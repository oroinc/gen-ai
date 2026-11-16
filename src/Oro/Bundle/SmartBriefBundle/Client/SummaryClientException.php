<?php

namespace Oro\Bundle\SmartBriefBundle\Client;

/**
 * The single failure type of the summary client layer.
 *
 * The reason is a fixed vocabulary used for the fallback metrics; the HTTP status and provider error code are kept
 * for logging.
 *
 * - The message is a short developer description. It must not contain the provider response body, the prompt,
 *   credentials or the request URL; loggers use the reason, HTTP status, provider error code and the exception
 *   class, never the message or the exception object as log context.
 * - The previous exception must not be a transport exception carrying the HTTP request or response
 *   (e.g. a Guzzle request exception or the OpenAI SDK error); clients extract status and code and drop it.
 * - The provider error code is the provider's enumerated code or status only, never a free-text message.
 */
class SummaryClientException extends \RuntimeException
{
    public const string REASON_CLIENT_ERROR = 'client_error';
    public const string REASON_TIMEOUT = 'timeout';
    public const string REASON_RATE_LIMITED = 'rate_limited';
    public const string REASON_TRUNCATED = 'truncated';

    public const array REASONS = [
        self::REASON_CLIENT_ERROR,
        self::REASON_TIMEOUT,
        self::REASON_RATE_LIMITED,
        self::REASON_TRUNCATED,
    ];

    /**
     * @param string      $reason            One of {@see REASONS}
     * @param int|null    $httpStatus        HTTP status of the provider response, when there was one
     * @param string|null $providerErrorCode The provider's enumerated error code or status, when present
     */
    public function __construct(
        string $message,
        private readonly string $reason = self::REASON_CLIENT_ERROR,
        private readonly ?int $httpStatus = null,
        private readonly ?string $providerErrorCode = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getProviderErrorCode(): ?string
    {
        return $this->providerErrorCode;
    }
}
