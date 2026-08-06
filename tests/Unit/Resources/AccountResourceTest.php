<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\AccountResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AccountResourceTest extends TestCase
{
    private FakeHttpClient $http;
    private AccountResource $accounts;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->accounts = new AccountResource($this->http, new Configuration('key', 'acc'));
    }

    public function testListHitsUnscopedAccountsPath(): void
    {
        $this->http->queueJson(200, [['id' => 'acc', 'name' => 'MT', 'roles' => ['owner']]]);

        $result = $this->accounts->list();

        $call = $this->http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('accounts', $call['uri'], 'list() must NOT be account-scoped');
        $this->assertSame('MT', $result['data'][0]['name']);
    }

    public function testListWorksOnAPublicClientWithBearerToken(): void
    {
        $accounts = new AccountResource($this->http, Configuration::forPublic());
        $this->http->queueJson(200, [['id' => 'acc']]);

        $accounts->list('access-token');

        $this->assertSame('accounts', $this->http->lastCall()['uri']);
        $this->assertSame(
            ['Authorization' => 'Bearer access-token'],
            $this->http->lastCall()['headers']
        );
    }

    public function testListRejectsUnauthenticatedPublicClient(): void
    {
        $accounts = new AccountResource($this->http, Configuration::forPublic());

        $this->expectException(ValidationException::class);
        $accounts->list();
    }

    public function testAccountScopedCallsRejectAPublicClient(): void
    {
        $accounts = new AccountResource($this->http, Configuration::forPublic());

        $this->expectException(RuntimeException::class);
        $accounts->get();
    }

    public function testGetHitsAccountScopedPathAndUnwrapsData(): void
    {
        $this->http->queueJson(200, ['id' => 'acc', 'name' => 'MT', 'primary_color' => null]);

        $result = $this->accounts->get();

        $call = $this->http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('accounts/acc', $call['uri']);
        $this->assertSame('MT', $result['name']);
    }

    public function testStatsUsesConfiguredAccountAndGranularity(): void
    {
        $this->http->queueJson(200, [['period' => '2026-08', 'documents_uploaded' => 2]]);

        $result = $this->accounts->stats();

        $this->assertSame('accounts/acc/stats', $this->http->lastCall()['uri']);
        $this->assertSame(['granularity' => 'monthly'], $this->http->lastCall()['query']);
        $this->assertSame(2, $result[0]['documents_uploaded']);
    }

    public function testCreateSendsNameOnlyByDefault(): void
    {
        $this->http->queueJson(201, ['id' => 'new']);

        $this->accounts->create('Acme Inc.');

        $call = $this->http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('accounts', $call['uri']);
        $this->assertSame(['name' => 'Acme Inc.'], $call['body']);
    }

    public function testCreateForwardsNotificationSenderType(): void
    {
        $this->http->queueJson(201, ['id' => 'new']);

        $this->accounts->create('Acme Inc.', AccountResource::NOTIFICATION_SENDER_ACCOUNT);

        $this->assertSame(
            ['name' => 'Acme Inc.', 'notification_sender_type' => 'Account'],
            $this->http->lastCall()['body']
        );
    }

    public function testCreateRejectsUnknownNotificationSenderType(): void
    {
        $this->expectException(ValidationException::class);
        $this->accounts->create('Acme Inc.', 'account'); // lowercase — the API is PascalCase
    }

    public function testUpdateSendsOnlySuppliedFields(): void
    {
        $this->http->queueJson(200, ['id' => 'acc']);

        $this->accounts->update('Renamed');

        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('accounts/acc', $call['uri']);
        $this->assertSame(['name' => 'Renamed'], $call['body']);
    }

    public function testUpdateWithNothingToChangeThrowsBeforeAnyRequest(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->accounts->update();
        } finally {
            $this->assertSame([], $this->http->calls, 'No HTTP request should be attempted');
        }
    }

    public function testDeleteSendsNoBodyWhenNotForced(): void
    {
        $this->http->queueJson(200, []);

        $this->accounts->delete();

        $call = $this->http->lastCall();
        $this->assertSame('DELETE', $call['method']);
        $this->assertSame('accounts/acc', $call['uri']);
        $this->assertSame([], $call['body']);
    }

    /**
     * The documented schema puts `force` in a JSON body rather than the query string.
     * Deliberately not exercised live — it would have destroyed the sandbox workspace.
     */
    public function testDeleteSendsForceInTheJsonBody(): void
    {
        $this->http->queueJson(200, []);

        $this->accounts->delete(true);

        $call = $this->http->lastCall();
        $this->assertSame(['force' => true], $call['body']);
        $this->assertSame([], $call['query']);
    }

    public function testThemeUnwrapsData(): void
    {
        $this->http->queueJson(200, ['account_name' => 'MT', 'primary_color' => '2072b9', 'logo' => null]);

        $result = $this->accounts->theme();

        $this->assertSame('accounts/acc/theme', $this->http->lastCall()['uri']);
        $this->assertSame('2072b9', $result['primary_color']);
    }

    public function testDownloadLogoReturnsRawBinaryBody(): void
    {
        $this->http->queueRaw(200, "\x89PNG\r\n\x1a\nbinary");

        $result = $this->accounts->downloadLogo();

        $this->assertSame('accounts/acc/logo', $this->http->lastCall()['uri']);
        $this->assertSame("\x89PNG\r\n\x1a\nbinary", $result, 'Binary body must not be JSON-decoded');
    }

    public function testUploadLogoSendsMultipart(): void
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'logo');
        $this->assertIsString($temporaryPath);
        $png = $temporaryPath . '.png';
        rename($temporaryPath, $png);
        file_put_contents($png, 'fake-png');

        $this->http->queueJson(200, ['logo' => 'https://example.com/logo.png']);

        try {
            $this->accounts->uploadLogo($png);

            $call = $this->http->lastCall();
            $this->assertSame('UPLOAD', $call['method']);
            $this->assertSame('accounts/acc/logo', $call['uri']);
            $this->assertSame($png, $call['file_path']);
        } finally {
            @unlink($png);
        }
    }

    public function testDeleteLogo(): void
    {
        $this->http->queueJson(200, []);

        $this->accounts->deleteLogo();

        $call = $this->http->lastCall();
        $this->assertSame('DELETE', $call['method']);
        $this->assertSame('accounts/acc/logo', $call['uri']);
    }
}
