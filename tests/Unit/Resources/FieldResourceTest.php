<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\FieldResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class FieldResourceTest extends TestCase
{
    private FakeHttpClient $http;
    private FieldResource $fields;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->fields = new FieldResource($this->http, new Configuration('key', 'acc'));
    }

    public function testCreateMergesOptions(): void
    {
        $this->http->queueJson(200, ['id' => 'f1', 'type' => 'text', 'name' => 'CPF']);

        $this->fields->create('text', 'CPF', ['regex' => '/x/', 'is_required' => false]);

        $call = $this->http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('accounts/acc/fields', $call['uri']);
        $this->assertSame(
            ['type' => 'text', 'name' => 'CPF', 'regex' => '/x/', 'is_required' => false],
            $call['body']
        );
    }

    public function testCreateRejectsEmptyType(): void
    {
        $this->expectException(ValidationException::class);
        $this->fields->create('', 'Name');
    }

    public function testCreateRejectsEmptyName(): void
    {
        $this->expectException(ValidationException::class);
        $this->fields->create('text', '');
    }

    public function testListSendsBooleanFlagsOnlyWhenTrue(): void
    {
        $this->http->queueJson(200, []);
        $this->fields->list(true, true);
        $this->assertSame(
            ['include_inactive' => 'true', 'include_standard' => 'true'],
            $this->http->lastCall()['query']
        );

        $this->http->queueJson(200, []);
        $this->fields->list();
        $this->assertSame([], $this->http->lastCall()['query']);
    }

    public function testGetUpdateDeletePaths(): void
    {
        $this->http->queueJson(200, ['id' => 'f1']);
        $this->fields->get('f1');
        $this->assertSame('accounts/acc/fields/f1', $this->http->lastCall()['uri']);

        $this->http->queueJson(200, ['id' => 'f1', 'name' => 'New']);
        $this->fields->update('f1', ['name' => 'New']);
        $put = $this->http->lastCall();
        $this->assertSame('PUT', $put['method']);
        $this->assertSame('accounts/acc/fields/f1', $put['uri']);

        $this->http->queueJson(200, []);
        $this->fields->delete('f1');
        $this->assertSame('DELETE', $this->http->lastCall()['method']);
    }

    public function testValidateAsUserSendsNoAccessCode(): void
    {
        $this->http->queueJson(200, ['success' => true]);

        $this->fields->validate('f1', '400.676.228-36');

        $call = $this->http->lastCall();
        $this->assertSame('accounts/acc/fields/f1/validate', $call['uri']);
        $this->assertSame(['value' => '400.676.228-36'], $call['body']);
        $this->assertSame([], $call['query']);
    }

    public function testValidateAsSignerSendsAccessCode(): void
    {
        $this->http->queueJson(200, ['success' => true]);
        $this->fields->validate('f1', 'x', 'CODE');
        $this->assertSame(['signer-access-code' => 'CODE'], $this->http->lastCall()['query']);
    }

    public function testValidateMultipleSendsArrayBody(): void
    {
        $this->http->queueJson(200, []);

        $values = [
            ['field_id' => 'f1', 'value' => '1'],
            ['field_id' => 'f2', 'value' => 'a@b.com'],
        ];
        $this->fields->validateMultiple($values, 'CODE');

        $call = $this->http->lastCall();
        $this->assertSame('accounts/acc/fields/validate-multiple', $call['uri']);
        $this->assertSame($values, $call['body']);
        $this->assertSame(['signer-access-code' => 'CODE'], $call['query']);
    }

    public function testTypesHitsGlobalEndpoint(): void
    {
        $this->http->queueJson(200, [['type' => 'text', 'name' => 'Text']]);
        $result = $this->fields->types();
        $this->assertSame('field-types', $this->http->lastCall()['uri']);
        $this->assertSame('text', $result[0]['type']);
    }
}
