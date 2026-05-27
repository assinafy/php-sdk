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

    public function testDeactivateUsesDedicatedInactivateEndpoint(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, [
            'url' => 'https://x',
            'email' => 'a@b.com',
            'events' => [WebhookResource::EVENT_DOCUMENT_READY],
            'is_active' => false,
        ]);

        $result = $webhooks->deactivate();

        $put = $http->lastCall();
        $this->assertSame('PUT', $put['method']);
        $this->assertSame('accounts/a/webhooks/inactivate', $put['uri']);
        $this->assertFalse($result['is_active']);
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

    public function testActivateThrowsWhenNoSubscription(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, []);

        $this->expectException(\RuntimeException::class);
        $webhooks->activate();
    }

    public function testEventTypesHitsGlobalEndpoint(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, [['id' => 'document_ready', 'description' => 'x']]);

        $result = $webhooks->eventTypes();

        $this->assertSame('webhooks/event-types', $http->lastCall()['uri']);
        $this->assertSame('document_ready', $result[0]['id']);
    }

    public function testDispatchesReturnsEnvelopeWithFilters(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, [['id' => 'd1', 'event' => 'document_ready']]);

        $result = $webhooks->dispatches(['event' => 'document_ready', 'delivered' => 'false']);

        $call = $http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('accounts/a/webhooks', $call['uri']);
        $this->assertSame(['event' => 'document_ready', 'delivered' => 'false'], $call['query']);
        $this->assertArrayHasKey('data', $result);
    }

    public function testRetryDispatch(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, ['id' => 'd1', 'delivered' => true]);

        $webhooks->retryDispatch('d1');

        $call = $http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('accounts/a/webhooks/d1/retry', $call['uri']);
    }
}
