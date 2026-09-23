<?php

declare(strict_types=1);

namespace Kochbuch\Exception;

class UnauthorizedException extends ApiException
{
    public function __construct(string $message = 'Authentication required.', ?string $code = null)
    {
        parent::__construct($message, 401, $code);
    }
}
