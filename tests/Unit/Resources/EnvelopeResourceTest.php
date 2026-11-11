<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\EnvelopeResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class EnvelopeResourceTest extends TestCase
{
    public function testCreationAndEstimatePreserveOptionsAndNormalizeNestedLists(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(new Configuration('key', 'acc'), $http);
        $resource = $client->envelopes();
        $this->assertSame($resource, $client->envelopes());
        $body = [
            'name' => 'Agreement', 'message' => null, 'max_total_credits' => 1.5,
            'signers' => [2 => ['id' => 's', 'embedded' => true, 'notification_methods' => [4 => 'Email']]],
            'documents' => [3 => ['id' => 'd', 'signer_ids' => [6 => 's'], 'method' => 'collect',
                'copy_receiver_ids' => [2 => 'cc'], 'expires_at' => null,
                'entries' => [7 => ['page_id' => 'page', 'fields' => [8 => ['signer_id' => 's', 'field_id' => 'f',
                    'display_settings' => ['left' => 10, 'top' => 20, 'width' => 40, 'height' => 20]]]]]]],
        ];
        $http->queueJson(200, ['id' => 'env'])->queueJson(200, ['total_credits' => 1.5]);
        $this->assertSame(['id' => 'env'], $resource->create($body, 'request-1'));
        $call = $http->lastCall();
        $this->assertSame(['Idempotency-Key' => 'request-1'], $call['headers']);
        $this->assertSame('accounts/acc/envelopes', $call['uri']);
        $this->assertTrue($call['body']['signers'][0]['embedded']);
        $this->assertSame(['Email'], $call['body']['signers'][0]['notification_methods']);
        $document = $call['body']['documents'][0];
        $this->assertSame(['s'], $document['signer_ids']);
        $this->assertSame(['cc'], $document['copy_receiver_ids']);
        $this->assertSame('f', $document['entries'][0]['fields'][0]['field_id']);
        $this->assertNull($document['expires_at']);
        $this->assertSame(['total_credits' => 1.5], $resource->estimateCost($body));
        $this->assertSame($call['body'], $http->lastCall()['body']);
        $this->assertSame([], $http->lastCall()['headers']);
    }

    public function testLifecycleRoutesEncodeCallerIdsAndKeepReturns(): void
    {
        $http = new FakeHttpClient();
        $resource = new EnvelopeResource($http, new Configuration('key', 'acc'));
        $operations = [
            ['GET', '', fn () => $resource->get('env/id')],
            ['PATCH', '', fn () => $resource->update('env/id', ['message' => null, 'document_ids' => [2 => 'd']])],
            ['PUT', '/cancel', fn () => $resource->cancel('env/id')],
            ['PUT', '/signers/s%2Fid/resend', fn () => $resource->resend('env/id', 's/id')],
            ['POST', '/documents', fn () => $resource->addDocument('env/id', ['id' => 'd', 'signer_ids' => ['s']])],
            ['DELETE', '/documents/d%2Fid', fn () => $resource->removeDocument('env/id', 'd/id')],
            ['DELETE', '', fn () => $resource->delete('env/id')],
        ];
        foreach ($operations as [$method, $suffix, $action]) {
            $http->queueJson(200, []);
            $this->assertSame([], $action());
            $this->assertSame($method, $http->lastCall()['method']);
            $this->assertSame('accounts/acc/envelopes/env%2Fid' . $suffix, $http->lastCall()['uri']);
        }
        $http->queueRaw(200, 'ZIP', ['Content-Type' => ['application/zip']]);
        $this->assertSame('ZIP', $resource->download('env/id', 'bundle'));
        $this->assertSame('accounts/acc/envelopes/env%2Fid/download/bundle', $http->lastCall()['uri']);
    }

    public function testListsAndActivitiesRetainPagination(): void
    {
        $http = new FakeHttpClient();
        $resource = new EnvelopeResource($http, new Configuration('key', 'acc'));
        foreach ([false, true] as $activities) {
            $http->queueJson(200, [['id' => 'item']], ['X-Pagination-Total-Count' => ['25'],
                'X-Pagination-Current-Page' => ['2'], 'X-Pagination-Page-Count' => ['3'],
                'X-Pagination-Per-Page' => ['10']]);
            $result = $activities ? $resource->activities('env', 2, 10, 'id') : $resource->list(2, 10, ['status' => 'open']);
            $this->assertSame(25, $result['pagination']['total_count']);
            $this->assertSame([['id' => 'item']], $result['data']);
            $this->assertSame(2, $http->lastCall()['query']['page']);
        }
        $this->assertSame('id', $http->lastCall()['query']['sort']);
    }

    public function testMalformedBodiesFailBeforeNetwork(): void
    {
        $http = new FakeHttpClient();
        $resource = new EnvelopeResource($http, new Configuration('key', 'acc'));
        $valid = ['name' => 'Agreement', 'signers' => [['id' => 's']],
            'documents' => [['id' => 'd', 'signer_ids' => ['s']]]];
        $bad = [
            [], ['name' => '<script>'], ['name' => str_repeat('x', 256)], ['message' => []],
            ['signers' => []], ['signers' => [['id' => 's', 'step' => 1]]],
            ['signers' => [['id' => 's', 'notification_methods' => ['Email', 'Whatsapp']]]],
            ['documents' => [['id' => 'd', 'signer_ids' => []]]],
            ['documents' => [['id' => 'd', 'signer_ids' => ['s'], 'method' => 'collect']]],
        ];
        foreach ($bad as $index => $override) {
            try {
                $resource->create($index === 0 ? [] : array_replace($valid, $override));
                $this->fail('Expected validation failure');
            } catch (ValidationException) {
                $this->assertSame([], $http->calls);
            }
        }
    }
}
