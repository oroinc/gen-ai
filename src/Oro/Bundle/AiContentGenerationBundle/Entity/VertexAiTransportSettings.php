<?php

namespace Oro\Bundle\AiContentGenerationBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Oro\Bundle\IntegrationBundle\Entity\Transport;
use Oro\Bundle\LocaleBundle\Entity\LocalizedFallbackValue;
use Oro\Bundle\SecurityBundle\DoctrineExtension\Dbal\Types\CryptedTextType;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Vertex AI settings entity. Stores basic configuration options for Vertex AI Integration
 */
#[ORM\Entity]
class VertexAiTransportSettings extends Transport
{
    public const string LABELS = 'labels';
    public const string CONFIG_FILE = 'config_file';
    public const string API_ENDPOINT = 'api_endpoint';
    public const string PROJECT_ID = 'project_id';
    public const string LOCATION = 'location';
    public const string MODEL = 'model';

    public const string DEFAULT_MODEL = 'text-bison@001';
    public const string DEFAULT_LOCATION = 'us-central1';

    /**
     * @var Collection<int, LocalizedFallbackValue>
     */
    #[ORM\ManyToMany(targetEntity: LocalizedFallbackValue::class, cascade: ['ALL'], orphanRemoval: true)]
    #[ORM\JoinTable(name: 'oro_vertex_ai_transp_label')]
    #[ORM\JoinColumn(name: 'transport_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'localized_value_id', referencedColumnName: 'id', unique: true, onDelete: 'CASCADE')]
    protected ?Collection $labels = null;

    #[ORM\Column(name: 'vertex_ai_config_file', type: CryptedTextType::TYPE, nullable: true)]
    protected ?string $configFile = null;

    #[ORM\Column(name: 'vertex_ai_api_endpoint', type: Types::STRING, length: 255, nullable: true)]
    protected ?string $apiEndpoint = null;

    #[ORM\Column(name: 'vertex_ai_project_id', type: Types::STRING, length: 255, nullable: true)]
    protected ?string $projectId = null;

    #[ORM\Column(name: 'vertex_ai_location', type: Types::STRING, length: 255, nullable: true)]
    protected string $location = self::DEFAULT_LOCATION;

    #[ORM\Column(name: 'vertex_ai_model', type: Types::STRING, length: 255, nullable: true)]
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

    public function getConfigFile(): ?string
    {
        return $this->configFile;
    }

    public function setConfigFile(?string $configFile): self
    {
        $this->configFile = $configFile;

        return $this;
    }

    public function getApiEndpoint(): ?string
    {
        return $this->apiEndpoint;
    }

    public function setApiEndpoint(?string $apiEndpoint): self
    {
        $this->apiEndpoint = $apiEndpoint;

        return $this;
    }

    public function getProjectId(): ?string
    {
        return $this->projectId;
    }

    public function setProjectId(?string $projectId): self
    {
        $this->projectId = $projectId;

        return $this;
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function setLocation(string $location): self
    {
        $this->location = $location;

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
                static::CONFIG_FILE => $this->getConfigFile(),
                static::API_ENDPOINT => $this->getApiEndpoint(),
                static::PROJECT_ID => $this->getProjectId(),
                static::LOCATION => $this->getLocation(),
                static::MODEL => $this->getModel(),
            ]);
        }

        return $this->settings;
    }
}
