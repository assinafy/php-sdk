<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\TagResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class TagResourceTest extends TestCase
{
    private FakeHttpClient $http;
    private TagResource $tags;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->tags = new TagResource($this->http, new Configuration('key', 'acc'));
    }

    public function testListUnwrapsDataAndOmitsEmptySearch(): void
    {
        $this->http->queueJson(200, [['id' => 't1', 'name' => 'Contracts']]);

        $result = $this->tags->list();

        $call = $this->http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('accounts/acc/tags', $call['uri']);
        $this->assertSame([], $call['query']);
        $this->assertSame('t1', $result[0]['id']);
    }

    public function testListPassesSearch(): void
    {
        $this->http->queueJson(200, []);
        $this->tags->list('contract');
        $this->assertSame(['search' => 'contract'], $this->http->lastCall()['query']);
    }

    public function testCreateSendsNameAndColor(): void
    {
        $this->http->queueJson(200, ['id' => 't1', 'name' => 'Contracts', 'color' => 'ff8800']);

        $this->tags->create('Contracts', 'ff8800');

        $call = $this->http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('accounts/acc/tags', $call['uri']);
        $this->assertSame(['name' => 'Contracts', 'color' => 'ff8800'], $call['body']);
    }

    public function testCreateOmitsColorWhenNull(): void
    {
        $this->http->queueJson(200, ['id' => 't1']);
        $this->tags->create('Contracts');
        $this->assertSame(['name' => 'Contracts'], $this->http->lastCall()['body']);
    }

    public function testCreateRejectsBlankName(): void
    {
        $this->expectException(ValidationException::class);
        $this->tags->create('   ');
    }

    public function testUpdatePathAndBody(): void
    {
        $this->http->queueJson(200, ['id' => 't1', 'name' => 'New']);

        $this->tags->update('t1', ['name' => 'New', 'color' => null]);

        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('accounts/acc/tags/t1', $call['uri']);
        $this->assertSame(['name' => 'New', 'color' => null], $call['body']);
    }

    public function testUpdateRejectsEmptyPayload(): void
    {
        $this->expectException(ValidationException::class);
        $this->tags->update('t1', []);
    }

    public function testDeleteWithoutForce(): void
    {
        $this->http->queueJson(200, ['deleted' => true]);
        $this->tags->delete('t1');

        $call = $this->http->lastCall();
        $this->assertSame('DELETE', $call['method']);
        $this->assertSame('accounts/acc/tags/t1', $call['uri']);
        $this->assertSame([], $call['query']);
    }

    public function testDeleteWithForceSendsQuery(): void
    {
        $this->http->queueJson(200, ['deleted' => true]);
        $this->tags->delete('t1', true);
        $this->assertSame(['force' => 'true'], $this->http->lastCall()['query']);
    }
}
