# Marketplace OAuth integration

Use OAuth when your application connects **someone else's** Assinafy workspace, without ever
handling their password or API key. The user approves your app once and you receive tokens limited
to the permissions they approved and to the single workspace they chose. Automating your own
workspace needs none of this — keep using an API key.

| | API key | OAuth |
| --- | --- | --- |
| Acts on | Your own workspace | Someone else's workspace, with their permission |
| Can do | Everything your account can do | Only the approved scopes |
| The user can switch it off | No | Yes, at any time |
| Choose it when | You automate your own account | You build an app other people connect |

`AssinafyClient::oauth()` returns an `OAuthResource` that covers the whole flow: PKCE material,
the authorization URL, callback validation, the token exchange, refresh, revocation, userinfo and
both discovery documents. The SDK deliberately does **not** store tokens, hold refresh locks or
renew anything automatically — those belong to your application, and this guide shows where.

Two hosts are involved on purpose. The browser-facing consent page lives on the authorization
server `https://auth.assinafy.com.br`; token, revocation and userinfo live on the API under `/v1`.
OAuth is deployed to production; sandbox does not serve these routes.

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Resources\OAuthResource;

$oauth = AssinafyClient::forAuth()->oauth(
    (string) getenv('ASSINAFY_OAUTH_CLIENT_ID'),
    (string) getenv('ASSINAFY_OAUTH_CLIENT_SECRET') ?: null,
);
```

Pass `null` as the second argument for a public application: it authenticates with PKCE alone and
is never issued a secret.

## Register the application

An owner of an eligible workspace creates the application in the
[Assinafy app](https://app.assinafy.com.br) under **Settings → OAuth applications → New
application**. Applications are created there, not through an API.

| Field | What to put |
| --- | --- |
| Name | What users see on the approval screen |
| Description | One sentence on what the app does with their documents |
| Logo URL | Optional `https://` image |
| Redirect URIs | Where users return after approving. Must be `https://`, without `#`, and is matched exactly — `…/callback` and `…/callback/` are different. Register one per environment. |
| Permissions | The most your app will ever request; you can ask for less at connect time, never more |
| Type | **Confidential** for code on a server you control, **Public** for code on the user's device. Cannot be changed later. |

Store the issued `client_id` and, for confidential apps, the secret shown once. The examples read
`ASSINAFY_OAUTH_CLIENT_ID` and `ASSINAFY_OAUTH_CLIENT_SECRET` from server-side secret storage. If
a secret is lost, rotate it — the old one stops working immediately, so deploy the new one at
once. Deleting or disabling the application disconnects every user immediately.

Local development needs an HTTPS tunnel; plain `http://localhost` is not accepted. New
applications are unverified: the approval screen says Assinafy has not reviewed them and they can
connect at most 25 workspaces. Arrange verification before launching beyond a pilot. One product
that many Assinafy customers will connect can be registered as a verified application owned by no
single workspace — talk to Assinafy first.

## Discover the endpoints

Read endpoint URLs from discovery rather than hardcoding them. Both documents are unauthenticated
and live at their host's origin, above the `/v1` prefix, so the SDK fetches each with a separate
credential-free client.

```php
$resource = $oauth->protectedResourceMetadata();
// [
//     'resource' => 'https://api.assinafy.com.br',
//     'authorization_servers' => ['https://auth.assinafy.com.br'],
//     'scopes_supported' => [
//         'documents:read', 'documents:write', 'templates:read', 'templates:write',
//         'account:read', 'openid', 'profile', 'email',
//     ],
//     'bearer_methods_supported' => ['header'],
// ]

$server = $oauth->authorizationServerMetadata($resource['authorization_servers'][0]);
// [
//     'issuer' => 'https://auth.assinafy.com.br',
//     'authorization_endpoint' => 'https://auth.assinafy.com.br/oauth/authorize',
//     'token_endpoint' => 'https://api.assinafy.com.br/v1/oauth/token',
//     'revocation_endpoint' => 'https://api.assinafy.com.br/v1/oauth/revoke',
//     'userinfo_endpoint' => 'https://api.assinafy.com.br/v1/oauth/userinfo',
//     'jwks_uri' => 'https://auth.assinafy.com.br/.well-known/jwks.json',
//     'scopes_supported' => [
//         'documents:read', 'documents:write', 'templates:read', 'templates:write',
//         'account:read', 'openid', 'profile', 'email', 'offline_access',
//     ],
//     'response_types_supported' => ['code'],
//     'grant_types_supported' => ['authorization_code', 'refresh_token'],
//     'code_challenge_methods_supported' => ['S256'],
//     'token_endpoint_auth_methods_supported' => ['client_secret_post', 'none'],
//     'authorization_response_iss_parameter_supported' => true,
//     'client_id_metadata_document_supported' => true,
// ]
```

`scopes_supported` on the protected resource omits `offline_access` on purpose: asking for a
refresh token is a client concern, not something the API is protected by. The authorization
server's own list includes it.

Validate the returned `issuer` against the one you expect before sending a secret to any endpoint
it names, and do not mix a sandbox resource URL with production authorization. The SDK falls back
to `OAuthResource::DEFAULT_ISSUER` when you pass nothing.

## Permissions (scopes)

| Constant | Scope | Lets your app |
| --- | --- | --- |
| `SCOPE_DOCUMENTS_READ` | `documents:read` | Read documents, their signers, assignments and activity |
| `SCOPE_DOCUMENTS_WRITE` | `documents:write` | Create documents and send them for signature |
| `SCOPE_TEMPLATES_READ` | `templates:read` | Read templates |
| `SCOPE_TEMPLATES_WRITE` | `templates:write` | Create and change templates |
| `SCOPE_ACCOUNT_READ` | `account:read` | Read the workspace profile, theme and logo |
| `SCOPE_OPENID` | `openid` | Receive an `id_token` identifying the user |
| `SCOPE_PROFILE` | `profile` | Read the user's name |
| `SCOPE_EMAIL` | `email` | Read the user's email and whether it is verified |
| `SCOPE_OFFLINE_ACCESS` | `offline_access` | Receive a refresh token, to keep working while the user is away |

Request the minimum: every permission is another line the user reads before deciding. The user
approves everything you requested or nothing, so a feature that needs more later means running the
flow again with the larger set. `documents:write` can spend the workspace's notification credits,
because sending for signature notifies signers. Billing and subscriptions, workspace membership,
credentials and administration are never available to an OAuth token, whatever its scopes.

## Begin authorization

`startAuthorization()` mints a fresh PKCE verifier and `state` on every call and returns the
transaction to persist. It makes no HTTP request.

```php
$start = $oauth->startAuthorization('https://app.example.com/integrations/assinafy/callback', [
    OAuthResource::SCOPE_DOCUMENTS_READ,
    OAuthResource::SCOPE_DOCUMENTS_WRITE,
    OAuthResource::SCOPE_ACCOUNT_READ,
    OAuthResource::SCOPE_OPENID,
    OAuthResource::SCOPE_EMAIL,
    OAuthResource::SCOPE_OFFLINE_ACCESS,
], ['issuer' => $server['issuer'], 'authorization_endpoint' => $server['authorization_endpoint']]);

// [
//     'state' => '<43-character random string>',
//     'code_verifier' => '<43-character random string>',
//     'code_challenge' => '<base64url sha256 of the verifier>',
//     'redirect_uri' => 'https://app.example.com/integrations/assinafy/callback',
//     'issuer' => 'https://auth.assinafy.com.br',
//     'resource' => 'https://api.assinafy.com.br',
//     'nonce' => '<43-character random string>',
//     'authorization_url' => 'https://auth.assinafy.com.br/oauth/authorize?response_type=code&…',
// ]

// The host application must already have started its secure authenticated session.
$_SESSION['assinafy_oauth'] = $start + ['created_at' => time()];

header('Location: ' . $start['authorization_url'], true, 302);
```

Bind the transaction to the initiating user with a short expiry, and redirect with a full page
navigation — never an AJAX call. A `nonce` is generated only when `openid` is among the scopes,
because nothing else echoes one back.

The `options` argument overrides `state`, `code_verifier`, `nonce`, `issuer`,
`authorization_endpoint` and `resource`; every one has a correct default. Supply your own only
when your framework owns that material.

If `client_id` or `redirect_uri` is wrong the user is **not** sent back to you — the authorization
server shows an error on its own page, because redirecting to an unverified address would be
unsafe. Users stuck on an Assinafy error page usually mean one of those two values is wrong.

## Validate the callback and exchange the code

`handleCallback()` is the security-critical step. It rejects a `state` that does not match the
stored transaction and an `iss` that is not the expected authorization server before the code is
sent anywhere, and turns a declined authorization into an exception. Consume the stored
transaction exactly once.

```php
use Assinafy\SDK\Exceptions\ApiException;

$transaction = $_SESSION['assinafy_oauth'] ?? null;
unset($_SESSION['assinafy_oauth']);

if (!is_array($transaction) || time() - $transaction['created_at'] > 600) {
    throw new RuntimeException('Invalid or expired OAuth transaction');
}

try {
    $code = $oauth->handleCallback($_GET, $transaction);
    $tokens = $oauth->exchangeCode($code, $transaction);
} catch (ApiException $e) {
    // 'access_denied' when the user declined; 'invalid_scope', 'invalid_request',
    // 'unsupported_response_type' or 'invalid_target' for a malformed request;
    // 'invalid_grant' or 'invalid_client' from the token endpoint.
    throw new RuntimeException('Assinafy authorization was not completed: ' . $e->getMessage());
}

// [
//     'access_token' => '<access-token>',
//     'token_type' => 'Bearer',
//     'expires_in' => 3600,
//     'scope' => 'documents:read documents:write account:read openid email',
//     'refresh_token' => '<refresh-token>',
//     'id_token' => '<signed-id-token>',
// ]
```

The code is single-use and expires **60 seconds** after approval, so exchange it from your server
immediately. `exchangeCode()` reads `code_verifier`, `redirect_uri` and `resource` from the stored
transaction so they match the authorization request byte for byte.

`refresh_token` is present only when `offline_access` was requested **and** approved; `id_token`
only with `openid`. Read the returned `scope` instead of assuming every requested permission was
granted — `offline_access` is a request-time signal, not an access-token permission, and never
appears there.

Token responses are flat JSON: RFC 6749 forbids the `{ status, message, data }` envelope the rest
of the API uses, so these methods return the decoded body as-is. Errors are flat too, which is why
`ApiException::getMessage()` is the machine-readable code and `getResponseData()['error_description']`
is the human-readable text. Persist tokens only after validating the response and the initiating
session, and never log the callback, the request, the token response or the exception context.

## Select the authorized workspace

A token belongs to the one workspace the user picked. With an OAuth token the workspace list
returns exactly that workspace.

```php
$accounts = AssinafyClient::forAuth()->accounts()->list($tokens['access_token']);
$accountId = $accounts['data'][0]['id'] ?? null;
if (!is_string($accountId) || $accountId === '') {
    throw new RuntimeException('No authorized workspace returned');
}

$connected = AssinafyClient::forBearer($tokens['access_token'], $accountId);
$documents = $connected->documents()->list();
```

Store that workspace id with the connection and never accept a different one from an untrusted
request. Calling any other workspace returns `403`, even one the same user belongs to — if your
customer uses several workspaces, connect each one separately and keep tokens per workspace. This
is the integration mistake we see most often.

Always send the token in `Authorization: Bearer`, which `forBearer()` does. A token sent as
`X-Api-Key` or in the query string is refused.

Create a fresh client per connection and avoid shared mutable credentials in workers or service
singletons.

## Refresh and replace credentials atomically

Access tokens last one hour. A connection lasts **30 days from the user's approval** and
refreshing does not extend it, so plan for users to reconnect monthly.

```php
$renewed = $oauth->refresh($connection->refreshToken);
// Same shape as the code exchange, with a NEW refresh_token.
```

Every refresh returns a new refresh token and retires the old one. A reused refresh token cannot
be told apart from a stolen one being replayed, so it ends the whole connection: every token stops
working and the user must connect again. Therefore:

1. Hold a lock for the connection and refresh one at a time.
2. Save the new `refresh_token` before doing anything else with the response.
3. Treat a timeout as "maybe it worked" — re-read your stored token before retrying, never retry
   blindly with the old one.

Load the refresh token from the encrypted connection record rather than a process-wide environment
variable. Reconnect after expiration, revocation, or `invalid_grant`. If the user approves your app
again with different permissions, the previous tokens stop working immediately.

## Sign users in (OpenID Connect)

Request `openid`, plus `profile` and/or `email`, to receive an `id_token`: a signed JWT saying who
approved. Validate it with a maintained OpenID Connect library — signature `RS256` against the
keys at `jwks_uri` matching the `kid`, `iss` equal to the issuer, `aud` equal to your `client_id`,
`exp` in the future, and `nonce` equal to the one you stored. Decoding a JWT is not validating it,
and the SDK does not ship a JWT implementation.

For the user's name and email, call userinfo instead of trusting a decoded token:

```php
$claims = $oauth->userinfo($tokens['access_token']);
// ['sub' => 'user-id', 'name' => 'Example User', 'email' => 'person@example.com',
//  'email_verified' => true]
```

`sub` is the user's stable identifier. `name` requires `profile` and `email` requires `email`;
optional claims are null or absent otherwise. Userinfo authenticates like any other API route, so
unlike the token endpoint its `401`/`403` arrive in the ordinary API envelope.

## Disconnecting

When a user disconnects in your product, revoke the token instead of only deleting your copy.
Revoke the refresh token when you hold one — that ends the whole connection.

```php
$oauth->revoke(
    $connection->refreshToken ?? $connection->accessToken,
    OAuthResource::TOKEN_TYPE_HINT_REFRESH,
);
```

Every token outcome answers `200` — revoked, already revoked, unknown, malformed — so the endpoint
can never be used to probe whether a token exists, and success here is not evidence the token was
real. Only failed client authentication answers `401` with `invalid_client`.

Users can also revoke your app themselves under **Connected apps** in their Assinafy profile, and
deleting or disabling your application has the same effect. In every case your tokens stop working
at once: handle the `401` by asking the user to connect again. Remove the local connection's
credentials when disconnecting, and record a remote revocation failure without recording token
values.

## Errors

On your redirect URI, surfaced by `handleCallback()` as `ApiException` with status 400:

| `error` | Meaning |
| --- | --- |
| `access_denied` | The user declined |
| `invalid_scope` | A scope your application is not registered for, or none |
| `invalid_request` | Missing or malformed PKCE parameters |
| `unsupported_response_type` | Anything other than `response_type=code` |
| `invalid_target` | A `resource` other than the API origin |

From the token endpoint, surfaced by `exchangeCode()` and `refresh()`:

| `error` | Usual causes |
| --- | --- |
| `invalid_grant` | Code expired, already used, or issued to another client; a `code_verifier` outside the 43–128 unreserved-character grammar; `redirect_uri` mismatch; a refresh token already used, expired, or whose authorization no longer includes `offline_access` |
| `invalid_client` | Wrong `client_id` or secret, or the application is disabled |
| `invalid_target` | `resource` does not match what was authorized |
| `unsupported_grant_type` | Only `authorization_code` and `refresh_token` exist |

From resource calls made with the token:

| Status | Meaning |
| --- | --- |
| `401` | Token expired, revoked, or not sent as `Bearer`. Refresh; if that fails, ask the user to reconnect. |
| `403` with `insufficient_scope` | Missing permission. Reconnect requesting the scope named in `WWW-Authenticate`. |
| `403` otherwise | Another workspace, the user's own role, or an area OAuth tokens can never reach. |
| `429` | Too many requests. Back off and retry later. |

An `insufficient_scope` challenge names what is missing and where the resource metadata lives:

```php
try {
    $connected->documents()->upload($path);
} catch (ApiException $e) {
    $challenge = $e->getResponseHeaderLine('WWW-Authenticate');
    // Bearer error="insufficient_scope", scope="documents:write",
    //   resource_metadata="https://api.assinafy.com.br/.well-known/oauth-protected-resource"
}
```

Treat it as a prompt to reconnect with that scope added, not as a request to retry.

The authorize and token endpoints accept **50 requests per minute per IP**. Normal traffic stays
far below that; hitting it usually means a refresh loop.

## Before you go live

- A new PKCE verifier and `state` for every connection attempt — `startAuthorization()` does this.
- `state` and `iss` checked on your redirect URI — `handleCallback()` does this.
- `client_secret` only on your server, never in a mobile app, browser code or a repository.
- The new refresh token saved before use, and one refresh at a time per connection.
- `401` handled: refresh, and if that fails, ask the user to reconnect.
- The workspace id stored per connection, and the returned `scope` read rather than assumed.
- Every production redirect URI registered, `https://` and exact.
- Only the permissions you need.
- Tokens revoked when a user disconnects.
- Verification requested before launching beyond a pilot.
