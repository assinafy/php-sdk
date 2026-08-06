<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Support;

use Assinafy\SDK\Support\MutableLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class MutableLoggerTest extends TestCase
{
    public function testStringableMessageIsNormalizedForEveryPsrLogVersion(): void
    {
        $target = new class () extends AbstractLogger {
            public string $lastMessage = '';

            public function log($level, $message, array $context = []): void
            {
                $this->lastMessage = $message;
            }
        };
        $proxy = new MutableLogger($target);
        $message = new class () implements \Stringable {
            public function __toString(): string
            {
                return 'normalized message';
            }
        };

        $proxy->log('info', $message);

        $this->assertSame('normalized message', $target->lastMessage);
        $this->assertSame($target, $proxy->getLogger());
    }
}
