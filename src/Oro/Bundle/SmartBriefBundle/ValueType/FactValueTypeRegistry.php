<?php

namespace Oro\Bundle\SmartBriefBundle\ValueType;

/**
 * Finds a fact value type by name among the services tagged `oro_smart_brief.fact_value_type`.
 *
 * Two services with the same name are a configuration error; a built-in type is replaced by decorating
 * its service (`oro_smart_brief.fact_value_type.<name>`), never by registering a second one.
 */
class FactValueTypeRegistry
{
    /** @var array<string, FactValueTypeInterface>|null */
    private ?array $valueTypesByName = null;

    /**
     * @param iterable<FactValueTypeInterface> $valueTypes
     */
    public function __construct(
        private readonly iterable $valueTypes
    ) {
    }

    public function has(string $name): bool
    {
        return isset($this->getValueTypesByName()[$name]);
    }

    /**
     * @throws \InvalidArgumentException when no value type has this name (a programming error)
     */
    public function get(string $name): FactValueTypeInterface
    {
        return $this->getValueTypesByName()[$name]
            ?? throw new \InvalidArgumentException(sprintf('Unknown fact value type "%s".', $name));
    }

    /**
     * @return array<string, FactValueTypeInterface>
     */
    private function getValueTypesByName(): array
    {
        if (null === $this->valueTypesByName) {
            $this->valueTypesByName = [];
            foreach ($this->valueTypes as $valueType) {
                $name = $valueType->getName();
                if (isset($this->valueTypesByName[$name])) {
                    throw new \LogicException(sprintf(
                        'Two fact value types are registered with the name "%s": %s and %s.',
                        $name,
                        $this->valueTypesByName[$name]::class,
                        $valueType::class
                    ));
                }
                $this->valueTypesByName[$name] = $valueType;
            }
        }

        return $this->valueTypesByName;
    }
}
