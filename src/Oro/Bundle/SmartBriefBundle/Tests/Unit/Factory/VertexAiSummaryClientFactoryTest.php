<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Factory;

use Google\Client as GoogleClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Client\VertexAiSummaryClient;
use Oro\Bundle\SmartBriefBundle\Factory\VertexAiSummaryClientFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Covers the Vertex AI factory:
 * settings validation, token exchange with the timeout, token caching by JSON hash, failure handling.
 */
final class VertexAiSummaryClientFactoryTest extends TestCase
{
    private const string JSON_KEY = '{"type":"service_account","client_email":"a@p1.iam.gserviceaccount.com",'
        . '"private_key":"-----BEGIN PRIVATE KEY-----secret"}';
    private const string OTHER_JSON_KEY = '{"type":"service_account","client_email":"b@p1.iam.gserviceaccount.com",'
        . '"private_key":"other-secret"}';
    private const string ENDPOINT = 'us-central1-aiplatform.googleapis.com';
    private const string TOKEN = 'ya29.access-token';
    private const string EXPECTED_BASE_URI = 'https://us-central1-aiplatform.googleapis.com/v1/projects/p1'
        . '/locations/us-central1/publishers/google/models/gemini-2.5-flash';

    private GoogleClient&MockObject $googleClient;
    private MockClock $clock;
    private ArrayAdapter $cache;
    private VertexAiSummaryClientFactory $factory;

    #[\Override]
    protected function setUp(): void
    {
        $this->googleClient = $this->createMock(GoogleClient::class);
        $this->clock = new MockClock();
        $this->cache = new ArrayAdapter(0, true, 0, 0, $this->clock);
        $this->factory = new VertexAiSummaryClientFactory($this->googleClient, $this->cache, 10, 128);
    }

    public function testSupports(): void
    {
        self::assertTrue($this->factory->supports('vertex_ai'));
        self::assertFalse($this->factory->supports('open_ai'));
    }

    public function testConstructorRejectsNonPositiveTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VertexAiSummaryClientFactory($this->googleClient, $this->cache, 0, 0);
    }

    public function testBuildExchangesTokenWithScopesConfigAndTimeout(): void
    {
        $this->googleClient->expects(self::once())
            ->method('setScopes')
            ->with(['https://www.googleapis.com/auth/cloud-platform']);
        $this->googleClient->expects(self::once())
            ->method('setAuthConfig')
            ->with(json_decode(self::JSON_KEY, true));
        $this->googleClient->expects(self::once())
            ->method('fetchAccessTokenWithAssertion')
            ->with(self::callback(static function (GuzzleClient $authHttp) {
                return 10 === $authHttp->getConfig(RequestOptions::TIMEOUT);
            }))
            ->willReturn(['access_token' => self::TOKEN, 'expires_in' => 3600]);

        $client = $this->factory->build($this->createSettings());

        self::assertInstanceOf(VertexAiSummaryClient::class, $client);
        self::assertSame('vertex_ai', $client->getProviderType());
        $this->assertClientState($client, self::EXPECTED_BASE_URI);
    }

    public function testLocationDefaultsWhenMissing(): void
    {
        $this->expectTokenExchange(1);

        $client = $this->factory->build($this->createSettings(['location' => '']));

        $this->assertClientState($client, self::EXPECTED_BASE_URI);
    }

    public function testTokenIsReusedUntilSixtySecondsBeforeExpiry(): void
    {
        // the cache item expiry is stamped from real time, so the mock clock starts at the real "now"
        $this->expectTokenExchange(2);

        $this->factory->build($this->createSettings());
        $this->clock->sleep(600);
        $this->factory->build($this->createSettings());
        $this->clock->sleep(3600 - 60 - 600 - 2);
        $this->factory->build($this->createSettings());
        $this->clock->sleep(4);
        $this->factory->build($this->createSettings());
    }

    /**
     * @dataProvider shortLivedTokenDataProvider
     */
    public function testTokenExpiringWithinTheMarginIsNotCached(int $expiresIn): void
    {
        $this->googleClient->expects(self::exactly(2))
            ->method('fetchAccessTokenWithAssertion')
            ->willReturn(['access_token' => self::TOKEN, 'expires_in' => $expiresIn]);

        $this->factory->build($this->createSettings());
        $this->factory->build($this->createSettings());
    }

    public static function shortLivedTokenDataProvider(): array
    {
        return [[60], [30], [0]];
    }

    public function testDifferentServiceAccountGetsItsOwnToken(): void
    {
        $this->expectTokenExchange(2);

        $this->factory->build($this->createSettings());
        $this->factory->build($this->createSettings(['config_file' => self::OTHER_JSON_KEY]));
    }

    public function testTokenWithoutExpiryIsNotCached(): void
    {
        $this->googleClient->expects(self::exactly(2))
            ->method('fetchAccessTokenWithAssertion')
            ->willReturn(['access_token' => self::TOKEN]);

        $this->factory->build($this->createSettings());
        $this->factory->build($this->createSettings());
    }

    /**
     * @dataProvider exchangeFailureDataProvider
     */
    public function testExchangeFailureThrowsAndCachesNothing(array|\Throwable $result, string $reason): void
    {
        $this->googleClient->expects(self::once())
            ->method('fetchAccessTokenWithAssertion')
            ->will($result instanceof \Throwable ? self::throwException($result) : self::returnValue($result));

        try {
            $this->factory->build($this->createSettings());
            self::fail('Expected SummaryClientException.');
        } catch (SummaryClientException $exception) {
            self::assertSame($reason, $exception->getReason());
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertFalse($this->cache->hasItem('oro_smart_brief_vertex_token_' . hash('sha256', self::JSON_KEY)));
    }

    public static function exchangeFailureDataProvider(): array
    {
        return [
            'exception' => [new \DomainException('Error reading secret key'), 'client_error'],
            'error exception' => [new \ErrorException('boom'), 'client_error'],
            'timeout' => [
                new ConnectException('timed out', new Request('POST', 'https://oauth2.googleapis.com/token')),
                'timeout',
            ],
            'no token' => [['expires_in' => 3600], 'client_error'],
            'empty token' => [['access_token' => '', 'expires_in' => 3600], 'client_error'],
        ];
    }

    /**
     * @dataProvider invalidConfigDataProvider
     */
    public function testInvalidConfigThrowsBeforeAnyExchange(string $configFile): void
    {
        $this->googleClient->expects(self::never())->method('setAuthConfig');
        $this->googleClient->expects(self::never())->method('fetchAccessTokenWithAssertion');

        $exception = null;
        try {
            $this->factory->build($this->createSettings(['config_file' => $configFile]));
        } catch (SummaryClientException $exception) {
        }

        self::assertNotNull($exception);
        self::assertStringNotContainsString('secret', $exception->getMessage());
        self::assertStringNotContainsString('/etc/', $exception->getMessage());
    }

    public static function invalidConfigDataProvider(): array
    {
        return [
            'not json' => ['secret{not json'],
            'json string literal that Google\Client would read as a file path' => ['"/etc/hostname"'],
            'json object of another type' => ['{"type":"authorized_user","secret":"x"}'],
            'json array' => ['["secret"]'],
            'oauth client json without type' => ['{"client_id":"x","client_secret":"secret"}'],
            'service account without private key' => ['{"type":"service_account","client_email":"a@b"}'],
        ];
    }

    public function testSettingsAreUrlEncodedInTheBaseUri(): void
    {
        $this->expectTokenExchange(1);

        $client = $this->factory->build($this->createSettings([
            'api_endpoint' => 'evil.example.test/../',
            'project_id' => 'p1?x=1',
            'location' => 'us central1',
            'model' => 'gemini/../other',
        ]));

        $baseUri = (new \ReflectionProperty($client, 'baseUri'))->getValue($client);
        self::assertSame(
            'https://evil.example.test%2F..%2F/v1/projects/p1%3Fx%3D1/locations/us%20central1'
            . '/publishers/google/models/gemini%2F..%2Fother',
            $baseUri
        );
    }

    /**
     * @dataProvider missingSettingDataProvider
     */
    public function testMissingSettingThrowsNamingOnlyTheKey(string $key): void
    {
        $this->googleClient->expects(self::never())->method('fetchAccessTokenWithAssertion');

        try {
            $this->factory->build($this->createSettings([$key => '']));
            self::fail('Expected SummaryClientException.');
        } catch (SummaryClientException $exception) {
            self::assertStringContainsString(sprintf('"%s"', $key), $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public static function missingSettingDataProvider(): array
    {
        return [['config_file'], ['api_endpoint'], ['project_id'], ['model']];
    }

    private function expectTokenExchange(int $times): void
    {
        $this->googleClient->expects(self::exactly($times))
            ->method('fetchAccessTokenWithAssertion')
            ->willReturn(['access_token' => self::TOKEN, 'expires_in' => 3600]);
    }

    private function createSettings(array $overrides = []): ParameterBag
    {
        return new ParameterBag(array_replace([
            'config_file' => self::JSON_KEY,
            'api_endpoint' => 'us-central1-aiplatform.googleapis.com',
            'project_id' => 'p1',
            'location' => 'us-central1',
            'model' => 'gemini-2.5-flash',
        ], $overrides));
    }

    /**
     * The client exposes nothing, so its wiring is read from its private state on purpose.
     */
    private function assertClientState(VertexAiSummaryClient $client, string $expectedBaseUri): void
    {
        $read = static fn (string $property) => (new \ReflectionProperty($client, $property))->getValue($client);

        self::assertSame($expectedBaseUri, $read('baseUri'));
        self::assertSame(self::TOKEN, $read('accessToken'));
        self::assertSame('gemini-2.5-flash', $read('model'));
        self::assertSame(128, $read('thinkingBudget'));
        self::assertSame(10, $read('httpClient')->getConfig(RequestOptions::TIMEOUT));
    }
}
