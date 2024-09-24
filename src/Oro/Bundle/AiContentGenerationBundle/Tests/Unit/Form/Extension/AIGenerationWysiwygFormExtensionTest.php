<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Form\Extension;

use Oro\Bundle\AiContentGenerationBundle\Form\Extension\AIGenerationWysiwygFormExtension;
use Oro\Bundle\AiContentGenerationBundle\Provider\TasksProvider;
use Oro\Bundle\CMSBundle\Form\EventSubscriber\DigitalAssetTwigTagsEventSubscriber;
use Oro\Bundle\CMSBundle\Form\Type\WYSIWYGType;
use Oro\Bundle\CMSBundle\Provider\HTMLPurifierScopeProvider;
use Oro\Bundle\CMSBundle\Tools\DigitalAssetTwigTagsConverter;
use Oro\Bundle\EntityBundle\Provider\EntityProvider;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\FormBundle\Provider\HtmlTagProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Asset\Packages as AssetHelper;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\FormIntegrationTestCase;

final class AIGenerationWysiwygFormExtensionTest extends FormIntegrationTestCase
{
    private EntityProvider&MockObject $entityProvider;

    private FeatureChecker&MockObject $featureChecker;

    private DigitalAssetTwigTagsConverter&MockObject $digitalAssetTwigTagsConverter;

    private TasksProvider&MockObject $tasksProvider;

    #[\Override]
    protected function setUp(): void
    {
        $this->digitalAssetTwigTagsConverter = $this->createMock(DigitalAssetTwigTagsConverter::class);
        $this->featureChecker = $this->createMock(FeatureChecker::class);
        $this->tasksProvider = $this->createMock(TasksProvider::class);

        $this->eventSubscriber = new DigitalAssetTwigTagsEventSubscriber($this->digitalAssetTwigTagsConverter);
        $this->entityProvider = $this->createMock(EntityProvider::class);

        $this->formType = new WYSIWYGType(
            $this->createMock(HtmlTagProvider::class),
            $this->createMock(HTMLPurifierScopeProvider::class),
            $this->eventSubscriber,
            $this->createMock(AssetHelper::class),
            $this->entityProvider
        );

        parent::setUp();
    }

    /**
     * @dataProvider wysiwygDataProvider
     */
    public function testAIGenerationWysiwygFormExtension(bool $feature, ?array $expected): void
    {
        $this->featureChecker->expects(self::once())
            ->method('isFeatureEnabled')
            ->willReturn($feature);

        $this->digitalAssetTwigTagsConverter
            ->expects(self::any())
            ->method('convertToUrls')
            ->willReturnArgument(0);

        $this->digitalAssetTwigTagsConverter
            ->expects(self::any())
            ->method('convertToTwigTags')
            ->willReturnArgument(0);

        $this->entityProvider
            ->expects(self::once())
            ->method('getEntity')
            ->willReturn([
                'label' => 'label',
                'plural_label' => 'plural_label'
            ]);

        $this->tasksProvider
            ->expects(self::any())
            ->method('getOpenPromptTaskKeys')
            ->willReturn(['open_prompt_task_key']);

        $form = $this->factory->create($this->formType::class);

        $view = $form->createView();

        $options = json_decode($view->vars['attr']['data-page-component-options'], true);

        self::assertEquals($expected, $options['builderPlugins']['aigeneration-plugin'] ?? null);
    }

    public function wysiwygDataProvider(): array
    {
        return [
            'add plugin' => [
                'feature' => true,
                'expected' => [
                    'jsmodule' => 'oroaicontentgeneration/js/app/grapesjs/plugins/aigeneration-plugin',
                    'openPromptTasks' => ['open_prompt_task_key']
                ]
            ],
            'no add plugin' => [
                'feature' => false,
                'expected' => null
            ]
        ];
    }

    #[\Override]
    protected function getExtensions(): array
    {
        return [
            new PreloadedExtension(
                [
                    WYSIWYGType::class => $this->formType
                ],
                []
            )
        ];
    }

    #[\Override]
    protected function getTypeExtensions(): array
    {
        $aiWysiwygExtension = new AIGenerationWysiwygFormExtension(
            $this->tasksProvider
        );
        $aiWysiwygExtension->addFeature('oro_ai_content_generation');
        $aiWysiwygExtension->setFeatureChecker($this->featureChecker);

        return array_merge(
            parent::getTypeExtensions(),
            [
                $aiWysiwygExtension
            ]
        );
    }
}
