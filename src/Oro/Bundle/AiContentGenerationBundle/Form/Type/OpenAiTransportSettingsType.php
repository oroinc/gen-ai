<?php

namespace Oro\Bundle\AiContentGenerationBundle\Form\Type;

use Oro\Bundle\AiContentGenerationBundle\Entity\OpenAiTransportSettings;
use Oro\Bundle\FormBundle\Form\Type\OroPlaceholderPasswordType;
use Oro\Bundle\LocaleBundle\Form\Type\LocalizedFallbackValueCollectionType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form provides field to set basic Open AI Integration settings.
 */
class OpenAiTransportSettingsType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('labels', LocalizedFallbackValueCollectionType::class, [
                'label'    => 'oro_ai_content_generation.integration.open_ai.settings.labels.label',
                'tooltip'  => 'oro_ai_content_generation.integration.open_ai.settings.labels.tooltip',
                'required' => true,
                'entry_options'  => [
                    'constraints' => [new NotBlank()],
                ]
            ])
            ->add('token', OroPlaceholderPasswordType::class, [
                'label' => 'oro_ai_content_generation.integration.open_ai.settings.token.label',
                'tooltip'  => 'oro_ai_content_generation.integration.open_ai.settings.token.tooltip',
                'required' => true,
                'constraints' => [new NotBlank()]
            ])
            ->add('model', TextType::class, [
                'label' => 'oro_ai_content_generation.integration.open_ai.settings.model.label',
                'tooltip'  => 'oro_ai_content_generation.integration.open_ai.settings.model.tooltip',
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
            'data_class' => OpenAiTransportSettings::class
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix(): string
    {
        return 'oro_open_ai_settings';
    }
}
