<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\SignerResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class SignerResourceTest extends TestCase
{
    private FakeHttpClient $http;
    private SignerResource $signers;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $config = new Configuration('key', 'acc');
        $this->signers = new SignerResource($this->http, $config);
    }

    public function testCreateSendsOnlyDocumentedFields(): void
    {
        $this->http->queueJson(201, [
            'id' => 's1',
            'full_name' => 'Alice',
            'email' => 'a@b.com',
            'whatsapp_phone_number' => '+5548999990000',
        ]);

        $result = $this->signers->create('Alice', 'a@b.com', '+5548999990000');

        $call = $this->http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('accounts/acc/signers', $call['uri']);
        $this->assertSame(
            ['full_name' => 'Alice', 'email' => 'a@b.com', 'whatsapp_phone_number' => '+5548999990000'],
            $call['body']
        );
        $this->assertArrayNotHasKey('cpf', $call['body']);
        $this->assertArrayNotHasKey('metadata', $call['body']);

        $this->assertSame('s1', $result['id']);
    }

    public function testCreateOmitsOptionalFields(): void
    {
        $this->http->queueJson(201, ['id' => 's2', 'full_name' => 'Bob']);

        $this->signers->create('Bob');

        $this->assertSame(['full_name' => 'Bob'], $this->http->lastCall()['body']);
    }

    public function testCreateRejectsEmptyName(): void
    {
        $this->expectException(ValidationException::class);
        $this->signers->create('');
    }

    public function testCreateRejectsInvalidEmail(): void
    {
        $this->expectException(ValidationException::class);
        $this->signers->create('Alice', 'not-an-email');
    }

    public function testCreateNormalizesPhoneToE164(): void
    {
        $this->http->queueJson(201, ['id' => 's3']);

        $this->signers->create('Alice', null, '(48) 99999-0000');

        $this->assertSame('+48999990000', $this->http->lastCall()['body']['whatsapp_phone_number']);
    }

    public function testListUsesHyphenatedPerPage(): void
    {
        $this->http->queueJson(200, []);
        $this->signers->list(3, 25, 'alice');

        $this->assertSame(
            ['page' => 3, 'per-page' => 25, 'search' => 'alice'],
            $this->http->lastCall()['query']
        );
    }

    public function testGetUpdateDeletePaths(): void
    {
        $this->http->queueJson(200, ['id' => 's1']);
        $this->signers->get('s1');
        $this->assertSame('accounts/acc/signers/s1', $this->http->lastCall()['uri']);

        $this->http->queueJson(200, ['id' => 's1', 'full_name' => 'New']);
        $this->signers->update('s1', ['full_name' => 'New']);
        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('accounts/acc/signers/s1', $call['uri']);
        $this->assertSame(['full_name' => 'New'], $call['body']);

        $this->http->queueJson(200, []);
        $this->signers->delete('s1');
        $this->assertSame('DELETE', $this->http->lastCall()['method']);
    }

    public function testFindByEmailReturnsExactMatch(): void
    {
        $this->http->queueJson(200, [
            ['id' => 's1', 'email' => 'OTHER@b.com'],
            ['id' => 's2', 'email' => 'Wanted@B.com'],
        ]);

        $hit = $this->signers->findByEmail('wanted@b.com');
        $this->assertNotNull($hit);
        $this->assertSame('s2', $hit['id']);
    }

    public function testFindByEmailReturnsNullWhenNotFound(): void
    {
        $this->http->queueJson(200, [['id' => 's1', 'email' => 'other@b.com']]);
        $this->assertNull($this->signers->findByEmail('missing@b.com'));
    }
}
