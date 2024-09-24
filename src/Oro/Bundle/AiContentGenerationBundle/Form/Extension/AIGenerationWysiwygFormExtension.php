<?php

namespace Oro\Bundle\AiContentGenerationBundle\Form\Extension;

use Oro\Bundle\AiContentGenerationBundle\Provider\TasksProvider;
use Oro\Bundle\CMSBundle\Form\Type\WYSIWYGType;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureCheckerHolderTrait;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureToggleableInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * Adds AI Content Generation Assistant button to wysiwyg editor
 */
class AIGenerationWysiwygFormExtension extends AbstractTypeExtension implements FeatureToggleableInterface
{
    use FeatureCheckerHolderTrait;

    public function __construct(private readonly TasksProvider $tasksProvider)
    {
    }

    #[\Override]
    public static function getExtendedTypes(): iterable
    {
        return [WYSIWYGType::class];
    }

    #[\Override]
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        if (!$this->isFeaturesEnabled()) {
            return;
        }

        $pageComponentOptions = json_decode(
            $view->vars['attr']['data-page-component-options'] ?? '',
            true
        );

        $pageComponentOptions['builderPlugins']['aigeneration-plugin'] = [
            'openPromptTasks' => $this->tasksProvider->getOpenPromptTaskKeys(),
            'jsmodule' => 'oroaicontentgeneration/js/app/grapesjs/plugins/aigeneration-plugin'
        ];

        $view->vars['attr']['data-page-component-options'] = json_encode($pageComponentOptions);
    }
}
