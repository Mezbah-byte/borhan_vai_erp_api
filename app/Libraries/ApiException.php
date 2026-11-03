<?php

namespace App\Libraries;

class ApiException extends \RuntimeException
{
    public array $errors;

    public function __construct(string $message, int $code = 400, array $errors = [])
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }
}
