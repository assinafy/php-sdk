<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Support;

use Assinafy\SDK\Exceptions\ValidationException;
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
     * Mirrors the current documented delivery envelope. It carries `object` (the entity)
     * and `payload` (event-specific detail); there is no `data` key.
     *
     * @return array<string, mixed>
     */
    private static function documentedDelivery(): array
    {
        return [
            'id' => 8629,
            'event' => 'signer_signed_document',
            'message' => 'Signer signed the document',
            'subject' => ['id' => '64f000000000000000000002', 'type' => 'Signer'],
            'origin' => ['ip' => '192.0.2.10', 'user-agent' => 'Example Browser'],
            'account_id' => '64f000000000000000000001',
            'created_at' => 1781024929,
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
        $event = $this->parser->extractEvent((string) json_encode(self::documentedDelivery()));

        $this->assertSame('signer_signed_document', $this->parser->getEventType($event));
        $this->assertSame('64f000000000000000000001', $this->parser->getAccountId($event));
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
        $event = $this->parser->extractEvent((string) json_encode(self::documentedDelivery()));

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

    // Standard Webhooks reference vector (https://www.standardwebhooks.com).
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    private const BODY = '{"test": 2432232314}';
    private const SENT_AT = 1614265330;
    private const SIGNATURE = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

    /** @return array<string, string> */
    private static function signedHeaders(string $signature = self::SIGNATURE): array
    {
        return [
            'webhook-id' => 'msg_p5jXN8AQM9LWM0D4loKWxJek',
            'webhook-timestamp' => (string) self::SENT_AT,
            'webhook-signature' => $signature,
        ];
    }

    public function testVerifySignatureAcceptsReferenceVector(): void
    {
        $this->assertTrue(
            $this->parser->verifySignature(self::BODY, self::signedHeaders(), self::SECRET, 300, self::SENT_AT)
        );
    }

    public function testVerifySignatureAcceptsAnyMatchingEntryAndHeaderStyles(): void
    {
        $multi = self::signedHeaders('v1,Zm9yZ2Vk ' . self::SIGNATURE);
        $server = [];
        foreach ($multi as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $psr7 = array_map(static fn (string $v): array => [$v], array_change_key_case($multi, CASE_UPPER));

        foreach ([$multi, $server, $psr7] as $headers) {
            $this->assertTrue(
                $this->parser->verifySignature(self::BODY, $headers, self::SECRET, 300, self::SENT_AT)
            );
        }
    }

    public function testVerifySignatureRejectsTamperingReplayAndMissingHeaders(): void
    {
        $p = $this->parser;
        $this->assertFalse($p->verifySignature('{"test": 1}', self::signedHeaders(), self::SECRET, 300, self::SENT_AT));
        $this->assertFalse($p->verifySignature(self::BODY, self::signedHeaders(), self::SECRET, 300, self::SENT_AT + 301));
        $this->assertFalse($p->verifySignature(self::BODY, self::signedHeaders(), self::SECRET, 300, self::SENT_AT - 301));
        $this->assertFalse($p->verifySignature(
            self::BODY,
            self::signedHeaders('v2,' . substr(self::SIGNATURE, 3)),
            self::SECRET,
            300,
            self::SENT_AT
        ));
        $this->assertFalse($p->verifySignature(
            self::BODY,
            self::signedHeaders(),
            'whsec_' . base64_encode('another-key'),
            300,
            self::SENT_AT
        ));
        foreach (array_keys(self::signedHeaders()) as $missing) {
            $headers = self::signedHeaders();
            unset($headers[$missing]);
            $this->assertFalse($p->verifySignature(self::BODY, $headers, self::SECRET, 300, self::SENT_AT));
        }
    }

    public function testVerifySignatureRejectsMalformedSecret(): void
    {
        $this->expectException(ValidationException::class);
        $this->parser->verifySignature(self::BODY, self::signedHeaders(), 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw');
    }
}
