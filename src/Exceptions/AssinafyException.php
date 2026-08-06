<?php

declare(strict_types=1);

namespace Assinafy\SDK\Exceptions;

use Exception;

class AssinafyException extends Exception
{
    /** @var array<string, mixed> */
    protected array $context = [];

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        #[\SensitiveParameter] array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setContext(#[\SensitiveParameter] array $context): self
    {
        $this->context = $context;
        return $this;
    }
}
