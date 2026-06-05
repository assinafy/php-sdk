<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\TemplateResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class TemplateResourceTest extends TestCase
{
    private function resource(FakeHttpClient $http): TemplateResource
    {
        return new TemplateResource($http, new Configuration('k', 'a'));
    }

    public function testListAndGet(): void
    {
        $http = new FakeHttpClient();
        $templates = $this->resource($http);

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

    public function testCreateUploadsPdfToTemplatesEndpoint(): void
    {
        $http = new FakeHttpClient();
        $templates = $this->resource($http);

        $pdf = tempnam(sys_get_temp_dir(), 'tpl-') . '.pdf';
        file_put_contents($pdf, '%PDF-1.4');

        $http->queueJson(200, ['id' => 'tpl-1', 'status' => 'Uploaded']);
        $created = $templates->create($pdf);

        $call = $http->lastCall();
        $this->assertSame('UPLOAD', $call['method']);
        $this->assertSame('accounts/a/templates', $call['uri']);
        $this->assertSame($pdf, $call['file_path']);
        $this->assertSame('tpl-1', $created['id']);

        unlink($pdf);
    }

    public function testCreateRejectsNonPdf(): void
    {
        $http = new FakeHttpClient();
        $templates = $this->resource($http);

        $txt = tempnam(sys_get_temp_dir(), 'tpl-') . '.txt';
        file_put_contents($txt, 'not a pdf');

        $this->expectException(ValidationException::class);
        try {
            $templates->create($txt);
        } finally {
            unlink($txt);
        }
    }

    public function testUpdateSendsEditableFields(): void
    {
        $http = new FakeHttpClient();
        $templates = $this->resource($http);

        $http->queueJson(200, ['id' => 't1', 'document_name' => 'Renamed']);
        $updated = $templates->update('t1', ['document_name' => 'Renamed', 'message' => 'hi']);

        $call = $http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('accounts/a/templates/t1', $call['uri']);
        $this->assertSame(['document_name' => 'Renamed', 'message' => 'hi'], $call['body']);
        $this->assertSame('Renamed', $updated['document_name']);
    }

    public function testDelete(): void
    {
        $http = new FakeHttpClient();
        $templates = $this->resource($http);

        $http->queueJson(200, []);
        $templates->delete('t1');

        $call = $http->lastCall();
        $this->assertSame('DELETE', $call['method']);
        $this->assertSame('accounts/a/templates/t1', $call['uri']);
    }

    public function testDownloadPageReturnsRawBody(): void
    {
        $http = new FakeHttpClient();
        $templates = $this->resource($http);

        $http->queueRaw(200, 'JPEGBYTES');
        $body = $templates->downloadPage('t1', 'p1');

        $call = $http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('accounts/a/templates/t1/pages/p1/download', $call['uri']);
        $this->assertSame('JPEGBYTES', $body);
    }
}
