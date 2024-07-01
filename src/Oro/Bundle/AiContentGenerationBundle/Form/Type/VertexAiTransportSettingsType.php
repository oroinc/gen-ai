<?php

namespace Oro\Bundle\AiContentGenerationBundle\Form\Type;

use Oro\Bundle\AiContentGenerationBundle\Entity\VertexAiTransportSettings;
use Oro\Bundle\AttachmentBundle\Form\Type\ContentFileType;
use Oro\Bundle\LocaleBundle\Form\Type\LocalizedFallbackValueCollectionType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form provides field to set basic Vertex AI Integration settings.
 */
class VertexAiTransportSettingsType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('labels', LocalizedFallbackValueCollectionType::class, [
                'label'    => 'oro_ai_content_generation.integration.vertex_ai.settings.labels.label',
                'tooltip'  => 'oro_ai_content_generation.integration.vertex_ai.settings.labels.tooltip',
                'required' => true,
                'entry_options'  => [
                    'constraints' => [new NotBlank()],
                ]
            ])
            ->add('configFile', ContentFileType::class, [
                'label' => 'oro_ai_content_generation.integration.vertex_ai.settings.config_file.label',
                'tooltip'  => 'oro_ai_content_generation.integration.vertex_ai.settings.config_file.tooltip',
                'required' => true,
                'fileName' => 'config.json',
                'constraints' => [new NotBlank([
                    'message' => 'oro_ai_content_generation.integration.vertex_ai.config_file.blank'
                ])],
                'fileConstraints' => [
                    new File(['mimeTypes' => ['application/json']]),
                ]
            ])
            ->add('apiEndpoint', TextType::class, [
                'label' => 'oro_ai_content_generation.integration.vertex_ai.settings.api_endpoint.label',
                'tooltip'  => 'oro_ai_content_generation.integration.vertex_ai.settings.api_endpoint.tooltip',
                'required' => true,
                'constraints' => [new NotBlank()]
            ])
            ->add('projectId', TextType::class, [
                'label' => 'oro_ai_content_generation.integration.vertex_ai.settings.project_id.label',
                'tooltip'  => 'oro_ai_content_generation.integration.vertex_ai.settings.project_id.tooltip',
                'required' => true,
                'constraints' => [new NotBlank()]
            ])
            ->add('location', TextType::class, [
                'label' => 'oro_ai_content_generation.integration.vertex_ai.settings.location.label',
                'tooltip'  => 'oro_ai_content_generation.integration.vertex_ai.settings.location.tooltip',
                'required' => true,
                'constraints' => [new NotBlank()]
            ])
            ->add('model', TextType::class, [
                'label' => 'oro_ai_content_generation.integration.vertex_ai.settings.model.label',
                'tooltip'  => 'oro_ai_content_generation.integration.vertex_ai.settings.model.tooltip',
                'required' => true,
                'constraints' => [new NotBlank()]
            ]);
    }

    /**
     * {@inheritDoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VertexAiTransportSettings::class
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix(): string
    {
        return 'oro_vertex_ai_settings';
    }
}
