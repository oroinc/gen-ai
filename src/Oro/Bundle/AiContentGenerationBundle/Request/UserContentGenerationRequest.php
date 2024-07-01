<?php

namespace Oro\Bundle\AiContentGenerationBundle\Request;

use Oro\Component\MessageQueue\Util\JSON;

/**
 * Holds information from the user form request needed for task processing
 */
readonly class UserContentGenerationRequest
{
    public function __construct(
        private string $submittedFormName,
        private array $submittedFormData,
        private string $submittedFormField,
        private array $submittedContentGenerationFormData,
    ) {
    }

    public static function fromRenderRequest(array $requestData): self
    {
        $submittedFormData = $requestData['submitted_form_data'] ?? [];

        if (isset($submittedFormData['source_form_submitted_form_data'])) {
            $requestData['submitted_form_data'] = array_merge(
                $requestData['submitted_form_data'],
                JSON::decode(
                    $submittedFormData['source_form_submitted_form_data']
                )
            );
        }

        return new self(
            $requestData['submitted_form_name'] ?? '',
            $requestData['submitted_form_data'] ?? [],
            $requestData['submitted_form_field'] ?? '',
            []
        );
    }

    public static function fromSubmitRequest(array $formData): self
    {
        return new self(
            $formData['source_form_submitted_form_name'] ?? '',
            JSON::decode($formData['source_form_submitted_form_data'] ?? []),
            $formData['source_form_submitted_form_field'] ?? '',
            $formData
        );
    }

    public function getSubmittedFormName(): string
    {
        return $this->submittedFormName;
    }

    public function getSubmittedFormData(): array
    {
        return $this->submittedFormData;
    }

    public function getSubmittedFormField(): string
    {
        return $this->submittedFormField;
    }

    public function getSubmittedContentGenerationFormData(): array
    {
        return $this->submittedContentGenerationFormData;
    }
}
