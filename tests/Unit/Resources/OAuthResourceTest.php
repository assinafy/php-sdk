<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Http\HttpClientInterface;
use Assinafy\SDK\Resources\OAuthResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class OAuthResourceTest extends TestCase
{
    private const REDIRECT = 'https://app.example.com/oauth/callback';

    private FakeHttpClient $http;
    private FakeHttpClient $discovery;
    /** @var array<int, string> */
    private array $discoveryOrigins = [];
    private OAuthResource $oauth;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->discovery = new FakeHttpClient();
        $this->discoveryOrigins = [];
        $this->oauth = $this->resource('client-id', 'client-secret');
    }

    private function resource(string $clientId, ?string $clientSecret): OAuthResource
    {
        return new OAuthResource(
            $this->http,
            Configuration::forPublic(),
            null,
            $clientId,
            $clientSecret,
            function (string $origin): HttpClientInterface {
                $this->discoveryOrigins[] = $origin;

                return $this->discovery;
            }
        );
    }

    /** Flat OAuth/OIDC bodies carry no `{status, message, data}` envelope. */
    private function queueFlat(int $status, array $body): void
    {
        $this->http->queueRaw($status, json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    private function authorizationQuery(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str(is_string($query) ? $query : '', $parsed);

        /** @var array<string, string> $parsed */
        return $parsed;
    }

    public function testConstructorRejectsEmptyClientId(): void
    {
        $this->expectException(ValidationException::class);
        $this->resource('  ', null);
    }

    public function testConstructorRejectsBlankSecret(): void
    {
        $this->expectException(ValidationException::class);
        $this->resource('client-id', '   ');
    }

    public function testDebugInfoHidesTheClientSecret(): void
    {
        $dumped = $this->oauth->__debugInfo();

        $this->assertSame('client-id', $dumped['client_id']);
        $this->assertSame('confidential', $dumped['client_type']);
        $this->assertStringNotContainsString('client-secret', print_r($dumped, true));
        $this->assertSame('public', $this->resource('client-id', null)->__debugInfo()['client_type']);
    }

    public function testStartAuthorizationBuildsThePkceAuthorizationUrl(): void
    {
        $start = $this->oauth->startAuthorization(self::REDIRECT, [
            OAuthResource::SCOPE_DOCUMENTS_READ,
            OAuthResource::SCOPE_DOCUMENTS_WRITE,
            OAuthResource::SCOPE_OFFLINE_ACCESS,
        ]);

        $this->assertStringStartsWith(
            'https://auth.assinafy.com.br/oauth/authorize?',
            $start['authorization_url']
        );

        $query = $this->authorizationQuery($start['authorization_url']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame(self::REDIRECT, $query['redirect_uri']);
        $this->assertSame('documents:read documents:write offline_access', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('https://api.assinafy.com.br', $query['resource']);
        $this->assertSame($start['state'], $query['state']);
        $this->assertSame($start['code_challenge'], $query['code_challenge']);

        // No HTTP call: the whole step is string construction.
        $this->assertSame([], $this->http->calls);
    }

    public function testStartAuthorizationDerivesTheChallengeFromTheVerifier(): void
    {
        $start = $this->oauth->startAuthorization(self::REDIRECT, [OAuthResource::SCOPE_ACCOUNT_READ]);

        $expected = rtrim(strtr(base64_encode(
            hash('sha256', $start['code_verifier'], true)
        ), '+/', '-_'), '=');

        $this->assertSame($expected, $start['code_challenge']);
        $this->assertSame($start['code_challenge'], OAuthResource::codeChallenge($start['code_verifier']));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]{43,128}$/D', $start['code_verifier']);
    }

    public function testStartAuthorizationMintsFreshMaterialEveryAttempt(): void
    {
        $first = $this->oauth->startAuthorization(self::REDIRECT, [OAuthResource::SCOPE_ACCOUNT_READ]);
        $second = $this->oauth->startAuthorization(self::REDIRECT, [OAuthResource::SCOPE_ACCOUNT_READ]);

        $this->assertNotSame($first['code_verifier'], $second['code_verifier']);
        $this->assertNotSame($first['state'], $second['state']);
    }

    public function testStartAuthorizationSendsANonceOnlyWhenOpenIdIsRequested(): void
    {
        $withoutOpenId = $this->oauth->startAuthorization(self::REDIRECT, [
            OAuthResource::SCOPE_DOCUMENTS_READ,
        ]);
        $this->assertArrayNotHasKey('nonce', $withoutOpenId);
        $this->assertArrayNotHasKey('nonce', $this->authorizationQuery($withoutOpenId['authorization_url']));

        $withOpenId = $this->oauth->startAuthorization(self::REDIRECT, [
            OAuthResource::SCOPE_OPENID,
            OAuthResource::SCOPE_EMAIL,
        ]);
        $this->assertNotSame('', $withOpenId['nonce']);
        $this->assertSame(
            $withOpenId['nonce'],
            $this->authorizationQuery($withOpenId['authorization_url'])['nonce']
        );
    }

    public function testStartAuthorizationAcceptsDiscoveredEndpointsAndSuppliedMaterial(): void
    {
        $verifier = OAuthResource::createCodeVerifier();
        $start = $this->oauth->startAuthorization(
            self::REDIRECT,
            [OAuthResource::SCOPE_ACCOUNT_READ],
            [
                'issuer' => 'https://auth.assinafy.com.br/',
                'authorization_endpoint' => 'https://auth.assinafy.com.br/oauth/authorize',
                'state' => 'supplied-state',
                'code_verifier' => $verifier,
                'resource' => 'https://api.assinafy.com.br',
            ]
        );

        $this->assertSame('supplied-state', $start['state']);
        $this->assertSame($verifier, $start['code_verifier']);
        $this->assertSame('https://auth.assinafy.com.br', $start['issuer']);
    }

    public function testStartAuthorizationRejectsUnusableRedirectUris(): void
    {
        foreach (
            [
                'http://app.example.com/callback',
                'https://app.example.com/callback#fragment',
                'not-a-url',
            ] as $redirectUri
        ) {
            try {
                $this->oauth->startAuthorization($redirectUri, [OAuthResource::SCOPE_ACCOUNT_READ]);
                $this->fail("Expected {$redirectUri} to be rejected");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Redirect URI', $e->getMessage());
            }
        }
    }

    public function testStartAuthorizationRejectsUnusableScopes(): void
    {
        $this->expectException(ValidationException::class);
        $this->oauth->startAuthorization(self::REDIRECT, []);
    }

    public function testStartAuthorizationRejectsAScopeContainingASpace(): void
    {
        $this->expectException(ValidationException::class);
        $this->oauth->startAuthorization(self::REDIRECT, ['documents:read documents:write']);
    }

    public function testStartAuthorizationRejectsAnOutOfGrammarVerifier(): void
    {
        $this->expectException(ValidationException::class);
        $this->oauth->startAuthorization(
            self::REDIRECT,
            [OAuthResource::SCOPE_ACCOUNT_READ],
            ['code_verifier' => 'too-short']
        );
    }

    public function testHandleCallbackReturnsTheCode(): void
    {
        $start = $this->oauth->startAuthorization(self::REDIRECT, [OAuthResource::SCOPE_ACCOUNT_READ]);

        $code = $this->oauth->handleCallback([
            'code' => 'authorization-code',
            'state' => $start['state'],
            'iss' => 'https://auth.assinafy.com.br',
        ], $start);

        $this->assertSame('authorization-code', $code);
    }

    public function testHandleCallbackRejectsAMismatchedState(): void
    {
        $start = $this->oauth->startAuthorization(self::REDIRECT, [OAuthResource::SCOPE_ACCOUNT_READ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('state does not match');
        $this->oauth->handleCallback([
            'code' => 'authorization-code',
            'state' => 'another-attempt',
            'iss' => 'https://auth.assinafy.com.br',
        ], $start);
    }

    public function testHandleCallbackRejectsAMissingOrForeignIssuer(): void
    {
        $start = $this->oauth->startAuthorization(self::REDIRECT, [OAuthResource::SCOPE_ACCOUNT_READ]);

        foreach ([null, 'https://auth.example.com'] as $issuer) {
            $query = ['code' => 'authorization-code', 'state' => $start['state']];
            if ($issuer !== null) {
                $query['iss'] = $issuer;
            }

            try {
                $this->oauth->handleCallback($query, $start);
                $this->fail('Expected the callback issuer to be rejected');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('issuer', $e->getMessage());
            }
        }
    }

    public function testHandleCallbackRaisesTheAuthorizationServerError(): void
    {
        $start = $this->oauth->startAuthorization(self::REDIRECT, [OAuthResource::SCOPE_ACCOUNT_READ]);

        try {
            $this->oauth->handleCallback([
                'error' => 'access_denied',
                'error_description' => 'The user declined',
                'state' => $start['state'],
                'iss' => 'https://auth.assinafy.com.br',
            ], $start);
            $this->fail('Expected a declined authorization to raise');
        } catch (ApiException $e) {
            $this->assertSame('access_denied', $e->getMessage());
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('The user declined', $e->getResponseData()['error_description']);
        }
    }

    public function testHandleCallbackRejectsAResponseWithoutACode(): void
    {
        $start = $this->oauth->startAuthorization(self::REDIRECT, [OAuthResource::SCOPE_ACCOUNT_READ]);

        $this->expectException(ValidationException::class);
        $this->oauth->handleCallback([
            'state' => $start['state'],
            'iss' => 'https://auth.assinafy.com.br',
        ], $start);
    }

    public function testExchangeCodePostsAFormEncodedAuthorizationCodeGrant(): void
    {
        $start = $this->oauth->startAuthorization(self::REDIRECT, [OAuthResource::SCOPE_DOCUMENTS_READ]);
        $this->queueFlat(200, [
            'access_token' => 'access',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => 'documents:read',
            'refresh_token' => 'refresh',
        ]);

        $tokens = $this->oauth->exchangeCode('authorization-code', $start);

        $call = $this->http->lastCall();
        $this->assertSame('POST_RAW', $call['method']);
        $this->assertSame('oauth/token', $call['uri']);
        $this->assertSame('application/x-www-form-urlencoded', $call['content_type']);

        parse_str($call['body'], $body);
        $this->assertSame([
            'grant_type' => 'authorization_code',
            'code' => 'authorization-code',
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $start['code_verifier'],
            'resource' => 'https://api.assinafy.com.br',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
        ], $body);

        // Flat JSON: returned as-is, never unwrapped from a `data` envelope.
        $this->assertSame('access', $tokens['access_token']);
        $this->assertSame('refresh', $tokens['refresh_token']);
    }

    public function testPublicClientsExchangeWithoutASecret(): void
    {
        $oauth = $this->resource('public-client', null);
        $start = $oauth->startAuthorization(self::REDIRECT, [OAuthResource::SCOPE_DOCUMENTS_READ]);
        $this->queueFlat(200, ['access_token' => 'access']);

        $oauth->exchangeCode('authorization-code', $start);

        parse_str($this->http->lastCall()['body'], $body);
        $this->assertArrayNotHasKey('client_secret', $body);
        $this->assertSame('public-client', $body['client_id']);
    }

    public function testExchangeCodeRejectsAnIncompleteTransaction(): void
    {
        $this->expectException(ValidationException::class);
        $this->oauth->exchangeCode('authorization-code', ['redirect_uri' => self::REDIRECT]);
    }

    public function testExchangeCodeRejectsAnEmptyCode(): void
    {
        $this->expectException(ValidationException::class);
        $this->oauth->exchangeCode('  ', [
            'code_verifier' => OAuthResource::createCodeVerifier(),
            'redirect_uri' => self::REDIRECT,
        ]);
    }

    public function testRefreshPostsAFormEncodedRefreshGrant(): void
    {
        $this->queueFlat(200, ['access_token' => 'new-access', 'refresh_token' => 'new-refresh']);

        $renewed = $this->oauth->refresh('current-refresh');

        parse_str($this->http->lastCall()['body'], $body);
        $this->assertSame([
            'grant_type' => 'refresh_token',
            'refresh_token' => 'current-refresh',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
        ], $body);
        $this->assertSame('new-refresh', $renewed['refresh_token']);
    }

    public function testRefreshRejectsAnEmptyToken(): void
    {
        $this->expectException(ValidationException::class);
        $this->oauth->refresh('');
    }

    public function testRevokePostsTheTokenAndOptionalHint(): void
    {
        $this->http->queueRaw(200, '');
        $this->assertSame([], $this->oauth->revoke('refresh', OAuthResource::TOKEN_TYPE_HINT_REFRESH));

        $call = $this->http->lastCall();
        $this->assertSame('oauth/revoke', $call['uri']);
        parse_str($call['body'], $body);
        $this->assertSame([
            'token' => 'refresh',
            'token_type_hint' => 'refresh_token',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
        ], $body);

        $this->http->queueRaw(200, '');
        $this->oauth->revoke('access');
        parse_str($this->http->lastCall()['body'], $withoutHint);
        $this->assertArrayNotHasKey('token_type_hint', $withoutHint);
    }

    public function testRevokeRejectsAnUnknownHint(): void
    {
        $this->expectException(ValidationException::class);
        $this->oauth->revoke('token', 'id_token');
    }

    public function testUserinfoSendsTheTokenAsABearerHeader(): void
    {
        $this->queueFlat(200, [
            'sub' => 'user-id',
            'name' => 'Example User',
            'email' => 'person@example.com',
            'email_verified' => true,
        ]);

        $claims = $this->oauth->userinfo('access-token');

        $call = $this->http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('oauth/userinfo', $call['uri']);
        $this->assertSame(['Authorization' => 'Bearer access-token'], $call['headers']);
        $this->assertSame([], $call['query']);
        $this->assertSame('user-id', $claims['sub']);
    }

    public function testUserinfoRejectsAnEmptyToken(): void
    {
        $this->expectException(ValidationException::class);
        $this->oauth->userinfo('   ');
    }

    public function testProtectedResourceMetadataIsFetchedFromTheApiOrigin(): void
    {
        $this->discovery->queueRaw(200, json_encode([
            'resource' => 'https://api.assinafy.com.br',
            'authorization_servers' => ['https://auth.assinafy.com.br'],
        ], JSON_THROW_ON_ERROR));

        $metadata = $this->oauth->protectedResourceMetadata();

        $this->assertSame(['https://api.assinafy.com.br'], $this->discoveryOrigins);
        $this->assertSame('.well-known/oauth-protected-resource', $this->discovery->lastCall()['uri']);
        $this->assertSame('https://auth.assinafy.com.br', $metadata['authorization_servers'][0]);
    }

    public function testProtectedResourceMetadataFollowsTheConfiguredEnvironment(): void
    {
        $sandbox = new OAuthResource(
            $this->http,
            Configuration::forPublic(Configuration::SANDBOX_BASE_URL),
            null,
            'client-id',
            null,
            function (string $origin): HttpClientInterface {
                $this->discoveryOrigins[] = $origin;

                return $this->discovery;
            }
        );
        $this->discovery->queueRaw(200, '{}');

        $sandbox->protectedResourceMetadata();

        $this->assertSame(['https://sandbox.assinafy.com.br'], $this->discoveryOrigins);
    }

    public function testAuthorizationServerMetadataIsFetchedFromTheIssuer(): void
    {
        $this->discovery->queueRaw(200, json_encode([
            'issuer' => 'https://auth.assinafy.com.br',
            'authorization_endpoint' => 'https://auth.assinafy.com.br/oauth/authorize',
        ], JSON_THROW_ON_ERROR));

        $metadata = $this->oauth->authorizationServerMetadata();

        $this->assertSame(['https://auth.assinafy.com.br'], $this->discoveryOrigins);
        $this->assertSame('.well-known/oauth-authorization-server', $this->discovery->lastCall()['uri']);
        $this->assertSame('https://auth.assinafy.com.br', $metadata['issuer']);
    }

    public function testAuthorizationServerMetadataRejectsANonHttpsIssuer(): void
    {
        $this->expectException(ValidationException::class);
        $this->oauth->authorizationServerMetadata('http://auth.example.com');
    }
}
