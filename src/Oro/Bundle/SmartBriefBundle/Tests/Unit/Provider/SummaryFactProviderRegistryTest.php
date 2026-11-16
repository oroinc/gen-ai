<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Provider;

use Oro\Bundle\SmartBriefBundle\Provider\SummaryFactProviderInterface;
use Oro\Bundle\SmartBriefBundle\Provider\SummaryFactProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The first provider in priority order that supports the class wins; otherwise null.
 */
final class SummaryFactProviderRegistryTest extends TestCase
{
    public function testFirstSupportingProviderWins(): void
    {
        $projectProvider = $this->createProvider(['App\Entity\Customer']);
        $builtInProvider = $this->createProvider(['App\Entity\Customer', 'App\Entity\Request']);

        // the tagged iterator hands providers over highest priority first
        $registry = new SummaryFactProviderRegistry([$projectProvider, $builtInProvider]);

        self::assertSame($projectProvider, $registry->getProvider('App\Entity\Customer'));
        self::assertSame($builtInProvider, $registry->getProvider('App\Entity\Request'));
        self::assertNull($registry->getProvider('App\Entity\Order'));
    }

    public function testNoProviders(): void
    {
        self::assertNull((new SummaryFactProviderRegistry([]))->getProvider('App\Entity\Customer'));
    }

    private function createProvider(array $supportedClasses): SummaryFactProviderInterface
    {
        $provider = $this->createMock(SummaryFactProviderInterface::class);
        $provider->expects(self::any())
            ->method('supportsClass')
            ->willReturnCallback(static fn (string $class) => \in_array($class, $supportedClasses, true));

        return $provider;
    }
}
