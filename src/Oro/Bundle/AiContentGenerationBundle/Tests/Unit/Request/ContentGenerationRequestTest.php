<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Request;

use Oro\Bundle\AiContentGenerationBundle\Request\ContentGenerationRequest;
use PHPUnit\Framework\TestCase;

final class ContentGenerationRequestTest extends TestCase
{
    public function testThatContentGenerationRequestValid(): void
    {
        $contentGenerationRequest = new ContentGenerationRequest(
            'Generate article',
            [
                'title' => 'Custom Product Title',
                'description' => '<div>Product description</div>'
            ],
            'Formal'
        );
        $contentGenerationRequest->setMaxTokens(120);

        self::assertEquals(
            "Context: \ntitle:Custom Product Title\ndescription:Product description",
            $contentGenerationRequest->getClientContext()
        );
        self::assertEquals('Generate article with tone Formal', $contentGenerationRequest->getClientPrompt());
        self::assertEquals(120, $contentGenerationRequest->getMaxTokens());
    }
}
