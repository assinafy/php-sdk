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
        $this->assertArrayNotHasKey(
            'is_active',
            $call['body'],
            'is_active is not part of the API contract; do not send it'
        );
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

    public function testGetAndDelete(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, ['url' => 'https://x']);
        $sub = $webhooks->get();
        $this->assertSame('https://x', $sub['url']);
        $this->assertSame('accounts/a/webhooks/subscriptions', $http->lastCall()['uri']);

        $http->queueJson(200, []);
        $webhooks->delete();
        $this->assertSame('DELETE', $http->lastCall()['method']);
    }
}
