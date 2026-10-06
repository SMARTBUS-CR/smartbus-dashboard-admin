<?php

namespace App\Exceptions;

use Exception;

class AuthenticationServiceException extends Exception
{
    public function __construct(string $message = 'The authentication service is unavailable. Please try again shortly.')
    {
        parent::__construct($message);
    }
}
