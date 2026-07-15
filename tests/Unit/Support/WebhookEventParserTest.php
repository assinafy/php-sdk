<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Support;

use Assinafy\SDK\Support\WebhookEventParser;
use PHPUnit\Framework\TestCase;

final class WebhookEventParserTest extends TestCase
{
    private WebhookEventParser $parser;

    protected function setUp(): void
    {
        $this->parser = new WebhookEventParser();
    }

    /**
     * Mirrors a real delivery captured from the sandbox. The envelope carries `object` (the
     * entity) and `payload` (event-specific detail); there is no `data` key, which is why the
     * 1.x `getEventData()` fallback chain never fired against real traffic.
     *
     * @return array<string, mixed>
     */
    private static function realDelivery(): array
    {
        return [
            'id' => 8629,
            'event' => 'signer_signed_document',
            'message' => 'Signer signed the document',
            'subject' => 'Document',
            'origin' => 'api',
            'account_id' => '102d25a489f34a275d31a16045fd',
            'created_at' => '2026-06-09T17:08:49Z',
            'object' => ['id' => '1032c5537d351349a9a94ad01cbe', 'type' => 'Document'],
            'payload' => ['signer_id' => '19e6b92e7895332ed9708535d8c'],
        ];
    }

    public function testExtractEventReturnsNullForInvalidJson(): void
    {
        $this->assertNull($this->parser->extractEvent('not-json'));
        $this->assertNull($this->parser->extractEvent(''));
    }

    public function testExtractEventReturnsNullForNonArrayJson(): void
    {
        $this->assertNull($this->parser->extractEvent('"a string"'));
        $this->assertNull($this->parser->extractEvent('42'));
    }

    public function testParsesARealDeliveryEnvelope(): void
    {
        $event = $this->parser->extractEvent((string) json_encode(self::realDelivery()));

        $this->assertSame('signer_signed_document', $this->parser->getEventType($event));
        $this->assertSame('102d25a489f34a275d31a16045fd', $this->parser->getAccountId($event));
        $this->assertSame(
            ['id' => '1032c5537d351349a9a94ad01cbe', 'type' => 'Document'],
            $this->parser->getEventData($event)
        );
        $this->assertSame(
            ['signer_id' => '19e6b92e7895332ed9708535d8c'],
            $this->parser->getEventPayload($event)
        );
    }

    public function testObjectAndPayloadAreDistinct(): void
    {
        $event = $this->parser->extractEvent((string) json_encode(self::realDelivery()));

        $this->assertNotSame($this->parser->getEventData($event), $this->parser->getEventPayload($event));
    }

    public function testAccessorsTolerateMissingKeys(): void
    {
        $this->assertNull($this->parser->getEventType([]));
        $this->assertNull($this->parser->getEventType(null));
        $this->assertNull($this->parser->getAccountId([]));
        $this->assertSame([], $this->parser->getEventData([]));
        $this->assertSame([], $this->parser->getEventPayload([]));
        $this->assertSame([], $this->parser->getEventData(null));
        $this->assertSame([], $this->parser->getEventPayload(null));
    }

    public function testScalarObjectAndPayloadDoNotLeakThroughArrayAccessors(): void
    {
        $event = $this->parser->extractEvent('{"event":7,"object":"nope","payload":13}');

        $this->assertNull($this->parser->getEventType($event));
        $this->assertSame([], $this->parser->getEventData($event));
        $this->assertSame([], $this->parser->getEventPayload($event));
    }
}
