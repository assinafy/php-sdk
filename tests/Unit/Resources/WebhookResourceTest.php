<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Resources\WebhookResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class WebhookResourceTest extends TestCase
{
    private function build(): array
    {
        $http = new FakeHttpClient();
        return [$http, new WebhookResource($http, new Configuration('k', 'a'))];
    }

    public function testRegisterSendsDefaultEventsWhenEmpty(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, ['url' => 'https://x', 'events' => WebhookResource::DEFAULT_EVENTS]);

        $webhooks->register('https://x', 'a@b.com');

        $call = $http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('accounts/a/webhooks/subscriptions', $call['uri']);
        $this->assertSame(WebhookResource::DEFAULT_EVENTS, $call['body']['events']);
        $this->assertSame('https://x', $call['body']['url']);
        $this->assertSame('a@b.com', $call['body']['email']);
        $this->assertTrue(
            $call['body']['is_active'],
            'is_active is required by the live API even though it is not in the public docs'
        );
    }

    public function testRegisterAllowsInactiveSubscription(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, []);

        $webhooks->register('https://x', 'a@b.com', [], false);

        $this->assertFalse($http->lastCall()['body']['is_active']);
    }

    public function testRegisterRespectsCustomEvents(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, []);

        $webhooks->register('https://x', 'a@b.com', [WebhookResource::EVENT_SIGNER_SIGNED]);

        $this->assertSame(
            [WebhookResource::EVENT_SIGNER_SIGNED],
            $http->lastCall()['body']['events']
        );
    }

    public function testGet(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, ['url' => 'https://x']);
        $sub = $webhooks->get();
        $this->assertSame('https://x', $sub['url']);
        $this->assertSame('accounts/a/webhooks/subscriptions', $http->lastCall()['uri']);
    }

    public function testDeactivatePreservesConfigAndFlipsIsActive(): void
    {
        [$http, $webhooks] = $this->build();
        // GET current
        $http->queueJson(200, [
            'url' => 'https://x',
            'email' => 'a@b.com',
            'events' => [WebhookResource::EVENT_DOCUMENT_READY],
            'is_active' => true,
        ]);
        // PUT replacement
        $http->queueJson(200, []);

        $webhooks->deactivate();

        $put = $http->lastCall();
        $this->assertSame('PUT', $put['method']);
        $this->assertSame('accounts/a/webhooks/subscriptions', $put['uri']);
        $this->assertSame('https://x', $put['body']['url']);
        $this->assertSame('a@b.com', $put['body']['email']);
        $this->assertSame([WebhookResource::EVENT_DOCUMENT_READY], $put['body']['events']);
        $this->assertFalse($put['body']['is_active']);
    }

    public function testActivateReusesExistingConfig(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, [
            'url' => 'https://x',
            'email' => 'a@b.com',
            'events' => WebhookResource::DEFAULT_EVENTS,
            'is_active' => false,
        ]);
        $http->queueJson(200, []);

        $webhooks->activate();

        $this->assertTrue($http->lastCall()['body']['is_active']);
    }

    public function testDeactivateThrowsWhenNoSubscription(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, []);

        $this->expectException(\RuntimeException::class);
        $webhooks->deactivate();
    }
}
