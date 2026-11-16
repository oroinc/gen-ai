<?php

namespace Oro\Bundle\SmartBriefBundle\Provider;

use Oro\Bundle\SmartBriefBundle\Model\FactSet;

/**
 * Computes the facts of one entity class for SmartBrief.
 *
 * Implementations are tagged `oro_smart_brief.fact_provider` (with `priority`); the registry asks each one
 * {@see supportsClass()} in priority order and uses the first that accepts the class.
 */
interface SummaryFactProviderInterface
{
    /**
     * True if this provider summarizes entities of this class. Decided from the class name alone, without
     * loading anything, so a controller can reject an unsupported class before touching the database.
     */
    public function supportsClass(string $entityClass): bool;

    /**
     * Stable lowercase key of the record type, e.g. 'customer' or 'rfq'. It selects the prompt translation
     * (`oro_smart_brief.prompt.{type}`) and the fact label keys.
     */
    public function getEntityType(): string;

    /**
     * Full system-configuration key of this provider's on/off switch, e.g. 'oro_smart_brief.customer_enabled'.
     */
    public function getEnabledConfigKey(): string;

    /**
     * Deterministic facts about the entity, limited to the records the current user may view.
     */
    public function getFacts(object $entity): FactSet;
}
