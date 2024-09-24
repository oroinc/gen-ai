<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Form\Extension;

use Oro\Bundle\AiContentGenerationBundle\Form\Extension\OroRichTextTypeExtension;
use Oro\Bundle\AiContentGenerationBundle\Provider\TasksProvider;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\FormBundle\Form\Type\OroRichTextType;
use Oro\Bundle\FormBundle\Provider\HtmlTagProvider;
use Oro\Bundle\UIBundle\Tools\HtmlTagHelper;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Asset\Context\ContextInterface;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\FormIntegrationTestCase;

final class OroRichTextTypeExtensionTest extends FormIntegrationTestCase
{
    private ContextInterface&MockObject $context;

    private FeatureChecker&MockObject $featureChecker;

    private TasksProvider&MockObject $tasksProvider;

    #[\Override]
    protected function setUp(): void
    {
        $this->context = $this->createMock(ContextInterface::class);
        $this->featureChecker = $this->createMock(FeatureChecker::class);
        $this->tasksProvider = $this->createMock(TasksProvider::class);

        $this->formType = new OroRichTextType(
            $this->createMock(ConfigManager::class),
            $this->createMock(HtmlTagProvider::class),
            $this->context,
            $this->createMock(HtmlTagHelper::class)
        );

        parent::setUp();
    }

    /**
     * @dataProvider richTextDataProvider
     */
    public function testAIGenerationRichTextFormExtension(bool $feature, array $expected): void
    {
        $this->featureChecker->expects(self::once())
            ->method('isFeatureEnabled')
            ->willReturn($feature);

        $this->tasksProvider
            ->expects(self::any())
            ->method('getOpenPromptTaskKeys')
            ->willReturn(['open_prompt_task_key']);

        $form = $this->factory->create($this->formType::class);
        $pageComponentOption = $form->getConfig()->getOption('page-component');

        self::assertEquals($expected, $pageComponentOption['options']);
    }

    public function richTextDataProvider(): array
    {
        return [
            'add plugin' => [
                'feature' => true,
                'expected' => [
                    'view' => 'oroaicontentgeneration/js/app/tinymce/wysiwyg-editor/wysiwyg-editor-view',
                    'openPromptTasks' => ['open_prompt_task_key'],
                    'content_css' => 'build/admin/tinymce/wysiwyg-editor.css'
                ]
            ],
            'no add plugin' => [
                'feature' => false,
                'expected' => [
                    'view' => 'oroform/js/app/views/wysiwig-editor/wysiwyg-editor-view',
                    'content_css' => 'build/admin/tinymce/wysiwyg-editor.css'
                ]
            ]
        ];
    }

    #[\Override]
    protected function getExtensions(): array
    {
        return [
            new PreloadedExtension(
                [
                    OroRichTextType::class => $this->formType
                ],
                []
            )
        ];
    }

    #[\Override]
    protected function getTypeExtensions(): array
    {
        $richTextExtension = new OroRichTextTypeExtension(
            $this->context,
            $this->tasksProvider
        );
        $richTextExtension->addFeature('oro_ai_content_generation');
        $richTextExtension->setFeatureChecker($this->featureChecker);

        return array_merge(
            parent::getTypeExtensions(),
            [
                $richTextExtension
            ]
        );
    }
}
