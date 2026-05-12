<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Resources\TemplateResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class TemplateResourceTest extends TestCase
{
    public function testListAndGet(): void
    {
        $http = new FakeHttpClient();
        $templates = new TemplateResource($http, new Configuration('k', 'a'));

        $http->queueJson(200, [['id' => 't1']]);
        $templates->list(1, 10, ['status' => 'ready']);

        $call = $http->lastCall();
        $this->assertSame('accounts/a/templates', $call['uri']);
        $this->assertSame(['page' => 1, 'per-page' => 10, 'status' => 'ready'], $call['query']);

        $http->queueJson(200, ['id' => 't1', 'name' => 'NDA']);
        $tpl = $templates->get('t1');

        $this->assertSame('accounts/a/templates/t1', $http->lastCall()['uri']);
        $this->assertSame('NDA', $tpl['name']);
    }
}
