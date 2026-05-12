<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Resources\AuthResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AuthResourceTest extends TestCase
{
    private FakeHttpClient $http;
    private AuthResource $auth;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->auth = new AuthResource($this->http, new Configuration('k', 'a'));
    }

    public function testLogin(): void
    {
        $this->http->queueJson(200, ['access_token' => 'tok', 'user' => ['id' => 'u1']]);
        $result = $this->auth->login('a@b.com', 'secret');

        $call = $this->http->lastCall();
        $this->assertSame('login', $call['uri']);
        $this->assertSame(['email' => 'a@b.com', 'password' => 'secret'], $call['body']);
        $this->assertSame('tok', $result['access_token']);
    }

    public function testSocialLogin(): void
    {
        $this->http->queueJson(200, ['access_token' => 'tok']);
        $this->auth->socialLogin('google', 'gtoken', true);

        $this->assertSame('authentication/social-login', $this->http->lastCall()['uri']);
        $this->assertSame([
            'provider' => 'google',
            'token' => 'gtoken',
            'has_accepted_terms' => true,
        ], $this->http->lastCall()['body']);
    }

    public function testApiKeyLifecycleUsesBearerHeader(): void
    {
        $this->http->queueJson(201, ['api_key' => 'k']);
        $this->auth->generateApiKey('TOKEN', 'pw');
        $call = $this->http->lastCall();
        $this->assertSame('users/api-keys', $call['uri']);
        $this->assertSame(['Authorization' => 'Bearer TOKEN'], $call['headers']);

        $this->http->queueJson(200, ['api_key' => 'k***']);
        $this->auth->getApiKey('TOKEN');
        $this->assertSame('GET', $this->http->lastCall()['method']);

        $this->http->queueJson(200, []);
        $this->auth->deleteApiKey('TOKEN');
        $this->assertSame('DELETE', $this->http->lastCall()['method']);
    }

    public function testChangePassword(): void
    {
        $this->http->queueJson(200, []);
        $this->auth->changePassword('TOKEN', 'a@b.com', 'old', 'new');

        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('authentication/change-password', $call['uri']);
        $this->assertSame([
            'email' => 'a@b.com',
            'password' => 'old',
            'new_password' => 'new',
        ], $call['body']);
        $this->assertSame(['Authorization' => 'Bearer TOKEN'], $call['headers']);
    }

    public function testRequestAndResetPassword(): void
    {
        $this->http->queueJson(200, []);
        $this->auth->requestPasswordReset('a@b.com');
        $this->assertSame('authentication/request-password-reset', $this->http->lastCall()['uri']);

        $this->http->queueJson(200, []);
        $this->auth->resetPassword('a@b.com', 'token', 'newpw');
        $this->assertSame('authentication/reset-password', $this->http->lastCall()['uri']);
        $this->assertSame(
            ['email' => 'a@b.com', 'token' => 'token', 'new_password' => 'newpw'],
            $this->http->lastCall()['body']
        );
    }
}
