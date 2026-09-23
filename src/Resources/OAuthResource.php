<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Http\GuzzleHttpClient;
use Assinafy\SDK\Http\HttpClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Marketplace OAuth 2.1 resource — the authorization-code + PKCE flow an application
 * uses to act on **another** workspace, plus `/oauth/token`, `/oauth/revoke`,
 * `/oauth/userinfo` and the two discovery documents.
 *
 * Automating your own workspace needs none of this: keep using an API key.
 *
 * Two hosts are involved on purpose. The browser-facing authorization page lives on
 * the authorization server (`https://auth.assinafy.com.br`); the token, revocation and
 * userinfo endpoints live on this API under `/v1`. The discovery documents sit at the
 * origin of each host, outside `/v1`, so they are fetched with short-lived public
 * clients built for that origin — see {@see self::authorizationServerMetadata()}.
 *
 * Every response on this resource is **flat JSON**: RFC 6749 §5.1/§5.2, RFC 8414 and
 * OIDC Core §5.3.2 all forbid the `{ status, message, data }` envelope the rest of the
 * API uses, so these methods return the decoded body as-is rather than unwrapping it.
 *
 * Failures raise {@see ApiException}. On the token and revocation endpoints — and on a
 * callback error surfaced by {@see self::handleCallback()} — `getMessage()` is the RFC
 * error code (`invalid_grant`, `invalid_client`, `access_denied`, …) and
 * `getResponseData()` carries `error_description`, so branch on the message. Userinfo is
 * the exception: it authenticates like any other API route, so its `401`/`403` arrive in
 * the ordinary `{ status, message, data }` envelope and `getMessage()` is the API's own
 * message. Log neither.
 *
 * ```php
 * $oauth = AssinafyClient::forAuth()->oauth($clientId, $clientSecret);
 *
 * // 1. Send the browser to Assinafy, keeping the transaction in the user's session.
 * $start = $oauth->startAuthorization('https://app.example.com/callback', [
 *     OAuthResource::SCOPE_DOCUMENTS_READ,
 *     OAuthResource::SCOPE_DOCUMENTS_WRITE,
 *     OAuthResource::SCOPE_OFFLINE_ACCESS,
 * ]);
 * $_SESSION['assinafy_oauth'] = $start;
 * header('Location: ' . $start['authorization_url']);
 *
 * // 2. On the redirect URI, validate the callback and exchange the code.
 * $transaction = $_SESSION['assinafy_oauth'];
 * unset($_SESSION['assinafy_oauth']);
 * $code = $oauth->handleCallback($_GET, $transaction);
 * $tokens = $oauth->exchangeCode($code, $transaction);
 * ```
 *
 * The SDK does not store tokens, hold refresh locks, or renew anything automatically.
 * Those are application concerns — see `docs/OAUTH.md`.
 *
 * @see https://api.assinafy.com.br/v1/docs#tag/OAuth-Integration-Guide
 */
class OAuthResource extends AbstractResource
{
    /** The authorization server that owns the browser-facing consent page. */
    public const DEFAULT_ISSUER = 'https://auth.assinafy.com.br';

    /** RFC 8414 metadata path, served by the authorization server's origin. */
    public const AUTHORIZATION_SERVER_METADATA_PATH = '.well-known/oauth-authorization-server';

    /** RFC 9728 metadata path, served by this API's origin. */
    public const PROTECTED_RESOURCE_METADATA_PATH = '.well-known/oauth-protected-resource';

    /** The only code challenge method the authorization server accepts. */
    public const CODE_CHALLENGE_METHOD = 'S256';

    public const GRANT_AUTHORIZATION_CODE = 'authorization_code';
    public const GRANT_REFRESH_TOKEN = 'refresh_token';

    public const TOKEN_TYPE_HINT_ACCESS = 'access_token';
    public const TOKEN_TYPE_HINT_REFRESH = 'refresh_token';

    public const SCOPE_DOCUMENTS_READ = 'documents:read';
    public const SCOPE_DOCUMENTS_WRITE = 'documents:write';
    public const SCOPE_TEMPLATES_READ = 'templates:read';
    public const SCOPE_TEMPLATES_WRITE = 'templates:write';
    public const SCOPE_ACCOUNT_READ = 'account:read';
    public const SCOPE_WEBHOOKS_WRITE = 'webhooks:write';
    public const SCOPE_OPENID = 'openid';
    public const SCOPE_PROFILE = 'profile';
    public const SCOPE_EMAIL = 'email';
    public const SCOPE_OFFLINE_ACCESS = 'offline_access';

    /**
     * The scopes published by discovery at the time of writing. Supplied for
     * autocompletion only: {@see self::startAuthorization()} does not reject an
     * unlisted scope, because the authorization server may publish new ones.
     */
    public const SCOPES = [
        self::SCOPE_DOCUMENTS_READ,
        self::SCOPE_DOCUMENTS_WRITE,
        self::SCOPE_TEMPLATES_READ,
        self::SCOPE_TEMPLATES_WRITE,
        self::SCOPE_ACCOUNT_READ,
        self::SCOPE_WEBHOOKS_WRITE,
        self::SCOPE_OPENID,
        self::SCOPE_PROFILE,
        self::SCOPE_EMAIL,
        self::SCOPE_OFFLINE_ACCESS,
    ];

    /** RFC 7636 code-verifier grammar: 43–128 unreserved characters. */
    private const VERIFIER_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/D';

    private string $clientId;
    private ?string $clientSecret;
    /** @var \Closure(string): HttpClientInterface */
    private \Closure $discoveryTransport;

    /**
     * @param string      $clientId     the application's `client_id` from the Assinafy app
     * @param string|null $clientSecret confidential applications only; public applications
     *     authenticate with PKCE alone and are never issued a secret
     * @param (\Closure(string): HttpClientInterface)|null $discoveryTransport builds a
     *     credential-free client for a given origin. Defaults to a Guzzle client; tests
     *     inject a stub because the discovery documents live on origins the configured
     *     transport cannot reach.
     * @throws ValidationException on an empty client ID or a present-but-blank secret
     */
    public function __construct(
        HttpClientInterface $httpClient,
        #[\SensitiveParameter] Configuration $config,
        ?LoggerInterface $logger = null,
        string $clientId = '',
        #[\SensitiveParameter] ?string $clientSecret = null,
        ?\Closure $discoveryTransport = null
    ) {
        parent::__construct($httpClient, $config, $logger);

        if (trim($clientId) === '') {
            throw new ValidationException('OAuth client ID cannot be empty');
        }
        if ($clientSecret !== null && trim($clientSecret) === '') {
            throw new ValidationException('OAuth client secret cannot be empty when supplied');
        }

        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;

        $resolvedLogger = $this->logger;
        $this->discoveryTransport = $discoveryTransport ?? static fn (
            string $origin
        ): HttpClientInterface => new GuzzleHttpClient(
            Configuration::forPublic($origin),
            $resolvedLogger
        );
    }

    /**
     * Keep the client secret out of diagnostic object dumps.
     *
     * @return array{client_id: string, client_type: string}
     */
    public function __debugInfo(): array
    {
        return [
            'client_id' => $this->clientId,
            'client_type' => $this->clientSecret === null ? 'public' : 'confidential',
        ];
    }

    /**
     * Begin a connection: mint PKCE material and build the authorization URL.
     * Pure string construction — makes **no** HTTP request.
     *
     * Call this once per connection attempt. A fresh `code_verifier` and `state` are
     * generated every time, which is the single rule that stops one attempt's material
     * being replayed against another. Persist the whole return value in the user's
     * authenticated server-side session, then redirect the browser to
     * `authorization_url` with a full page navigation (never an AJAX call).
     *
     * Request/Response: none.
     *
     * Returns:
     * ```php
     * [
     *     'authorization_url' => 'https://auth.assinafy.com.br/oauth/authorize?response_type=code&…',
     *     'state' => '<43-char random string>',
     *     'code_verifier' => '<43-char random string>',
     *     'code_challenge' => '<base64url sha256 of the verifier>',
     *     'nonce' => '<43-char random string, only when the openid scope was requested>',
     *     'redirect_uri' => 'https://app.example.com/oauth/callback',
     *     'issuer' => 'https://auth.assinafy.com.br',
     *     'resource' => 'https://api.assinafy.com.br',
     * ]
     * ```
     *
     * Feed the same array back into {@see self::handleCallback()} and
     * {@see self::exchangeCode()}; neither re-derives anything from it that it cannot check.
     *
     * @param string             $redirectUri one of the application's registered URIs,
     *     character for character — `…/callback` and `…/callback/` are different
     * @param array<int, mixed>  $scopes      the permissions to request, e.g.
     *     {@see self::SCOPE_DOCUMENTS_WRITE}. The user approves all of them or none.
     * @param array<string, mixed> $options overrides: `state`, `code_verifier`, `nonce`,
     *     `issuer`, `authorization_endpoint`, `resource`. Every one has a correct default;
     *     supply `issuer`/`authorization_endpoint` from
     *     {@see self::authorizationServerMetadata()} to avoid hardcoding them.
     * @return array<string, string> the transaction to persist
     * @throws ValidationException on a non-HTTPS or fragment-bearing redirect URI, an
     *     empty scope list, a scope containing a space, or an out-of-grammar verifier
     */
    public function startAuthorization(
        string $redirectUri,
        array $scopes,
        #[\SensitiveParameter] array $options = []
    ): array {
        $this->assertRedirectUri($redirectUri);

        if ($scopes === []) {
            throw new ValidationException('At least one OAuth scope is required');
        }

        // `scope` is a space-delimited list on the wire, so an embedded space would
        // silently request two permissions — or one that does not exist.
        $requested = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || trim($scope) === '' || str_contains($scope, ' ')) {
                throw new ValidationException(
                    'OAuth scopes must be non-empty strings without spaces',
                    ['scope' => $scope]
                );
            }
            $requested[] = $scope;
        }

        $verifier = $this->stringOption($options, 'code_verifier') ?? self::createCodeVerifier();
        if (preg_match(self::VERIFIER_PATTERN, $verifier) !== 1) {
            throw new ValidationException(
                'PKCE code verifier must be 43-128 characters from A-Z a-z 0-9 - . _ ~'
            );
        }

        $issuer = rtrim($this->stringOption($options, 'issuer') ?? self::DEFAULT_ISSUER, '/');
        $endpoint = $this->stringOption($options, 'authorization_endpoint')
            ?? $issuer . '/oauth/authorize';
        $state = $this->stringOption($options, 'state') ?? self::createState();
        $resource = $this->stringOption($options, 'resource') ?? $this->apiOrigin();

        $query = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $requested),
            'state' => $state,
            'code_challenge' => self::codeChallenge($verifier),
            'code_challenge_method' => self::CODE_CHALLENGE_METHOD,
            'resource' => $resource,
        ];

        $transaction = [
            'state' => $state,
            'code_verifier' => $verifier,
            'code_challenge' => $query['code_challenge'],
            'redirect_uri' => $redirectUri,
            'issuer' => $issuer,
            'resource' => $resource,
        ];

        // An id_token is only issued for `openid`, and a nonce is only echoed into one.
        if (in_array(self::SCOPE_OPENID, $requested, true)) {
            $nonce = $this->stringOption($options, 'nonce') ?? self::createState();
            $query['nonce'] = $nonce;
            $transaction['nonce'] = $nonce;
        }

        $transaction['authorization_url'] = $endpoint
            . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $transaction;
    }

    /**
     * Validate the browser's return to your redirect URI and return the authorization code.
     * Pure verification — makes **no** HTTP request.
     *
     * This is the security-critical step of the flow. It rejects a response whose `state`
     * does not match the stored transaction (CSRF, or another tab's attempt) and one whose
     * `iss` is not the expected authorization server (a mixed-up or spoofed issuer), before
     * the code is ever sent anywhere. Consume the stored transaction exactly once.
     *
     * Request/Response: none — `$query` is your framework's already-parsed query string.
     *
     * Approved callback:
     * ```php
     * ['code' => '<authorization-code>', 'state' => '<state>', 'iss' => 'https://auth.assinafy.com.br']
     * ```
     *
     * Declined callback, which raises `ApiException` with message `access_denied`:
     * ```php
     * ['error' => 'access_denied', 'error_description' => '…', 'state' => '<state>',
     *  'iss' => 'https://auth.assinafy.com.br']
     * ```
     *
     * @param array<string, mixed> $query       the callback query parameters
     * @param array<string, mixed> $transaction the array {@see self::startAuthorization()}
     *     returned, loaded back from the user's session
     * @return string the single-use authorization code, which expires 60 seconds after approval
     * @throws ValidationException when the transaction is unusable, `state` does not match,
     *     `iss` is absent or wrong, or no code was returned
     * @throws ApiException when the authorization server reported an error — `getMessage()`
     *     is the RFC code (`access_denied`, `invalid_scope`, `invalid_request`,
     *     `unsupported_response_type`, `invalid_target`)
     */
    public function handleCallback(
        #[\SensitiveParameter] array $query,
        #[\SensitiveParameter] array $transaction
    ): string {
        $expectedState = $this->stringOption($transaction, 'state');
        if ($expectedState === null) {
            throw new ValidationException('Stored OAuth transaction is missing its state');
        }

        $state = $this->stringOption($query, 'state');
        if ($state === null || !hash_equals($expectedState, $state)) {
            throw new ValidationException('OAuth callback state does not match the stored transaction');
        }

        // The authorization server advertises authorization_response_iss_parameter_supported,
        // so a missing `iss` is itself a reason to stop rather than something to tolerate.
        $expectedIssuer = rtrim(
            $this->stringOption($transaction, 'issuer') ?? self::DEFAULT_ISSUER,
            '/'
        );
        $issuer = $this->stringOption($query, 'iss');
        if ($issuer === null || !hash_equals($expectedIssuer, rtrim($issuer, '/'))) {
            throw new ValidationException('OAuth callback issuer does not match the expected authorization server');
        }

        $error = $this->stringOption($query, 'error');
        if ($error !== null) {
            $description = $this->stringOption($query, 'error_description');

            throw new ApiException($error, 400, [
                'error' => $error,
                'error_description' => $description,
            ]);
        }

        $code = $this->stringOption($query, 'code');
        if ($code === null) {
            throw new ValidationException('OAuth callback did not include an authorization code');
        }

        return $code;
    }

    /**
     * Exchange an authorization code for tokens.
     * `POST /oauth/token` with `grant_type=authorization_code`
     *
     * Server-side only, and only once: the code is single-use and expires 60 seconds
     * after approval. The `redirect_uri` and `code_verifier` must be byte-identical to
     * the ones the authorization request was made with, which is why the whole stored
     * transaction is passed rather than re-supplied by hand.
     *
     * Request body (`application/x-www-form-urlencoded`; `client_secret` is omitted by
     * public applications):
     * ```php
     * [
     *     'grant_type' => 'authorization_code',
     *     'code' => '<authorization-code>',
     *     'redirect_uri' => 'https://app.example.com/oauth/callback',
     *     'code_verifier' => '<code-verifier>',
     *     'client_id' => '<client-id>',
     *     'client_secret' => '<client-secret>',
     *     'resource' => 'https://api.assinafy.com.br',
     * ]
     * ```
     *
     * Example response (flat JSON — no `data` envelope):
     * ```php
     * [
     *     'access_token' => '<access-token>',
     *     'token_type' => 'Bearer',
     *     'expires_in' => 3600,
     *     'scope' => 'documents:read documents:write',
     *     'refresh_token' => '<refresh-token>',
     *     'id_token' => '<signed-id-token>',
     * ]
     * ```
     *
     * `refresh_token` appears only when `offline_access` was requested and approved;
     * `id_token` only with `openid`. Read the returned `scope` instead of assuming every
     * requested permission was granted — `offline_access` is a request-time signal and
     * never appears there. Then call {@see \Assinafy\SDK\Resources\AccountResource::list()}
     * with the access token: an OAuth token returns exactly the one authorized workspace,
     * whose `data[0].id` belongs in the connection record next to the tokens.
     *
     * @param array<string, mixed> $transaction the array {@see self::startAuthorization()}
     *     returned; `code_verifier`, `redirect_uri` and `resource` are read from it
     * @return array<string, mixed> `{ access_token, token_type, expires_in, scope,
     *     refresh_token?, id_token? }`
     * @throws ValidationException on an empty code or a transaction missing its verifier
     *     or redirect URI
     * @throws ApiException `invalid_grant` (expired, replayed, or mismatched code),
     *     `invalid_client`, `invalid_target`
     */
    public function exchangeCode(
        #[\SensitiveParameter] string $code,
        #[\SensitiveParameter] array $transaction
    ): array {
        if (trim($code) === '') {
            throw new ValidationException('Authorization code cannot be empty');
        }

        $verifier = $this->stringOption($transaction, 'code_verifier');
        if ($verifier === null) {
            throw new ValidationException('Stored OAuth transaction is missing its code verifier');
        }

        $redirectUri = $this->stringOption($transaction, 'redirect_uri');
        if ($redirectUri === null) {
            throw new ValidationException('Stored OAuth transaction is missing its redirect URI');
        }

        return $this->token([
            'grant_type' => self::GRANT_AUTHORIZATION_CODE,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
            'resource' => $this->stringOption($transaction, 'resource') ?? $this->apiOrigin(),
        ]);
    }

    /**
     * Renew an access token without the user.
     * `POST /oauth/token` with `grant_type=refresh_token`
     *
     * Access tokens last one hour; a connection lasts 30 days from approval and refreshing
     * does not extend it, so plan for users to reconnect monthly.
     *
     * **Every refresh retires the token it used and returns a new one.** A replayed refresh
     * token cannot be distinguished from a stolen one, so the server ends the entire
     * connection when it sees one. Hold a per-connection lock, persist the returned
     * `refresh_token` before doing anything else with the response, and never retry after
     * an ambiguous timeout without first re-reading what you stored.
     *
     * Request body (`application/x-www-form-urlencoded`):
     * ```php
     * [
     *     'grant_type' => 'refresh_token',
     *     'refresh_token' => '<current-refresh-token>',
     *     'client_id' => '<client-id>',
     *     'client_secret' => '<client-secret>',
     * ]
     * ```
     *
     * Example response (flat JSON — no `data` envelope):
     * ```php
     * [
     *     'access_token' => '<new-access-token>',
     *     'token_type' => 'Bearer',
     *     'expires_in' => 3600,
     *     'scope' => 'documents:read documents:write',
     *     'refresh_token' => '<new-refresh-token>',
     * ]
     * ```
     *
     * @return array<string, mixed> the same shape as {@see self::exchangeCode()}
     * @throws ValidationException on an empty refresh token
     * @throws ApiException `invalid_grant` when the token was already used, has expired,
     *     lost `offline_access`, or the user reconnected with different permissions —
     *     reconnect rather than retry
     */
    public function refresh(#[\SensitiveParameter] string $refreshToken): array
    {
        if (trim($refreshToken) === '') {
            throw new ValidationException('Refresh token cannot be empty');
        }

        return $this->token([
            'grant_type' => self::GRANT_REFRESH_TOKEN,
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Disconnect: revoke an access or refresh token.
     * `POST /oauth/revoke`
     *
     * Call this when a user disconnects in your product, instead of only deleting your
     * copy. Revoking the refresh token ends the whole connection, so pass that when you
     * hold one. The endpoint answers `200` for every token outcome — revoked, already
     * revoked, unknown, malformed — so it can never be used to probe whether a token
     * exists, and a success here is not evidence the token was real. Only failed client
     * authentication answers `401`.
     *
     * Request body (`application/x-www-form-urlencoded`):
     * ```php
     * [
     *     'token' => '<refresh-or-access-token>',
     *     'token_type_hint' => 'refresh_token',
     *     'client_id' => '<client-id>',
     *     'client_secret' => '<client-secret>',
     * ]
     * ```
     *
     * Response: `200` with an empty body, returned as `[]`.
     *
     * @param string      $token         the refresh token when one is stored, else the access token
     * @param string|null $tokenTypeHint {@see self::TOKEN_TYPE_HINT_REFRESH} or
     *     {@see self::TOKEN_TYPE_HINT_ACCESS}; omit when unsure
     * @return array<string, mixed> an empty array on success
     * @throws ValidationException on an empty token or an unknown hint
     * @throws ApiException `invalid_client` on failed client authentication
     */
    public function revoke(
        #[\SensitiveParameter] string $token,
        ?string $tokenTypeHint = null
    ): array {
        if (trim($token) === '') {
            throw new ValidationException('Token cannot be empty');
        }

        if (
            $tokenTypeHint !== null
            && !in_array($tokenTypeHint, [self::TOKEN_TYPE_HINT_ACCESS, self::TOKEN_TYPE_HINT_REFRESH], true)
        ) {
            throw new ValidationException('Unknown token type hint', ['token_type_hint' => $tokenTypeHint]);
        }

        $payload = ['token' => $token];
        if ($tokenTypeHint !== null) {
            $payload['token_type_hint'] = $tokenTypeHint;
        }

        return $this->form('oauth/revoke', $payload + $this->clientCredentials());
    }

    /**
     * Read the OpenID Connect claims of the user who authorized the token.
     * `GET /oauth/userinfo`
     *
     * Requires the `openid` scope; `name` additionally requires `profile` and `email`
     * requires `email`. Use this for the user's profile rather than decoding the
     * `id_token`, which needs full RS256/JWKS validation by a maintained OIDC library
     * before any claim in it can be trusted.
     *
     * Request: no body. The token travels in `Authorization: Bearer`, which is the only
     * accepted method — an OAuth token sent as `X-Api-Key` or in the query string is refused.
     *
     * Example response (flat OIDC claims — no `data` envelope):
     * ```php
     * [
     *     'sub' => 'd6zqpbyog2v3xvxerwn8la94',
     *     'name' => 'Example User',
     *     'email' => 'person@example.com',
     *     'email_verified' => true,
     * ]
     * ```
     *
     * `sub` is the user's stable identifier; the optional claims are null or absent when
     * the matching scope was not granted.
     *
     * @return array<string, mixed> `{ sub, name?, email?, email_verified? }`
     * @throws ValidationException on an empty access token
     * @throws ApiException 401 when the token expired or was revoked; 403 when `openid`
     *     was not granted. Unlike the token and revocation endpoints, these errors arrive
     *     in the ordinary API envelope rather than as flat `{ error, error_description }`.
     */
    public function userinfo(#[\SensitiveParameter] string $accessToken): array
    {
        $response = $this->httpClient->get(
            'oauth/userinfo',
            [],
            $this->bearerHeaders($accessToken)
        );

        return $this->flatData($response->getData());
    }

    /**
     * Read this API's protected-resource metadata.
     * `GET /.well-known/oauth-protected-resource` — at the API origin, outside `/v1`.
     *
     * RFC 9728. Names the authorization server that issues tokens for this API and the
     * scopes it accepts, and is what the `resource_metadata="…"` parameter of a
     * `WWW-Authenticate` challenge points at. Start an integration here, then read the
     * authorization server's own document from `authorization_servers[0]`.
     *
     * Uses a separate credential-free client for the API origin: this document sits above
     * the `/v1` prefix the configured transport is pinned to, and carries no workspace
     * credential.
     *
     * Request: none.
     *
     * Example response (flat JSON — no `data` envelope):
     * ```php
     * [
     *     'resource' => 'https://api.assinafy.com.br',
     *     'authorization_servers' => ['https://auth.assinafy.com.br'],
     *     'scopes_supported' => [
     *         'documents:read', 'documents:write', 'templates:read', 'templates:write',
     *         'account:read', 'webhooks:write', 'openid', 'profile', 'email',
     *     ],
     *     'bearer_methods_supported' => ['header'],
     * ]
     * ```
     *
     * `scopes_supported` deliberately omits `offline_access`: asking for a refresh token
     * is a client concern, not something this resource is protected by.
     *
     * @return array<string, mixed> `{ resource, authorization_servers, scopes_supported,
     *     bearer_methods_supported }`
     * @throws ApiException when the deployment does not serve OAuth — sandbox does not
     */
    public function protectedResourceMetadata(): array
    {
        return $this->metadata($this->apiOrigin(), self::PROTECTED_RESOURCE_METADATA_PATH);
    }

    /**
     * Read the authorization server's metadata.
     * `GET {issuer}/.well-known/oauth-authorization-server`
     *
     * RFC 8414. This is the document to configure from rather than hardcoding endpoint
     * URLs: pass its `issuer` and `authorization_endpoint` into
     * {@see self::startAuthorization()}. It is served **only** by the authorization
     * server, never by this API, so it is fetched with a separate credential-free client
     * for that origin.
     *
     * Request: none.
     *
     * Example response (flat JSON — no `data` envelope):
     * ```php
     * [
     *     'issuer' => 'https://auth.assinafy.com.br',
     *     'authorization_endpoint' => 'https://auth.assinafy.com.br/oauth/authorize',
     *     'token_endpoint' => 'https://api.assinafy.com.br/v1/oauth/token',
     *     'revocation_endpoint' => 'https://api.assinafy.com.br/v1/oauth/revoke',
     *     'userinfo_endpoint' => 'https://api.assinafy.com.br/v1/oauth/userinfo',
     *     'jwks_uri' => 'https://auth.assinafy.com.br/.well-known/jwks.json',
     *     'scopes_supported' => [
     *         'documents:read', 'documents:write', 'templates:read', 'templates:write',
     *         'account:read', 'webhooks:write', 'openid', 'profile', 'email', 'offline_access',
     *     ],
     *     'response_types_supported' => ['code'],
     *     'grant_types_supported' => ['authorization_code', 'refresh_token'],
     *     'code_challenge_methods_supported' => ['S256'],
     *     'token_endpoint_auth_methods_supported' => ['client_secret_post', 'none'],
     *     'authorization_response_iss_parameter_supported' => true,
     *     'client_id_metadata_document_supported' => true,
     * ]
     * ```
     *
     * Validate the returned `issuer` against the one you expect before sending a secret
     * to any endpoint it names.
     *
     * @param string|null $issuer the authorization server origin; defaults to
     *     {@see self::DEFAULT_ISSUER}, or pass `authorization_servers[0]` from
     *     {@see self::protectedResourceMetadata()}
     * @return array<string, mixed> the RFC 8414 metadata object
     * @throws ValidationException on a non-HTTPS issuer
     * @throws ApiException when the issuer does not serve the document
     */
    public function authorizationServerMetadata(?string $issuer = null): array
    {
        return $this->metadata(
            rtrim($issuer ?? self::DEFAULT_ISSUER, '/'),
            self::AUTHORIZATION_SERVER_METADATA_PATH
        );
    }

    /**
     * Generate an RFC 7636 code verifier: 43 characters from the unreserved set.
     *
     * A new one is required for every connection attempt. {@see self::startAuthorization()}
     * calls this for you; use it directly only when your framework owns the session material.
     */
    public static function createCodeVerifier(): string
    {
        return self::base64Url(random_bytes(32));
    }

    /** Derive the S256 challenge sent to the authorization server from a verifier. */
    public static function codeChallenge(#[\SensitiveParameter] string $codeVerifier): string
    {
        return self::base64Url(hash('sha256', $codeVerifier, true));
    }

    /** Generate the random per-attempt `state` that protects the callback against CSRF. */
    public static function createState(): string
    {
        return self::base64Url(random_bytes(32));
    }

    /**
     * Send a form-encoded token request.
     *
     * RFC 6749 §4.1.3 specifies `application/x-www-form-urlencoded`, which is also what
     * the published integration guide sends. The transport logs only the byte count of a
     * raw body, so the grant never reaches a log line.
     *
     * @param array<string, string> $payload
     * @return array<string, mixed>
     */
    private function token(#[\SensitiveParameter] array $payload): array
    {
        return $this->form('oauth/token', $payload + $this->clientCredentials());
    }

    /**
     * @param array<string, string> $payload
     * @return array<string, mixed>
     */
    private function form(string $uri, #[\SensitiveParameter] array $payload): array
    {
        $response = $this->httpClient->postRaw(
            $uri,
            http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
            'application/x-www-form-urlencoded'
        );

        return $this->flatData($response->getData());
    }

    /**
     * `client_secret_post` for confidential applications, PKCE alone for public ones.
     *
     * @return array<string, string>
     */
    private function clientCredentials(): array
    {
        $credentials = ['client_id' => $this->clientId];
        if ($this->clientSecret !== null) {
            $credentials['client_secret'] = $this->clientSecret;
        }

        return $credentials;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function stringOption(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * OAuth and OIDC responses are string-keyed objects, never the API's list envelope.
     *
     * @param array<array-key, mixed>|null $data
     * @return array<string, mixed>
     */
    private function flatData(?array $data): array
    {
        if ($data === null) {
            return [];
        }

        $flat = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new \Assinafy\SDK\Exceptions\NetworkException(
                    'Assinafy API returned a non-object OAuth response'
                );
            }
            $flat[$key] = $value;
        }

        return $flat;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $origin, string $path): array
    {
        if (strtolower((string) parse_url($origin, PHP_URL_SCHEME)) !== 'https') {
            throw new ValidationException('OAuth metadata origins must use HTTPS', ['origin' => $origin]);
        }

        return $this->flatData(($this->discoveryTransport)($origin)->get($path)->getData());
    }

    /** The API origin the `resource` indicator names — the base URL without `/v1`. */
    private function apiOrigin(): string
    {
        $baseUrl = $this->config->getBaseUrl();
        $port = parse_url($baseUrl, PHP_URL_PORT);

        return parse_url($baseUrl, PHP_URL_SCHEME) . '://'
            . parse_url($baseUrl, PHP_URL_HOST)
            . ($port === null ? '' : ':' . $port);
    }

    private function assertRedirectUri(string $redirectUri): void
    {
        if (filter_var($redirectUri, FILTER_VALIDATE_URL) === false) {
            throw new ValidationException('Redirect URI must be a valid absolute URL', [
                'redirect_uri' => $redirectUri,
            ]);
        }

        if (strtolower((string) parse_url($redirectUri, PHP_URL_SCHEME)) !== 'https') {
            throw new ValidationException(
                'Redirect URI must use HTTPS — plain http://localhost is not accepted, use an HTTPS tunnel',
                ['redirect_uri' => $redirectUri]
            );
        }

        if (parse_url($redirectUri, PHP_URL_FRAGMENT) !== null || str_contains($redirectUri, '#')) {
            throw new ValidationException('Redirect URI cannot contain a fragment', [
                'redirect_uri' => $redirectUri,
            ]);
        }
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
