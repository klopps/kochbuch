<?php

declare(strict_types=1);

namespace Kochbuch\Exception;

class ValidationException extends ApiException
{
    public function __construct(string $message = 'Invalid request data.', ?string $code = null)
    {
        parent::__construct($message, 422, $code);
    }
}
