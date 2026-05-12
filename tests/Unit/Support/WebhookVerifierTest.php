<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Support;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Support\WebhookVerifier;
use PHPUnit\Framework\TestCase;

final class WebhookVerifierTest extends TestCase
{
    public function testVerifyReturnsTrueOnMatchingSignature(): void
    {
        $verifier = new WebhookVerifier(new Configuration('k', 'a', Configuration::DEFAULT_BASE_URL, 'shh'));
        $payload = '{"event":"document_ready"}';
        $sig = hash_hmac('sha256', $payload, 'shh');

        $this->assertTrue($verifier->verify($payload, $sig));
    }

    public function testVerifyReturnsFalseOnMismatch(): void
    {
        $verifier = new WebhookVerifier(new Configuration('k', 'a', Configuration::DEFAULT_BASE_URL, 'shh'));
        $this->assertFalse($verifier->verify('{}', 'nope'));
    }

    public function testVerifyReturnsFalseWithoutSecret(): void
    {
        $verifier = new WebhookVerifier(new Configuration('k', 'a'));
        $this->assertFalse($verifier->verify('{}', hash_hmac('sha256', '{}', 'shh')));
    }

    public function testExtractAndAccessHelpers(): void
    {
        $verifier = new WebhookVerifier(new Configuration('k', 'a', Configuration::DEFAULT_BASE_URL, 's'));

        $this->assertNull($verifier->extractEvent('not-json'));

        $event = $verifier->extractEvent('{"event":"signer_signed_document","data":{"id":"x"}}');
        $this->assertSame('signer_signed_document', $verifier->getEventType($event));
        $this->assertSame(['id' => 'x'], $verifier->getEventData($event));
    }
}
