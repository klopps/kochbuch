<?php

declare(strict_types=1);

namespace Kochbuch\Exception;

class ForbiddenException extends ApiException
{
    public function __construct(string $message = 'Not allowed to perform this action.', ?string $code = null)
    {
        parent::__construct($message, 403, $code);
    }
}
