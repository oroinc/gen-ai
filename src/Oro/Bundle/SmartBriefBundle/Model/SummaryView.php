<?php

namespace Oro\Bundle\SmartBriefBundle\Model;

/**
 * What the block renders: the AI paragraph (null in fallback), the facts it was built from, and when it was made.
 */
final readonly class SummaryView
{
    public function __construct(
        public ?string $text,
        public FactSet $facts,
        public bool $fallback,
        public \DateTimeImmutable $generatedAt,
    ) {
    }
}
