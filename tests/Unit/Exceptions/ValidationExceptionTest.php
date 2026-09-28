<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Exceptions;

use Assinafy\SDK\Exceptions\AssinafyException;
use Assinafy\SDK\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class ValidationExceptionTest extends TestCase
{
    public function testGetErrorsReturnsTheValidationFailures(): void
    {
        $errors = ['email' => ['The email field is required']];
        $exception = new ValidationException('Validation failed', $errors);

        $this->assertSame($errors, $exception->getErrors());
        $this->assertSame(422, $exception->getCode());
        $this->assertSame(['errors' => $errors], $exception->getContext());
        $this->assertInstanceOf(AssinafyException::class, $exception);
    }

    public function testFromArrayBuildsAnExceptionCarryingTheErrors(): void
    {
        $errors = ['document' => ['The document must be a PDF']];
        $exception = ValidationException::fromArray($errors);

        $this->assertSame('Validation failed', $exception->getMessage());
        $this->assertSame($errors, $exception->getErrors());
    }
}
