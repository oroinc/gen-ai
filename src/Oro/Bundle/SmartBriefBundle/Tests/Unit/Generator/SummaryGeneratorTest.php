<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Generator;

use Oro\Bundle\EntityBundle\Exception\NotManageableEntityException;
use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\SmartBriefBundle\Cache\SummaryCache;
use Oro\Bundle\SmartBriefBundle\Cache\SummaryCacheKeyBuilder;
use Oro\Bundle\SmartBriefBundle\Client\SummaryClientException;
use Oro\Bundle\SmartBriefBundle\Generator\GenerationMetrics;
use Oro\Bundle\SmartBriefBundle\Generator\SummaryGenerator;
use Oro\Bundle\SmartBriefBundle\Generator\SummaryProviderResolver;
use Oro\Bundle\SmartBriefBundle\Generator\SummaryTextGenerator;
use Oro\Bundle\SmartBriefBundle\Model\Fact;
use Oro\Bundle\SmartBriefBundle\Model\FactSet;
use Oro\Bundle\SmartBriefBundle\Model\FactValueType;
use Oro\Bundle\SmartBriefBundle\Model\SummaryTextResult;
use Oro\Bundle\SmartBriefBundle\Model\SummaryView;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryFactProviderInterface;
use Oro\Bundle\SmartBriefBundle\Tests\Unit\Stub\CustomerStub;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the generator: the resolver's null, the no-activity view, cache hits and misses, the fallback view
 * (nothing cached), hidden on other errors and the log content. The AI leg itself is covered by
 * SummaryTextGeneratorTest. That a day change alone keeps the cache key holds structurally: neither the key builder nor
 * the serializer reads the current time, and the calendar date is not a fact (see SummaryCacheKeyBuilderTest).
 */
final class SummaryGeneratorTest extends TestCase
{
    private const string ENTITY_CLASS = CustomerStub::class;
    private const string KEY = 'oro_smart_brief_key';

    private SummaryProviderResolver&MockObject $providerResolver;
    private SummaryFactProviderInterface&MockObject $provider;
    private SummaryCacheKeyBuilder&MockObject $keyBuilder;
    private SummaryCache&MockObject $cache;
    private SummaryTextGenerator&MockObject $textGenerator;
    private LoggerInterface&MockObject $logger;
    private SummaryGenerator $generator;
    private CustomerStub $entity;
    private DoctrineHelper&MockObject $doctrineHelper;

    #[\Override]
    protected function setUp(): void
    {
        $this->entity = new CustomerStub();
        $this->providerResolver = $this->createMock(SummaryProviderResolver::class);
        $this->provider = $this->createMock(SummaryFactProviderInterface::class);
        $this->provider->expects(self::any())->method('getEntityType')->willReturn('customer');
        $this->doctrineHelper = $this->createMock(DoctrineHelper::class);
        $this->doctrineHelper->expects(self::any())
            ->method('getEntityClass')
            ->with(self::identicalTo($this->entity))
            ->willReturn(self::ENTITY_CLASS);
        $this->doctrineHelper->expects(self::any())
            ->method('getSingleEntityIdentifier')
            ->with(self::identicalTo($this->entity), false)
            ->willReturn(42);
        $this->keyBuilder = $this->createMock(SummaryCacheKeyBuilder::class);
        $this->cache = $this->createMock(SummaryCache::class);
        $this->textGenerator = $this->createMock(SummaryTextGenerator::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        // Exactly one metrics entry per attempt, whatever the path a test exercises.
        $this->logger->expects(self::once())
            ->method('info')
            ->with(GenerationMetrics::LOG_MESSAGE, self::isType('array'));

        $this->generator = new SummaryGenerator(
            $this->providerResolver,
            $this->doctrineHelper,
            $this->keyBuilder,
            $this->cache,
            $this->textGenerator,
            $this->logger
        );
    }

    public function testNotEligible(): void
    {
        $this->providerResolver->expects(self::once())
            ->method('resolve')
            ->with(self::identicalTo($this->entity))
            ->willReturn(null);
        $this->keyBuilder->expects(self::never())->method('isRefreshOnChangeEnabled');
        $this->expectNoFactsNoCacheNoClient();

        self::assertNull($this->generator->generate($this->entity));
    }

    /**
     * @dataProvider nonInformativeFactsDataProvider
     */
    public function testRefreshOnChangeWithoutInformativeFactsReturnsNoActivityView(FactSet $facts): void
    {
        $this->expectProvider();
        $this->expectMode(true);
        $this->provider->expects(self::once())->method('getFacts')->willReturn($facts);
        $this->keyBuilder->expects(self::never())->method('build');
        $this->cache->expects(self::never())->method('get');
        $this->textGenerator->expects(self::never())->method('generate');

        $view = $this->generator->generate($this->entity);

        self::assertNull($view->text);
        self::assertTrue($view->fallback);
        self::assertSame($facts, $view->facts);
    }

    public static function nonInformativeFactsDataProvider(): array
    {
        $identityOnly = new FactSet();
        $identityOnly->add(new Fact('company_name', FactValueType::TEXT, 'Acme', false));

        return ['empty' => [new FactSet()], 'identity facts only' => [$identityOnly]];
    }

    public function testRefreshOnChangeMissCallsTheAiAndStoresTheResult(): void
    {
        $this->expectProvider();
        $this->expectMode(true);
        $facts = $this->createFacts();
        $this->provider->expects(self::once())->method('getFacts')->willReturn($facts);
        $this->keyBuilder->expects(self::once())
            ->method('build')
            ->with('customer', self::ENTITY_CLASS, 42, self::identicalTo($facts))
            ->willReturn(self::KEY);
        $this->cache->expects(self::once())->method('get')->with(self::KEY)->willReturn(null);
        $this->textGenerator->expects(self::once())
            ->method('generate')
            ->with('customer', self::identicalTo($facts))
            ->willReturn(SummaryTextResult::success('Clean text.', 'open_ai', 'gpt-5-mini', 10, 5));
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('error');
        $this->cache->expects(self::once())
            ->method('save')
            ->with(self::KEY, self::callback(static function (SummaryView $cached) use ($facts) {
                return 'Clean text.' === $cached->text
                    && $cached->facts === $facts
                    && 'UTC' === $cached->generatedAt->getTimezone()->getName();
            }));

        $view = $this->generator->generate($this->entity);

        self::assertSame('Clean text.', $view->text);
        self::assertSame($facts, $view->facts);
        self::assertFalse($view->fallback);
        self::assertWrittenNow($view);
    }

    public function testRefreshOnChangeHitReturnsTheCachedSummary(): void
    {
        $this->expectProvider();
        $this->expectMode(true);
        $facts = $this->createFacts();
        $this->provider->expects(self::once())->method('getFacts')->willReturn($facts);
        $this->keyBuilder->expects(self::once())->method('build')->willReturn(self::KEY);
        $facts->setRecords('open_rfq_count', [10, 11]);
        $generatedAt = new \DateTimeImmutable('2026-10-07 09:00:00');
        $cachedFacts = $this->createFacts();
        $cachedFacts->setRecords('open_rfq_count', [4]);
        $this->cache->expects(self::once())
            ->method('get')
            ->with(self::KEY)
            ->willReturn(new SummaryView('Cached text.', $cachedFacts, false, $generatedAt));
        $this->textGenerator->expects(self::never())->method('generate');
        $this->cache->expects(self::never())->method('save');

        $view = $this->generator->generate($this->entity);

        self::assertEquals(new SummaryView('Cached text.', $facts, false, $generatedAt), $view);
        self::assertSame($facts, $view->facts, 'The facts just computed carry the current records.');
    }

    /**
     * @dataProvider refreshOnChangeDataProvider
     */
    public function testRegenerateSkipsTheCacheAndReplacesTheCachedSummary(bool $refreshOnChange): void
    {
        $this->expectProvider();
        $this->expectMode($refreshOnChange);
        $facts = $this->createFacts();
        $this->provider->expects(self::once())->method('getFacts')->willReturn($facts);
        $this->keyBuilder->expects(self::once())->method('build')->willReturn(self::KEY);
        $this->cache->expects(self::never())->method('get');
        $this->textGenerator->expects(self::once())
            ->method('generate')
            ->willReturn(SummaryTextResult::success('Fresh text.', 'open_ai', 'gpt-5-mini', 10, 5));
        $this->cache->expects(self::once())
            ->method('save')
            ->with(self::KEY, self::callback(static fn (SummaryView $cached) => 'Fresh text.' === $cached->text));

        $view = $this->generator->generate($this->entity, true);

        self::assertSame('Fresh text.', $view->text);
    }

    public static function refreshOnChangeDataProvider(): array
    {
        return ['refresh on change' => [true], 'refresh on change disabled' => [false]];
    }

    public function testRefreshOnChangeDisabledHitServesTheCachedFactsWithoutAFactQuery(): void
    {
        $this->expectProvider();
        $this->expectMode(false);
        $this->provider->expects(self::never())->method('getFacts');
        $this->keyBuilder->expects(self::once())
            ->method('build')
            ->with('customer', self::ENTITY_CLASS, 42, null)
            ->willReturn(self::KEY);
        $cachedFacts = $this->createFacts();
        $generatedAt = new \DateTimeImmutable('2026-10-07 09:00:00');
        $this->cache->expects(self::once())
            ->method('get')
            ->with(self::KEY)
            ->willReturn(new SummaryView('Cached text.', $cachedFacts, false, $generatedAt));
        $this->textGenerator->expects(self::never())->method('generate');

        $view = $this->generator->generate($this->entity);

        self::assertSame($cachedFacts, $view->facts);
        self::assertSame('Cached text.', $view->text);
    }

    public function testRefreshOnChangeDisabledMissComputesFactsAndStoresUnderTheUserKey(): void
    {
        $this->expectProvider();
        $this->expectMode(false);
        $facts = $this->createFacts();
        $this->keyBuilder->expects(self::once())->method('build')->willReturn(self::KEY);
        $this->cache->expects(self::once())->method('get')->with(self::KEY)->willReturn(null);
        $this->provider->expects(self::once())->method('getFacts')->willReturn($facts);
        $this->textGenerator->expects(self::once())
            ->method('generate')
            ->willReturn(SummaryTextResult::success('text', 'open_ai', 'm', null, null));
        $this->cache->expects(self::once())->method('save')->with(self::KEY, self::isInstanceOf(SummaryView::class));

        self::assertSame('text', $this->generator->generate($this->entity)->text);
    }

    public function testRefreshOnChangeDisabledMissWithoutInformativeFactsReturnsNoActivityView(): void
    {
        $this->expectProvider();
        $this->expectMode(false);
        $this->keyBuilder->expects(self::once())->method('build')->willReturn(self::KEY);
        $this->cache->expects(self::once())->method('get')->willReturn(null);
        $this->provider->expects(self::once())->method('getFacts')->willReturn(new FactSet());
        $this->textGenerator->expects(self::never())->method('generate');
        $this->cache->expects(self::never())->method('save');

        $view = $this->generator->generate($this->entity);

        self::assertNull($view->text);
        self::assertTrue($view->fallback);
    }

    /**
     * @dataProvider modeDataProvider
     */
    public function testAiFailureFallsBackToTheFactsLogsAWarningAndCachesNothing(bool $refreshOnChange): void
    {
        $this->expectProvider();
        $this->expectMode($refreshOnChange);
        $facts = $this->createFacts();
        $this->provider->expects(self::once())->method('getFacts')->willReturn($facts);
        $this->keyBuilder->expects(self::once())->method('build')->willReturn(self::KEY);
        $this->cache->expects(self::once())->method('get')->willReturn(null);
        $exception = new SummaryClientException(
            'prompt echoed here',
            SummaryClientException::REASON_RATE_LIMITED,
            429,
            'rate_limit_exceeded'
        );
        $this->textGenerator->expects(self::once())
            ->method('generate')
            ->with('customer', self::identicalTo($facts))
            ->willReturn(SummaryTextResult::fallback($exception, 'open_ai'));
        $this->cache->expects(self::never())->method('save');
        $this->logger->expects(self::never())->method('error');
        $this->logger->expects(self::once())
            ->method('warning')
            ->with('SmartBrief fell back to the facts.', self::baseContext() + [
                'channel_type' => 'open_ai',
                'reason' => 'rate_limited',
                'http_status' => 429,
                'provider_error_code' => 'rate_limit_exceeded',
            ]);

        $view = $this->generator->generate($this->entity);

        self::assertNull($view->text);
        self::assertSame($facts, $view->facts);
        self::assertTrue($view->fallback);
        self::assertWrittenNow($view);
    }

    public static function modeDataProvider(): array
    {
        return ['refresh on change' => [true], 'per user' => [false]];
    }

    public function testFailedCacheWriteIsLoggedAndTheSummaryIsStillShown(): void
    {
        $this->expectProvider();
        $this->expectMode(true);
        $facts = $this->createFacts();
        $this->provider->expects(self::once())->method('getFacts')->willReturn($facts);
        $this->keyBuilder->expects(self::once())->method('build')->willReturn(self::KEY);
        $this->cache->expects(self::once())->method('get')->willReturn(null);
        $this->textGenerator->expects(self::once())
            ->method('generate')
            ->willReturn(SummaryTextResult::success('Clean text.', 'open_ai', 'gpt-5-mini', 10, 5));
        $this->cache->expects(self::once())
            ->method('save')
            ->willThrowException(new \RuntimeException('redis://user:secret@host'));
        $this->logger->expects(self::once())
            ->method('error')
            ->with('SmartBrief could not be cached.', self::baseContext() + [
                'exception_class' => \RuntimeException::class,
            ]);

        $view = $this->generator->generate($this->entity);

        self::assertSame('Clean text.', $view->text);
        self::assertFalse($view->fallback);
    }

    public function testTextGeneratorErrorHidesTheBlock(): void
    {
        $this->expectProvider();
        $this->expectMode(true);
        $this->provider->expects(self::once())->method('getFacts')->willReturn($this->createFacts());
        $this->keyBuilder->expects(self::once())->method('build')->willReturn(self::KEY);
        $this->cache->expects(self::once())->method('get')->willReturn(null);
        $this->textGenerator->expects(self::once())
            ->method('generate')
            ->willThrowException(new \RuntimeException('bug'));
        $this->cache->expects(self::never())->method('save');
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::once())
            ->method('error')
            ->with('SmartBrief hidden after an error.', self::baseContext() + [
                'exception_class' => \RuntimeException::class,
            ]);

        self::assertNull($this->generator->generate($this->entity));
    }

    public function testResolverErrorHidesTheBlock(): void
    {
        $this->providerResolver->expects(self::once())
            ->method('resolve')
            ->willThrowException(new \RuntimeException('config'));
        $this->expectNoFactsNoCacheNoClient();
        $this->logger->expects(self::once())
            ->method('error')
            ->with('SmartBrief hidden after an error.', [
                'entity_class' => self::ENTITY_CLASS,
                'entity_id' => 42,
                'entity_type' => null,
                'exception_class' => \RuntimeException::class,
            ]);

        self::assertNull($this->generator->generate($this->entity));
    }

    public function testProviderErrorHidesTheBlockAndLogsClassesOnly(): void
    {
        $this->expectProvider();
        $this->expectMode(true);
        $this->provider->expects(self::once())
            ->method('getFacts')
            ->willThrowException(new \RuntimeException('SELECT secret FROM facts'));
        $this->cache->expects(self::never())->method('get');
        $this->textGenerator->expects(self::never())->method('generate');
        $this->logger->expects(self::once())
            ->method('error')
            ->with('SmartBrief hidden after an error.', self::baseContext() + [
                'exception_class' => \RuntimeException::class,
            ]);

        self::assertNull($this->generator->generate($this->entity));
    }

    public function testCacheErrorHidesTheBlock(): void
    {
        $this->expectProvider();
        $this->expectMode(false);
        $this->keyBuilder->expects(self::once())->method('build')->willReturn(self::KEY);
        $this->cache->expects(self::once())->method('get')->willThrowException(new \LogicException('no token'));
        $this->logger->expects(self::once())
            ->method('error')
            ->with('SmartBrief hidden after an error.', self::baseContext() + [
                'exception_class' => \LogicException::class,
            ]);

        self::assertNull($this->generator->generate($this->entity));
    }

    private static function baseContext(): array
    {
        return ['entity_class' => self::ENTITY_CLASS, 'entity_id' => 42, 'entity_type' => 'customer'];
    }

    /**
     * @dataProvider metricsDataProvider
     */
    public function testEveryAttemptWritesOneMetricsEntry(callable $arrange, array $expectedContext): void
    {
        $arrange($this);
        $this->logger->expects(self::once())
            ->method('info')
            ->with(
                GenerationMetrics::LOG_MESSAGE,
                self::callback(static function (array $context) use ($expectedContext): bool {
                    self::assertIsInt($context['latency_ms']);
                    self::assertGreaterThanOrEqual(0, $context['latency_ms']);
                    unset($context['latency_ms']);
                    self::assertSame(array_merge(self::baseMetrics(), $expectedContext), $context);

                    return true;
                })
            );

        $this->generator->generate($this->entity);
    }

    public static function metricsDataProvider(): array
    {
        return [
            'ok after a miss' => [
                static function (self $test): void {
                    $test->expectProvider();
                    $test->expectMode(true);
                    $test->provider->expects(self::once())->method('getFacts')->willReturn($test->createFacts());
                    $test->keyBuilder->expects(self::once())->method('build')->willReturn(self::KEY);
                    $test->cache->expects(self::once())->method('get')->willReturn(null);
                    $test->textGenerator->expects(self::once())
                        ->method('generate')
                        ->willReturn(SummaryTextResult::success('Text', 'open_ai', 'gpt-5-mini', 120, 40));
                },
                [
                    'channel_type' => 'open_ai',
                    'model' => 'gpt-5-mini',
                    'cache' => 'miss',
                    'refresh_on_change' => true,
                    'outcome' => 'ok',
                    'input_tokens' => 120,
                    'output_tokens' => 40,
                ],
            ],
            'cache hit' => [
                static function (self $test): void {
                    $test->expectProvider();
                    $test->expectMode(false);
                    $test->keyBuilder->expects(self::once())->method('build')->willReturn(self::KEY);
                    $test->cache->expects(self::once())
                        ->method('get')
                        ->willReturn(
                            new SummaryView('Cached.', $test->createFacts(), false, new \DateTimeImmutable())
                        );
                },
                ['cache' => 'hit', 'refresh_on_change' => false, 'outcome' => 'ok'],
            ],
            'fallback' => [
                static function (self $test): void {
                    $test->expectProvider();
                    $test->expectMode(true);
                    $test->provider->expects(self::once())->method('getFacts')->willReturn($test->createFacts());
                    $test->keyBuilder->expects(self::once())->method('build')->willReturn(self::KEY);
                    $test->cache->expects(self::once())->method('get')->willReturn(null);
                    $exception = new SummaryClientException(
                        'provider body echoing the prompt',
                        SummaryClientException::REASON_TIMEOUT
                    );
                    $test->textGenerator->expects(self::once())
                        ->method('generate')
                        ->willReturn(SummaryTextResult::fallback($exception, 'open_ai'));
                },
                [
                    'channel_type' => 'open_ai',
                    'cache' => 'miss',
                    'refresh_on_change' => true,
                    'outcome' => 'fallback',
                    'fallback_reason' => 'timeout',
                    'http_status' => null,
                ],
            ],
            'no activity' => [
                static function (self $test): void {
                    $test->expectProvider();
                    $test->expectMode(true);
                    $test->provider->expects(self::once())->method('getFacts')->willReturn(new FactSet());
                },
                ['refresh_on_change' => true, 'outcome' => 'no_activity'],
            ],
            'no activity (per user, after a miss)' => [
                static function (self $test): void {
                    $test->expectProvider();
                    $test->expectMode(false);
                    $test->keyBuilder->expects(self::once())->method('build')->willReturn(self::KEY);
                    $test->cache->expects(self::once())->method('get')->willReturn(null);
                    $test->provider->expects(self::once())->method('getFacts')->willReturn(new FactSet());
                },
                [
                    'cache' => 'miss',
                    'refresh_on_change' => false,
                    'outcome' => 'no_activity',
                ],
            ],
            'hidden: switch off (the type is still named)' => [
                static function (self $test): void {
                    $test->providerResolver->expects(self::once())->method('resolve')->willReturn(null);
                    $test->providerResolver->expects(self::once())
                        ->method('findProvider')
                        ->with(self::ENTITY_CLASS)
                        ->willReturn($test->provider);
                    $test->providerResolver->expects(self::once())
                        ->method('getIneligibilityReason')
                        ->with(self::ENTITY_CLASS)
                        ->willReturn(GenerationMetrics::HIDDEN_SWITCH_OFF);
                },
                ['outcome' => 'hidden', 'hidden_reason' => 'switch_off'],
            ],
            'hidden: feature off (direct caller)' => [
                static function (self $test): void {
                    $test->providerResolver->expects(self::once())->method('resolve')->willReturn(null);
                    $test->providerResolver->expects(self::once())->method('findProvider')->willReturn($test->provider);
                    $test->providerResolver->expects(self::once())
                        ->method('getIneligibilityReason')
                        ->willReturn(GenerationMetrics::HIDDEN_FEATURE_OFF);
                },
                ['outcome' => 'hidden', 'hidden_reason' => 'feature_off'],
            ],
            'hidden: no provider' => [
                static function (self $test): void {
                    $test->providerResolver->expects(self::once())->method('resolve')->willReturn(null);
                    $test->providerResolver->expects(self::once())->method('findProvider')->willReturn(null);
                    $test->providerResolver->expects(self::once())
                        ->method('getIneligibilityReason')
                        ->willReturn(GenerationMetrics::HIDDEN_NO_PROVIDER);
                },
                ['entity_type' => null, 'outcome' => 'hidden', 'hidden_reason' => 'no_provider'],
            ],
            'hidden: error' => [
                static function (self $test): void {
                    $test->expectProvider();
                    $test->expectMode(true);
                    $test->provider->expects(self::once())
                        ->method('getFacts')
                        ->willThrowException(new \RuntimeException('SELECT secret'));
                },
                ['refresh_on_change' => true, 'outcome' => 'hidden', 'hidden_reason' => 'error'],
            ],
        ];
    }

    public function testLatencyIsMeasured(): void
    {
        $this->expectProvider();
        $this->expectMode(true);
        $this->provider->expects(self::once())
            ->method('getFacts')
            ->willReturnCallback(function (): FactSet {
                usleep(50_000);

                return new FactSet();
            });
        $this->logger->expects(self::once())
            ->method('info')
            ->with(GenerationMetrics::LOG_MESSAGE, self::callback(static fn (array $c) => $c['latency_ms'] >= 50));

        $this->generator->generate($this->entity);
    }

    public function testFailingMetricsHandlerDoesNotAffectTheSummary(): void
    {
        $this->expectProvider();
        $this->expectMode(true);
        $facts = $this->createFacts();
        $this->provider->expects(self::once())->method('getFacts')->willReturn($facts);
        $this->keyBuilder->expects(self::once())->method('build')->willReturn(self::KEY);
        $this->cache->expects(self::once())->method('get')->willReturn(null);
        $this->textGenerator->expects(self::once())
            ->method('generate')
            ->willReturn(SummaryTextResult::success('Clean text.', 'open_ai', 'gpt-5-mini', 10, 5));
        $this->logger->expects(self::once())
            ->method('info')
            ->willThrowException(new \RuntimeException('handler down'));

        self::assertSame('Clean text.', $this->generator->generate($this->entity)->text);
    }

    private static function baseMetrics(): array
    {
        return [
            'entity_type' => 'customer',
            'channel_type' => null,
            'model' => null,
            'cache' => 'none',
            'refresh_on_change' => null,
            'outcome' => 'hidden',
        ];
    }

    private function expectProvider(): void
    {
        $this->providerResolver->expects(self::once())
            ->method('resolve')
            ->with(self::identicalTo($this->entity))
            ->willReturn($this->provider);
    }

    private function expectMode(bool $refreshOnChange): void
    {
        $this->keyBuilder->expects(self::once())->method('isRefreshOnChangeEnabled')->willReturn($refreshOnChange);
    }

    private function expectNoFactsNoCacheNoClient(): void
    {
        $this->provider->expects(self::never())->method('getFacts');
        $this->cache->expects(self::never())->method('get');
        $this->cache->expects(self::never())->method('save');
        $this->textGenerator->expects(self::never())->method('generate');
    }

    private function createFacts(): FactSet
    {
        $facts = new FactSet();
        $facts->add(new Fact('company_name', FactValueType::TEXT, 'Acme', false));
        $facts->add(new Fact('open_rfq_count', FactValueType::INTEGER, 2));

        return $facts;
    }

    /**
     * @dataProvider unusableIdentifierDataProvider
     */
    public function testRecordWithoutUsableIdentifierIsHidden(
        mixed $identifier,
        ?\Throwable $lookupError,
        string $exceptionClass
    ): void {
        $doctrineHelper = $this->createMock(DoctrineHelper::class);
        $doctrineHelper->expects(self::any())->method('getEntityClass')->willReturn(self::ENTITY_CLASS);
        $lookup = $doctrineHelper->expects(self::once())
            ->method('getSingleEntityIdentifier')
            ->with(self::anything(), false);
        if (null === $lookupError) {
            $this->providerResolver->expects(self::once())->method('resolve')->willReturn($this->provider);
            $lookup->willReturn($identifier);
        } else {
            $this->providerResolver->expects(self::never())->method('resolve');
            $lookup->willThrowException($lookupError);
        }
        $this->keyBuilder->expects(self::never())->method('build');
        $this->cache->expects(self::never())->method('get');
        $this->logger->expects(self::once())
            ->method('error')
            ->with('SmartBrief hidden after an error.', [
                'entity_class' => self::ENTITY_CLASS,
                'entity_id' => null === $lookupError ? $identifier : null,
                'entity_type' => null === $lookupError ? 'customer' : null,
                'exception_class' => $exceptionClass,
            ]);
        $generator = new SummaryGenerator(
            $this->providerResolver,
            $doctrineHelper,
            $this->keyBuilder,
            $this->cache,
            $this->textGenerator,
            $this->logger
        );

        self::assertNull($generator->generate($this->entity));
    }

    public static function unusableIdentifierDataProvider(): array
    {
        return [
            'null (not persisted, or composite)' => [null, null, \LogicException::class],
            'empty string' => ['', null, \LogicException::class],
            'not manageable' => [
                null,
                new NotManageableEntityException('Acme\Thing'),
                NotManageableEntityException::class,
            ],
        ];
    }

    private static function assertWrittenNow(SummaryView $view): void
    {
        self::assertSame('UTC', $view->generatedAt->getTimezone()->getName());
        self::assertLessThanOrEqual(5, abs(time() - $view->generatedAt->getTimestamp()));
    }
}
