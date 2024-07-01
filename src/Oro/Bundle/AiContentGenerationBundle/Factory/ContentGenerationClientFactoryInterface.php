<?php

namespace Oro\Bundle\AiContentGenerationBundle\Factory;

use Oro\Bundle\AiContentGenerationBundle\Client\ContentGenerationClientInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Represents Factory for AI Client
 */
interface ContentGenerationClientFactoryInterface
{
    public function build(ParameterBag $parameterBag): ContentGenerationClientInterface;

    public function addAdditionalParam(string $key, int|float|string|null $value): void;

    public function supports(string $clientName): bool;
}
