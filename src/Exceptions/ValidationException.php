<?php

declare(strict_types=1);

namespace Assinafy\SDK\Exceptions;

class ValidationException extends AssinafyException
{
    private array $errors = [];

    public function __construct(string $message = 'Validation failed', array $errors = [], int $code = 422)
    {
        parent::__construct($message, $code, null, ['errors' => $errors]);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public static function fromArray(array $errors): self
    {
        return new self('Validation failed', $errors);
    }
}

