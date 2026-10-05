<?php

declare(strict_types=1);

namespace Kochbuch\Exception;

use RuntimeException;

class ApiException extends RuntimeException
{
    /**
     * @param array<string, scalar|null> $details extra fields merged into the
     *        JSON error body next to message/code (e.g. "retry_at" for a rate
     *        limit) - see App.php's error handler and public/js/i18n.js's
     *        translateApiError(), which can use them in the translated text
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly ?string $errorCode = null,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Machine-readable identifier (e.g. "auth.invalid_credentials") the
     * frontend can translate via its own t() catalog under an "error.*"
     * namespace, falling back to this exception's English getMessage() when
     * null or when no matching key exists.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
