<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Exceptions;

use Assinafy\SDK\Exceptions\AssinafyException;
use PHPUnit\Framework\TestCase;

final class AssinafyExceptionTest extends TestCase
{
    public function testContextRoundTripsThroughGetterAndSetter(): void
    {
        $exception = new AssinafyException('boom', 0, null, ['initial' => 'value']);
        $this->assertSame(['initial' => 'value'], $exception->getContext());

        $replacement = ['status' => 422, 'detail' => 'nope'];
        $this->assertSame($exception, $exception->setContext($replacement));
        $this->assertSame($replacement, $exception->getContext());
    }

    public function testContextParametersAreMarkedSensitive(): void
    {
        $constructor = new \ReflectionMethod(AssinafyException::class, '__construct');
        $constructorContext = $constructor->getParameters()[3];
        $this->assertSame('context', $constructorContext->getName());
        $this->assertNotEmpty(
            $constructorContext->getAttributes(\SensitiveParameter::class),
            'The constructor $context parameter must be #[\SensitiveParameter]'
        );

        $setter = new \ReflectionMethod(AssinafyException::class, 'setContext');
        $setterContext = $setter->getParameters()[0];
        $this->assertSame('context', $setterContext->getName());
        $this->assertNotEmpty(
            $setterContext->getAttributes(\SensitiveParameter::class),
            'setContext() $context must be #[\SensitiveParameter] so stack traces redact it'
        );
    }
}
