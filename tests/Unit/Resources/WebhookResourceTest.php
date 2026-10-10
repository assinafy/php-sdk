<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\NetworkException;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\WebhookResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $webhooks->register('https://x', 'a@example.com');

        $call = $http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('accounts/a/webhooks/subscriptions', $call['uri']);
        $this->assertSame(WebhookResource::DEFAULT_EVENTS, $call['body']['events']);
        $this->assertSame('https://x', $call['body']['url']);
        $this->assertSame('a@example.com', $call['body']['email']);
        $this->assertTrue(
            $call['body']['is_active'],
            'is_active is required by the API contract'
        );
    }

    public function testRegisterAllowsInactiveSubscription(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, []);

        $webhooks->register('https://x', 'a@example.com', [], false);

        $this->assertFalse($http->lastCall()['body']['is_active']);
    }

    public function testRegisterRespectsCustomEvents(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, []);

        $webhooks->register('https://x', 'a@example.com', [WebhookResource::EVENT_SIGNER_SIGNED]);

        $this->assertSame(
            [WebhookResource::EVENT_SIGNER_SIGNED],
            $http->lastCall()['body']['events']
        );
    }

    public function testRegisterRejectsWhitespaceEvent(): void
    {
        [, $webhooks] = $this->build();

        $this->expectException(ValidationException::class);
        $webhooks->register('https://x', 'a@example.com', ['   ']);
    }

    public function testRegisterRejectsNonHttpWebhookUrl(): void
    {
        [, $webhooks] = $this->build();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('absolute HTTP or HTTPS URL');

        $webhooks->register('ftp://example.com/hook', 'a@example.com');
    }

    public function testRegisterAcceptsAbsoluteHttpUrlWithQuery(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, []);

        $webhooks->register('http://example.com/hook?source=sdk', 'developer@example.test');

        $this->assertSame('http://example.com/hook?source=sdk', $http->lastCall()['body']['url']);
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
            'email' => 'a@example.com',
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
            'email' => 'a@example.com',
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
        $this->assertSame([
            'page' => 1,
            'per-page' => 20,
            'event' => 'document_ready',
            'delivered' => 'false',
        ], $call['query']);
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

    public function testDispatchesRejectsNonIntegerPage(): void
    {
        [, $webhooks] = $this->build();

        $this->expectException(ValidationException::class);
        $webhooks->dispatches(['page' => '2']);
    }

    public function testRetryDispatchRejectsAnEmptyId(): void
    {
        [, $webhooks] = $this->build();

        $this->expectException(ValidationException::class);
        $webhooks->retryDispatch('');
    }

    public function testEndpointCrudUsesDocumentedRoutesAndShapes(): void
    {
        [$http, $webhooks] = $this->build();
        $endpoint = ['id' => 'ep1', 'url' => 'https://example.com/a', 'signing_enabled' => true];
        $http->queueJson(200, [$endpoint])
            ->queueJson(200, $endpoint)
            ->queueJson(200, $endpoint)
            ->queueJson(200, $endpoint)
            ->queueJson(200, []);

        $this->assertSame([$endpoint], $webhooks->listEndpoints());
        $this->assertSame($endpoint, $webhooks->createEndpoint(
            'https://example.com/a',
            'ops@example.com',
            [WebhookResource::EVENT_DOCUMENT_READY],
            'ERP',
            signingEnabled: true
        ));
        $this->assertSame($endpoint, $webhooks->getEndpoint('ep1'));
        $this->assertSame($endpoint, $webhooks->updateEndpoint('ep1', ['is_active' => false]));
        $this->assertSame([], $webhooks->deleteEndpoint('ep1'));

        $routes = array_map(static fn (array $c): string => $c['method'] . ' ' . $c['uri'], $http->calls);
        $this->assertSame([
            'GET accounts/a/webhooks/endpoints',
            'POST accounts/a/webhooks/endpoints',
            'GET accounts/a/webhooks/endpoints/ep1',
            'PUT accounts/a/webhooks/endpoints/ep1',
            'DELETE accounts/a/webhooks/endpoints/ep1',
        ], $routes);
        $this->assertSame([
            'url' => 'https://example.com/a',
            'email' => 'ops@example.com',
            'events' => ['document_ready'],
            'is_active' => true,
            'signing_enabled' => true,
            'name' => 'ERP',
        ], $http->calls[1]['body']);
        $this->assertSame(['is_active' => false], $http->calls[3]['body']);
    }

    public function testCreateEndpointOmitsNullNameAndDefaultsEvents(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, []);

        $webhooks->createEndpoint('https://example.com/a', 'ops@example.com');

        $body = $http->lastCall()['body'];
        $this->assertArrayNotHasKey('name', $body);
        $this->assertSame(WebhookResource::DEFAULT_EVENTS, $body['events']);
        $this->assertFalse($body['signing_enabled']);
    }

    public function testSecretMethodsReturnTheSecretString(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, ['secret' => 'whsec_old'])->queueJson(200, ['secret' => 'whsec_new']);

        $this->assertSame('whsec_old', $webhooks->endpointSecret('ep1'));
        $this->assertSame('whsec_new', $webhooks->rotateEndpointSecret('ep1'));
        $this->assertSame('GET accounts/a/webhooks/endpoints/ep1/secret', $http->calls[0]['method'] . ' ' . $http->calls[0]['uri']);
        $this->assertSame('POST accounts/a/webhooks/endpoints/ep1/secret/rotate', $http->calls[1]['method'] . ' ' . $http->calls[1]['uri']);
    }

    public function testSecretWithoutValueIsMalformedResponse(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, []);

        $this->expectException(NetworkException::class);
        $webhooks->endpointSecret('ep1');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidEndpointChanges(): iterable
    {
        yield 'empty' => [[]];
        yield 'unknown field' => [['secret' => 'x']];
        yield 'relative url' => [['url' => '/hook']];
        yield 'bad email' => [['email' => 'nope']];
        yield 'empty events' => [['events' => []]];
        yield 'blank event' => [['events' => [' ']]];
        yield 'string flag' => [['signing_enabled' => 'true']];
        yield 'numeric name' => [['name' => 3]];
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidEndpointChanges')]
    public function testUpdateEndpointRejectsInvalidChangesLocally(array $changes): void
    {
        [$http, $webhooks] = $this->build();

        try {
            $webhooks->updateEndpoint('ep1', $changes);
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
            $this->assertSame([], $http->calls);
        }
    }

    public function testEndpointIdIsPathEncoded(): void
    {
        [$http, $webhooks] = $this->build();
        $http->queueJson(200, []);

        $webhooks->getEndpoint('../secret');

        $this->assertSame('accounts/a/webhooks/endpoints/..%2Fsecret', $http->lastCall()['uri']);
    }
}
