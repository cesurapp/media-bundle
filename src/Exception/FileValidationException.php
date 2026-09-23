<?php

namespace Cesurapp\MediaBundle\Exception;

class FileValidationException extends \Exception
{
    public function __construct(
        string $message = 'Validation failed',
        int $code = 422,
        protected ?array $errors = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getErrors(): ?array
    {
        return $this->errors;
    }
}
