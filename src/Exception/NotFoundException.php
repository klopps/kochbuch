<?php

declare(strict_types=1);

namespace Kochbuch\Exception;

class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Resource not found.', ?string $code = null)
    {
        parent::__construct($message, 404, $code);
    }
}
