# Marketplace OAuth integration

Use OAuth when an application connects a customer's Assinafy workspace. Keep a separate connection
record for each workspace, with its granted scopes, encrypted credentials, and expiration times.
An API key is appropriate for a backend accessing its own workspace.

The [official OAuth guide](https://api.assinafy.com.br/v1/docs#tag/OAuth-Integration-Guide) defines authorization-code
flow with PKCE S256. Registration requires an eligible Assinafy account, a registered application,
and an exact redirect URI. A confidential backend also receives a client secret. A workspace API
key cannot create an authorization code or substitute for the client's credentials and consent.

The SDK's `forBearer()` client handles resource calls with the resulting access token. Token,
revocation, userinfo, and discovery calls use the existing public HTTP transport; there is no
`oauth()` resource or automatic token renewal. The examples below describe the published success
contract. Successful consent, exchange, renewal, and revocation require a registered app and are
separate from API-key sandbox tests.

## Register the application

An owner of an eligible workspace creates the application in the
[Assinafy app](https://app.assinafy.com.br), under **Settings → OAuth applications → New application**.
Choose Confidential for a backend that can protect a client secret, or Public for code on a user's
device. Register the maximum permissions the app needs and an exact HTTPS redirect URI without a
fragment. Local development requires an HTTPS callback; plain `http://localhost` is not accepted.

Store the issued `client_id` and, for confidential apps, the secret shown once. The examples use
`ASSINAFY_OAUTH_CLIENT_ID` and `ASSINAFY_OAUTH_CLIENT_SECRET` from server-side secret configuration.
Deleting or disabling an app invalidates its connections. New unverified apps are limited to
25 workspaces; arrange verification with Assinafy before expanding beyond that limit.

## Discover the production endpoints

The protected-resource document is at the API origin, outside `/v1`:

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;

$resourceClient = AssinafyClient::forAuth('https://api.assinafy.com.br');
$resourceMetadata = $resourceClient->getHttpClient()
    ->get('.well-known/oauth-protected-resource')->getData();

$issuerClient = AssinafyClient::forAuth('https://auth.assinafy.com.br');
$authorizationMetadata = $issuerClient->getHttpClient()
    ->get('.well-known/oauth-authorization-server')->getData();
```

Both requests have no body or authentication. The protected-resource response is a flat object:

```php
[
    'resource' => 'https://api.assinafy.com.br',
    'authorization_servers' => ['https://auth.assinafy.com.br'],
    'scopes_supported' => [
        'documents:read', 'documents:write', 'templates:read', 'templates:write',
        'account:read', 'openid', 'profile', 'email',
    ],
    'bearer_methods_supported' => ['header'],
]
```

The authorization-server response is also a flat object:

```php
[
    'issuer' => 'https://auth.assinafy.com.br',
    'authorization_endpoint' => 'https://auth.assinafy.com.br/oauth/authorize',
    'token_endpoint' => 'https://api.assinafy.com.br/v1/oauth/token',
    'revocation_endpoint' => 'https://api.assinafy.com.br/v1/oauth/revoke',
    'userinfo_endpoint' => 'https://api.assinafy.com.br/v1/oauth/userinfo',
    'jwks_uri' => 'https://auth.assinafy.com.br/.well-known/jwks.json',
    'scopes_supported' => [
        'documents:read', 'documents:write', 'templates:read', 'templates:write',
        'account:read', 'openid', 'profile', 'email', 'offline_access',
    ],
    'response_types_supported' => ['code'],
    'grant_types_supported' => ['authorization_code', 'refresh_token'],
    'code_challenge_methods_supported' => ['S256'],
    'token_endpoint_auth_methods_supported' => ['client_secret_post', 'none'],
    'authorization_response_iss_parameter_supported' => true,
    'client_id_metadata_document_supported' => true,
]
```

The metadata advertises client-ID metadata documents as an additional client-identification
mechanism. This guide uses an application registered in Assinafy.
Pin the expected issuer and validate discovered origins before sending secrets. Do not mix sandbox
resource URLs with production authorization. Sandbox deployments may not expose these OAuth routes.

## Begin authorization

Generate new state and a verifier for every connection attempt. Store this transaction in the
application's authenticated server-side session with a short expiry and bind it to the initiating
user. The verifier must be 43–128 characters from the PKCE unreserved character set.

```php
$clientId = (string) getenv('ASSINAFY_OAUTH_CLIENT_ID');
if ($clientId === '') {
    throw new RuntimeException('ASSINAFY_OAUTH_CLIENT_ID is required');
}
$redirectUri = 'https://app.example.com/integrations/assinafy/callback';
$verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$state = bin2hex(random_bytes(32));
$nonce = bin2hex(random_bytes(32));

// The host application must already have started its secure authenticated session.
$_SESSION['assinafy_oauth'] = [
    'state' => $state,
    'verifier' => $verifier,
    'nonce' => $nonce,
    'redirect_uri' => $redirectUri,
    'created_at' => time(),
];

$authorizationUrl = 'https://auth.assinafy.com.br/oauth/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'scope' => 'documents:read documents:write account:read openid profile email offline_access',
    'resource' => 'https://api.assinafy.com.br',
    'code_challenge' => $challenge,
    'code_challenge_method' => 'S256',
    'state' => $state,
    'nonce' => $nonce,
], '', '&', PHP_QUERY_RFC3986);
```

Redirect the user to `$authorizationUrl`. The user selects a workspace and grants permissions.
Request only scopes needed by the app; template access needs `templates:read` or `templates:write`.
The `resource` value is the API origin, without `/v1`.

## Validate the callback and exchange the code

The callback includes `code`, `state`, and `iss`, or an OAuth error. Verify state and issuer before
using the code. Consume the stored transaction once. Authorization codes expire after 60 seconds.

```php
$transaction = $_SESSION['assinafy_oauth'] ?? null;
unset($_SESSION['assinafy_oauth']);

if (
    !is_array($transaction)
    || !is_string($_GET['state'] ?? null)
    || !hash_equals($transaction['state'], $_GET['state'])
    || ($_GET['iss'] ?? null) !== 'https://auth.assinafy.com.br'
    || time() - $transaction['created_at'] > 600
) {
    throw new RuntimeException('Invalid or expired OAuth callback');
}
if (isset($_GET['error'])) {
    throw new RuntimeException('Assinafy authorization was not completed');
}
$code = $_GET['code'] ?? null;
if (!is_string($code) || $code === '') {
    throw new RuntimeException('Missing authorization code');
}

$tokenRequest = [
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => $transaction['redirect_uri'],
    'client_id' => (string) getenv('ASSINAFY_OAUTH_CLIENT_ID'),
    'client_secret' => (string) getenv('ASSINAFY_OAUTH_CLIENT_SECRET'),
    'code_verifier' => $transaction['verifier'],
    'resource' => 'https://api.assinafy.com.br',
];
$public = AssinafyClient::forAuth(Configuration::DEFAULT_BASE_URL);
$tokens = $public->getHttpClient()->postRaw(
    'oauth/token',
    http_build_query($tokenRequest, '', '&', PHP_QUERY_RFC3986),
    'application/x-www-form-urlencoded',
)->getData();
```

This example is for a confidential backend using `client_secret_post`. Public clients omit
`client_secret` and still use PKCE. Use form encoding as specified in the integration guide.
Token responses are flat JSON, with no `data` envelope:

```php
[
    'access_token' => '<access-token>',
    'token_type' => 'Bearer',
    'expires_in' => 3600,
    'refresh_token' => '<refresh-token>',
    'scope' => 'documents:read documents:write account:read openid profile email offline_access',
    'id_token' => '<signed-id-token>',
]
```

`refresh_token` is present only with approved offline access; `id_token` requires `openid`.
Read actual granted scopes from `scope`; do not assume every requested scope was approved.
`offline_access` is a renewal request signal and is not an access-token permission.
Persist tokens only after validating the response and the initiating application's user/session.
If using OIDC identity, use a maintained OIDC/JWT library to validate the RS256 signature against
JWKS, issuer, audience, expiration, and the stored nonce. Merely decoding a JWT does not validate it.

## Select the authorized workspace

```php
if (!is_array($tokens) || !is_string($tokens['access_token'] ?? null)) {
    throw new RuntimeException('Invalid OAuth token response');
}
$accounts = $public->accounts()->list($tokens['access_token']);
$accountId = $accounts['data'][0]['id'] ?? null;
if (!is_string($accountId) || $accountId === '') {
    throw new RuntimeException('No authorized workspace returned');
}
$connected = AssinafyClient::forBearer($tokens['access_token'], $accountId);
$documents = $connected->documents()->list();
```

The OAuth connection authorizes one workspace. Persist that ID with the connection and never
accept a different workspace ID from an untrusted request. Create a fresh client for each
connection; avoid shared mutable credentials in workers or service singletons.

OAuth scopes restrict resource access. Billing, membership, credential-management, and some
administrative APIs are unavailable through OAuth even though an API-key client exposes them.
For `403` with `insufficient_scope` in `WWW-Authenticate`, request the missing permission through
new consent. Other `403` responses may be plan, ownership, or feature restrictions.

## Refresh and replace credentials atomically

Access tokens last one hour. Refresh tokens rotate on every use, and approval expires after
30 days. Hold a lock for the connection, load its current refresh token, refresh once, and
atomically save the new token pair before releasing the lock.

```php
$refreshRequest = [
    'grant_type' => 'refresh_token',
    'refresh_token' => (string) getenv('ASSINAFY_OAUTH_REFRESH_TOKEN'),
    'client_id' => (string) getenv('ASSINAFY_OAUTH_CLIENT_ID'),
    'client_secret' => (string) getenv('ASSINAFY_OAUTH_CLIENT_SECRET'),
];
$renewed = $public->getHttpClient()->postRaw(
    'oauth/token',
    http_build_query($refreshRequest, '', '&', PHP_QUERY_RFC3986),
    'application/x-www-form-urlencoded',
)->getData();
```

The response has the same token fields as the code exchange. In an application, load the refresh
token from the encrypted connection record rather than a process-wide environment variable.
Never reuse an old refresh token, run parallel refreshes, or blindly retry after an ambiguous
timeout: reuse detection can invalidate the connection. Reconnect after expiration, revocation,
or `invalid_grant`. The SDK does not implement storage, locks, consent, or automatic retries.

## Userinfo and disconnect

Userinfo requires an OAuth Bearer token and returns flat OIDC claims:

```php
$userinfo = $public->getHttpClient()->get('oauth/userinfo', [], [
    'Authorization' => 'Bearer ' . $tokens['access_token'],
])->getData();
// ['sub' => 'user-id', 'name' => 'Example User', 'email' => 'user@example.com', 'email_verified' => true]
```

Optional claims depend on scopes. Disconnect with a refresh token when one is stored, otherwise
with the access token:

```php
$revokeRequest = [
    'token' => $tokens['refresh_token'] ?? $tokens['access_token'],
    'client_id' => (string) getenv('ASSINAFY_OAUTH_CLIENT_ID'),
    'client_secret' => (string) getenv('ASSINAFY_OAUTH_CLIENT_SECRET'),
];
$public->getHttpClient()->postRaw(
    'oauth/revoke',
    http_build_query($revokeRequest, '', '&', PHP_QUERY_RFC3986),
    'application/x-www-form-urlencoded',
);
```

Successful revocation returns HTTP 200 with no token information; unknown/revoked tokens also
succeed. Invalid client authentication can return 401. Remove the local connection's credentials
when disconnecting, and record any remote revocation failure without recording token values.

OAuth errors can be flat objects such as
`['error' => 'invalid_client', 'error_description' => 'Client authentication failed.']`.
The SDK raises `ApiException`; inspect `getResponseData()` and `getResponseHeaderLine()` without
logging the full callback, request, token response, or exception context.
