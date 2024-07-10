<?php

namespace Oro\Bundle\AiContentGenerationBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\IntegrationBundle\Entity\Transport;
use Oro\Bundle\LocaleBundle\Entity\LocalizedFallbackValue;
use Oro\Bundle\SecurityBundle\DoctrineExtension\Dbal\Types\CryptedStringType;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * OpenAI settings entity. Stores basic configuration options for Open AI Integration
 */
#[ORM\Entity]
class OpenAiTransportSettings extends Transport
{
    public const string LABELS = 'labels';
    public const string TOKEN = 'token';
    public const string MODEL = 'model';

    public const string DEFAULT_MODEL = 'gpt-3.5-turbo';

    /**
     * @var Collection<int, LocalizedFallbackValue>
     */
    #[ORM\ManyToMany(targetEntity: LocalizedFallbackValue::class, cascade: ['ALL'], orphanRemoval: true)]
    #[ORM\JoinTable(name: 'oro_open_ai_transp_label')]
    #[ORM\JoinColumn(name: 'transport_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'localized_value_id', referencedColumnName: 'id', unique: true, onDelete: 'CASCADE')]
    protected ?Collection $labels = null;

    #[ORM\Column(name: 'open_ai_token', type: CryptedStringType::TYPE, length: 255, nullable: true)]
    protected ?string $token = null;

    #[ORM\Column(name: 'open_ai_model', type: Types::STRING, length: 255, nullable: true)]
    protected string $model = self::DEFAULT_MODEL;

    private ?ParameterBag $settings = null;

    public function __construct()
    {
        $this->labels = new ArrayCollection();
    }

    public function getLabels(): Collection
    {
        return $this->labels;
    }

    public function addLabel(LocalizedFallbackValue $label): self
    {
        if (!$this->labels->contains($label)) {
            $this->labels->add($label);
        }

        return $this;
    }

    public function removeLabel(LocalizedFallbackValue $label): self
    {
        if ($this->labels->contains($label)) {
            $this->labels->removeElement($label);
        }

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(?string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getSettingsBag(): ParameterBag
    {
        if (null === $this->settings) {
            $this->settings = new ParameterBag([
                static::LABELS => $this->getLabels(),
                static::TOKEN => $this->getToken(),
                static::MODEL => $this->getModel(),
            ]);
        }

        return $this->settings;
    }
}
