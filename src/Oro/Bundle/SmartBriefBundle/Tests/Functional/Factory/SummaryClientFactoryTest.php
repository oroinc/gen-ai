<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional\Factory;

use Oro\Bundle\ConfigBundle\Tests\Functional\Traits\ConfigManagerAwareTestTrait;
use Oro\Bundle\OrganizationBundle\Entity\Organization;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Client\SummaryRequest;
use Oro\Bundle\SmartBriefBundle\Client\SummaryResponse;
use Oro\Bundle\SmartBriefBundle\Factory\SummaryClientFactory;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\DataFixtures\LoadOpenAiChannelData;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\Stub\OpenAiSummaryClientFactoryStub;
use Oro\Bundle\SmartBriefBundle\Tests\Functional\Stub\SummaryClientStub;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;

/**
 * The dispatcher resolves the fixture channel to the stub client,
 * which behaves as the test configured it, and refuses an empty or disabled selection or a channel of another
 * organization.
 */
final class SummaryClientFactoryTest extends WebTestCase
{
    use ConfigManagerAwareTestTrait;

    #[\Override]
    protected function setUp(): void
    {
        $this->initClient();
        $this->loadFixtures([LoadOpenAiChannelData::class]);
        // the channel must belong to the user's organization
        $this->updateUserSecurityToken(self::AUTH_USER);
        OpenAiSummaryClientFactoryStub::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->restoreConfigValues();
        OpenAiSummaryClientFactoryStub::reset();
        parent::tearDown();
    }

    public function testSelectedChannelResolvesToTheStubClient(): void
    {
        $this->selectChannel(LoadOpenAiChannelData::CHANNEL);
        OpenAiSummaryClientFactoryStub::respondWith(new SummaryResponse('Configured summary.', 'stub-model', 10, 5));

        $client = $this->getFactory()->getClient();

        self::assertInstanceOf(SummaryClientStub::class, $client);
        self::assertSame($client, OpenAiSummaryClientFactoryStub::getLastClient());
        self::assertSame('open_ai', $client->getProviderType());
        self::assertSame('gpt-5-mini', OpenAiSummaryClientFactoryStub::getLastModel());
        $response = $client->generateSummary(new SummaryRequest('system', 'facts', 'en', 0.2, 1024));
        self::assertSame('Configured summary.', $response->text);
        self::assertSame('stub-model', $response->model);
    }

    public function testStubFailsAsConfigured(): void
    {
        $this->selectChannel(LoadOpenAiChannelData::CHANNEL);
        OpenAiSummaryClientFactoryStub::failWith(new SummaryClientException('down', 'timeout'));

        $client = $this->getFactory()->getClient();

        $this->expectException(SummaryClientException::class);
        $this->expectExceptionMessage('down');
        $client->generateSummary(new SummaryRequest('system', 'facts', 'en', 0.2, 1024));
    }

    public function testClearedSelectionIsRefused(): void
    {
        $this->selectChannel(LoadOpenAiChannelData::CHANNEL);
        self::assertInstanceOf(SummaryClientStub::class, $this->getFactory()->getClient());

        $this->setConfigValue('oro_smart_brief.integration', null);

        $this->expectException(SummaryClientException::class);
        $this->expectExceptionMessage('No AI integration is selected');
        $this->getFactory()->getClient();
    }

    public function testStubSleepsAsConfigured(): void
    {
        $this->selectChannel(LoadOpenAiChannelData::CHANNEL);
        OpenAiSummaryClientFactoryStub::sleepForMs(50);

        $client = $this->getFactory()->getClient();
        $started = microtime(true);
        $client->generateSummary(new SummaryRequest('system', 'facts', 'en', 0.2, 1024));

        self::assertGreaterThanOrEqual(0.05, microtime(true) - $started);
        self::assertCount(1, OpenAiSummaryClientFactoryStub::getLastClient()->getRequests());
    }

    public function testChannelOfAnotherOrganizationIsRefused(): void
    {
        $this->selectChannel(LoadOpenAiChannelData::CHANNEL);
        $em = self::getContainer()->get('doctrine')->getManagerForClass(Organization::class);
        $otherOrganization = (new Organization())->setName('Other organization')->setEnabled(true);
        $em->persist($otherOrganization);
        $this->getReference(LoadOpenAiChannelData::CHANNEL)->setOrganization($otherOrganization);
        $em->flush();

        $this->expectException(SummaryClientException::class);
        $this->expectExceptionMessage('does not exist in this organization');
        $this->getFactory()->getClient();
    }

    public function testDisabledChannelIsRefused(): void
    {
        $this->selectChannel(LoadOpenAiChannelData::DISABLED_CHANNEL);

        $this->expectException(SummaryClientException::class);
        $this->expectExceptionMessage('is disabled');
        $this->getFactory()->getClient();
    }

    private function selectChannel(string $reference): void
    {
        $this->setConfigValue('oro_smart_brief.integration', $this->getReference($reference)->getId());
    }

    private function getFactory(): SummaryClientFactory
    {
        return self::getContainer()->get('oro_smart_brief.tests.client.factory');
    }
}
