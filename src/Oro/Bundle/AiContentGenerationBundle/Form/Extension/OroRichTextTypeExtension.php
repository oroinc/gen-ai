<?php

namespace Oro\Bundle\AiContentGenerationBundle\Form\Extension;

use Oro\Bundle\AiContentGenerationBundle\Provider\TasksProvider;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureCheckerHolderTrait;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureToggleableInterface;
use Oro\Bundle\FormBundle\Form\Type\OroRichTextType;
use Symfony\Component\Asset\Context\ContextInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Adds AI Content Generation Assistant button to rich text editor
 */
class OroRichTextTypeExtension extends AbstractTypeExtension implements FeatureToggleableInterface
{
    use FeatureCheckerHolderTrait;

    public function __construct(
        private readonly ContextInterface $context,
        private readonly TasksProvider $tasksProvider
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [OroRichTextType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        if (!$this->isFeaturesEnabled()) {
            return;
        }

        $assetsBaseUrl = ltrim($this->context->getBasePath() . '/', '/');

        $resolver
            ->setDefault('page-component', [
                'module'  => 'oroui/js/app/components/view-component',
                'options' => [
                    'view' => 'oroaicontentgeneration/js/app/tinymce/wysiwyg-editor/wysiwyg-editor-view',
                    'content_css' => $assetsBaseUrl . 'build/admin/tinymce/wysiwyg-editor.css',
                    'openPromptTasks' => $this->tasksProvider->getOpenPromptTaskKeys()
                ]
            ]);
    }
}
