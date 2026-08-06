<?php

declare(strict_types=1);

namespace Assinafy\SDK\Exceptions;

class ValidationException extends AssinafyException
{
    /** @var array<array-key, mixed> */
    private array $errors = [];

    /**
     * @param array<array-key, mixed> $errors
     */
    public function __construct(
        string $message = 'Validation failed',
        #[\SensitiveParameter] array $errors = [],
        int $code = 422
    ) {
        parent::__construct($message, $code, null, ['errors' => $errors]);
        $this->errors = $errors;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<array-key, mixed> $errors
     */
    public static function fromArray(#[\SensitiveParameter] array $errors): self
    {
        return new self('Validation failed', $errors);
    }
}
