<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Request;

use Oro\Bundle\AiContentGenerationBundle\Request\UserContentGenerationRequest;
use PHPUnit\Framework\TestCase;

final class UserContentGenerationRequestTest extends TestCase
{
    public function testThatUserContentGenerationRequestValid(): void
    {
        $contentGenerationRequest = new UserContentGenerationRequest(
            'oro_form',
            ['option' => 'value'],
            'form_field',
            ['another_option' => 'value']
        );

        self::assertEquals('oro_form', $contentGenerationRequest->getSubmittedFormName());
        self::assertEquals(['option' => 'value'], $contentGenerationRequest->getSubmittedFormData());
        self::assertEquals('form_field', $contentGenerationRequest->getSubmittedFormField());
        self::assertEquals(
            ['another_option' => 'value'],
            $contentGenerationRequest->getSubmittedContentGenerationFormData()
        );
    }

    public function testThatCreateWithEmptyArrayWithoutError(): void
    {
        $request = UserContentGenerationRequest::fromRenderRequest([]);

        self::assertEquals('', $request->getSubmittedFormName());
        self::assertEquals([], $request->getSubmittedFormData());
        self::assertEquals('', $request->getSubmittedFormField());
        self::assertEquals([], $request->getSubmittedContentGenerationFormData());

        $request = UserContentGenerationRequest::fromSubmitRequest([]);

        self::assertEquals('', $request->getSubmittedFormName());
        self::assertEquals([], $request->getSubmittedFormData());
        self::assertEquals('', $request->getSubmittedFormField());
        self::assertEquals([], $request->getSubmittedContentGenerationFormData());
    }

    public function testThatFromRenderRequestMergesFormData(): void
    {
        $request = UserContentGenerationRequest::fromRenderRequest([
            'submitted_form_name' => 'form_name',
            'submitted_form_field' => 'field',
            'submitted_form_data' => [
                'option' => 'value',
                'source_form_submitted_form_data' => json_encode(['note' => true])
            ]
        ]);

        self::assertEquals('form_name', $request->getSubmittedFormName());
        self::assertEquals(
            [
                'option' => 'value',
                'note' => true,
                'source_form_submitted_form_data' => json_encode(['note' => true])
            ],
            $request->getSubmittedFormData()
        );
        self::assertEquals('field', $request->getSubmittedFormField());
    }
}
