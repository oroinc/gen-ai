<?php

namespace Oro\Bundle\SmartBriefBundle\Tests\Unit\Stub;

use Oro\Bundle\IntegrationBundle\Entity\Transport;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * A transport whose settings bag holds the given values.
 */
final class TransportStub extends Transport
{
    private ?ParameterBag $settingsBag = null;

    public function __construct(
        private readonly array $settings
    ) {
    }

    #[\Override]
    public function getSettingsBag(): ParameterBag
    {
        return $this->settingsBag ??= new ParameterBag($this->settings);
    }
}
