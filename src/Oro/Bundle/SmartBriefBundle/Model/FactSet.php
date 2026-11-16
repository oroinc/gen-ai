<?php

namespace Oro\Bundle\SmartBriefBundle\Model;

/**
 * The ordered facts of one subject entity as seen by the current user.
 *
 * Facts keep their emission order. A key is emitted once: adding a second fact with the same key is a provider
 * bug and fails loudly instead of silently replacing the first.
 *
 * A fact can also carry the identifiers of the records behind it (for example the RFQ ids behind a count), at most
 * {@see RECORD_LIMIT}; the block links to them. They are not facts: never sent to the AI and not part of the
 * fingerprint.
 *
 * @implements \IteratorAggregate<int, Fact>
 */
final class FactSet implements \IteratorAggregate, \Countable
{
    public const int RECORD_LIMIT = 20;

    /** @var array<string, Fact> */
    private array $facts = [];

    /** @var array<string, list<int|string>> fact key => record identifiers */
    private array $records = [];

    /**
     * @throws \LogicException when a fact with this key was already added
     */
    public function add(Fact $fact): void
    {
        if (isset($this->facts[$fact->key])) {
            throw new \LogicException(sprintf('The fact "%s" was already added to this set.', $fact->key));
        }
        $this->facts[$fact->key] = $fact;
    }

    public function get(string $key): ?Fact
    {
        return $this->facts[$key] ?? null;
    }

    /**
     * @param list<int|string> $identifiers
     */
    public function setRecords(string $key, array $identifiers): void
    {
        if (\count($identifiers) > self::RECORD_LIMIT) {
            throw new \LogicException(
                sprintf('The fact "%s" can link to at most %d records.', $key, self::RECORD_LIMIT)
            );
        }
        $this->records[$key] = array_values($identifiers);
    }

    /**
     * @return list<int|string>|null null when the fact has no records to link to
     */
    public function getRecords(string $key): ?array
    {
        return $this->records[$key] ?? null;
    }

    /**
     * @return array<string, list<int|string>>
     */
    public function getAllRecords(): array
    {
        return $this->records;
    }

    public function isEmpty(): bool
    {
        return [] === $this->facts;
    }

    public function hasInformativeFacts(): bool
    {
        foreach ($this->facts as $fact) {
            if ($fact->informative) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \ArrayIterator<int, Fact>
     */
    #[\Override]
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator(array_values($this->facts));
    }

    #[\Override]
    public function count(): int
    {
        return \count($this->facts);
    }
}
