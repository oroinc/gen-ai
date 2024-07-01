<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Task;

use Oro\Bundle\AiContentGenerationBundle\Context\ContextItem;
use Oro\Bundle\AiContentGenerationBundle\Provider\ProductTaskContextProvider;
use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use Oro\Bundle\AiContentGenerationBundle\Task\ExtractProductAttributesTask;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ExtractProductAttributesTaskTest extends TestCase
{
    private ProductTaskContextProvider&MockObject $productContextProvider;

    private ExtractProductAttributesTask $task;

    protected function setUp(): void
    {
        $this->productContextProvider = $this->createMock(ProductTaskContextProvider::class);
        $this->task = new ExtractProductAttributesTask($this->productContextProvider, 'descriptions');
    }

    /**
     * @dataProvider notSupportedRequest
     */
    public function testThatTaskNotSupportsRequest(UserContentGenerationRequest $request): void
    {
        self::assertFalse($this->task->supports($request));
    }

    public function testThatTaskDoesNotHaveEnoughContext(): void
    {
        $request = new UserContentGenerationRequest(
            'oro_product',
            [],
            '[descriptions]',
            []
        );

        self::assertFalse($this->task->supports($request));
    }

    public function testThatTaskSupportsRequest(): void
    {
        $request = new UserContentGenerationRequest(
            'oro_product',
            [],
            '[descriptions]',
            []
        );

        $this->productContextProvider
            ->expects(self::once())
            ->method('getDescription')
            ->willReturn(new ContextItem('description', 'content'));

        self::assertTrue($this->task->supports($request));
    }

    public function testOnKey(): void
    {
        self::assertEquals('extract_product_attributes', $this->task->getKey());
    }

    public function testContext(): void
    {
        $request = new UserContentGenerationRequest(
            'oro_product',
            [],
            '[descriptions]',
            []
        );

        $contextItem = new ContextItem('description', 'content');

        $this->productContextProvider
            ->expects(self::once())
            ->method('getDescription')
            ->willReturn($contextItem);

        self::assertEquals([$contextItem], $this->task->getContext($request));
    }

    private function notSupportedRequest(): array
    {
        $notProductForm = new UserContentGenerationRequest(
            'not_product_form',
            [],
            '[plural]',
            []
        );

        $notProductFormField = new UserContentGenerationRequest(
            'oro_product',
            [],
            '[not_valid]',
            []
        );

        return [
            'Not product form' => [
                $notProductForm
            ],
            'Not valid product form field' => [
                $notProductFormField
            ]
        ];
    }
}
