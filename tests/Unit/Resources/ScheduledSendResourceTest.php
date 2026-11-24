<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ScheduledSendResourceTest extends TestCase
{
    public function testAssignmentSchedulePreservesCapacityAndUnixTimes(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(new Configuration('key', 'account'), $http);
        $http->queueJson(200, ['id' => 'send', 'status' => 'pending']);
        $result = $client->assignments()->schedule('doc/id', [3 => [
            'id' => 'signer', 'sign_as' => 'Witness', 'notification_methods' => [4 => 'Email'],
        ]], 1800000000, options: ['expires_at' => 1800010000, 'send_at' => 1, 'copy_receivers' => [4 => 'copy']]);
        self::assertSame(['id' => 'send', 'status' => 'pending'], $result);
        self::assertSame('documents/doc%2Fid/scheduled-sends', $http->lastCall()['uri']);
        self::assertSame('POST', $http->lastCall()['method']);
        self::assertSame([
            'method' => 'virtual',
            'signers' => [['id' => 'signer', 'notification_methods' => ['Email'], 'sign_as' => 'Witness']],
            'send_at' => 1800000000, 'copy_receivers' => ['copy'], 'expires_at' => 1800010000,
        ], $http->lastCall()['body']);
    }

    public function testEnvelopeScheduleUsesTheCreationRulesAndReindexesLists(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(new Configuration('key', 'account'), $http);
        $http->queueJson(200, ['id' => 'send']);
        $client->envelopes()->schedule([
            'name' => 'Agreement', 'signers' => [2 => ['id' => 'signer']],
            'documents' => [8 => ['id' => 'doc', 'signer_ids' => [6 => 'signer']]],
            'max_total_credits' => 0,
        ], '2027-01-02T12:00:00Z');
        self::assertSame('accounts/account/scheduled-sends', $http->lastCall()['uri']);
        self::assertSame([
            'name' => 'Agreement', 'signers' => [['id' => 'signer']],
            'documents' => [['id' => 'doc', 'signer_ids' => ['signer']]],
            'max_total_credits' => 0, 'send_at' => '2027-01-02T12:00:00Z',
        ], $http->lastCall()['body']);
        self::assertSame([], $http->lastCall()['headers']);
    }

    public function testInvalidSchedulesMakeNoRequest(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(new Configuration('key', 'account'), $http);
        $operations = [
            fn () => $client->assignments()->schedule('doc', ['signer'], 'tomorrow'),
            fn () => $client->assignments()->schedule('doc', [], 1800000000),
            fn () => $client->assignments()->schedule('doc', ['signer'], 1800000000, 'collect'),
            fn () => $client->assignments()->schedule('doc', [['id' => 'signer', 'sign_as' => 'witness']], 1800000000),
            fn () => $client->envelopes()->schedule(['name' => 'Agreement'], 1800000000),
            fn () => $client->envelopes()->schedule([], '2027-02-30'),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Invalid schedule accepted');
            } catch (ValidationException) {
                self::assertSame([], $http->calls);
            }
        }
    }

    public function testListAndActionsPreservePaginationAndResponse(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(new Configuration('key', 'account'), $http);
        self::assertSame($client->scheduledSends(), $client->scheduledSends());
        $http->queueJson(200, [['id' => 'send']], [
            'X-Pagination-Current-Page' => ['2'], 'X-Pagination-Page-Count' => ['3'],
            'X-Pagination-Per-Page' => ['1'], 'X-Pagination-Total-Count' => ['3'],
        ]);
        $result = $client->scheduledSends()->list(2, 1, 'doc');
        self::assertSame(2, $result['pagination']['current_page']);
        self::assertSame(['page' => 2, 'per-page' => 1, 'document_id' => 'doc'], $http->lastCall()['query']);
        foreach (['cancel' => 'cancel', 'sendNow' => 'send'] as $method => $route) {
            $http->queueJson(200, ['id' => 'send']);
            self::assertSame(['id' => 'send'], $client->scheduledSends()->{$method}('send/id'));
            self::assertSame('PUT', $http->lastCall()['method']);
            self::assertSame('accounts/account/scheduled-sends/send%2Fid/' . $route, $http->lastCall()['uri']);
            self::assertNull($http->lastCall()['body']);
        }
    }
}
