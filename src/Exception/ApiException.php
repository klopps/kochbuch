<?php

declare(strict_types=1);

namespace Kochbuch\Exception;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly ?string $errorCode = null,
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
}
