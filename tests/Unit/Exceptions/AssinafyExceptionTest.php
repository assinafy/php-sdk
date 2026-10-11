<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Exceptions;

use Assinafy\SDK\Exceptions\ApiException;
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

    public function testRetryAfterAndRateLimitParsing(): void
    {
        $none = new ApiException('Not found', 404);
        $this->assertFalse($none->isRateLimited());
        $this->assertNull($none->getRetryAfter());
        $this->assertNull($none->getRateLimit());

        $date = new ApiException('Slow down', 429, null, null, [
            'retry-after' => gmdate('D, d M Y H:i:s \\G\\M\\T', time() + 120),
            'x-rate-limit-limit' => '120',
            'x-rate-limit-remaining' => '0',
        ]);
        $this->assertTrue($date->isRateLimited());
        $this->assertGreaterThanOrEqual(118, $date->getRetryAfter());
        $this->assertLessThanOrEqual(120, $date->getRetryAfter());
        $this->assertNull($date->getRateLimit(), 'all three headers are required');

        $past = new ApiException('Slow down', 429, null, null, ['Retry-After' => 'Thu, 01 Jan 1970 00:00:00 GMT']);
        $this->assertSame(0, $past->getRetryAfter());

        $garbage = new ApiException('Slow down', 429, null, null, ['Retry-After' => 'soon-ish']);
        $this->assertNull($garbage->getRetryAfter());
    }
}
