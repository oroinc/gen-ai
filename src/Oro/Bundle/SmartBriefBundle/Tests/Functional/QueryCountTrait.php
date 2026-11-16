<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Functional;

use Doctrine\DBAL\Logging\SQLLogger;

/**
 * Counts the SQL statements a callback executes (query-count tests). Unlike the shared QueryTracker, it does
 * not format the parameters, so array parameters (the live-quote ended-status list) are fine.
 */
trait QueryCountTrait
{
    private function countQueries(string $entityClass, callable $callback): int
    {
        $configuration = self::getContainer()->get('doctrine')->getManagerForClass($entityClass)
            ->getConnection()->getConfiguration();
        $counter = new class () implements SQLLogger {
            public int $count = 0;

            #[\Override]
            public function startQuery($sql, ?array $params = null, ?array $types = null): void
            {
                ++$this->count;
            }

            #[\Override]
            public function stopQuery(): void
            {
            }
        };
        $previous = $configuration->getSQLLogger();
        $configuration->setSQLLogger($counter);
        try {
            $callback();
        } finally {
            $configuration->setSQLLogger($previous);
        }

        return $counter->count;
    }
}
