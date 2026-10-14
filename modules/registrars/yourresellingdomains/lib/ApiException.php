<?php

declare(strict_types=1);

namespace YourReselling\Whmcs\Domains;

/** An API failure with structured validation and rate-limit details; never includes credentials or raw HTML. */
final class ApiException extends \RuntimeException
{
    /** @param array<string, mixed> $validationErrors */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorCode = null,
        public readonly array $validationErrors = [],
        public readonly ?string $requestId = null,
        public readonly ?string $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }
}
