# Upgrading

## 1.x → 2.0.0

Most code needs no changes. The main migration points are the PHP version floor, webhook
handling, the `Configuration` constructor, and custom transport signatures.

Version 2.0.0 is available as repository tag `v2.0.0`, but Packagist does not currently expose
`assinafy/php-sdk`. Use the tagged VCS/path-repository instructions in
[docs/INSTALLATION.md](docs/INSTALLATION.md), and switch to `assinafy/php-sdk:^2.0` after
Packagist publication.

Current repository `main` covers all 89 operations on the 67 paths in the 2026-08-19 OpenAPI
document. Five additional template-management method/path pairs remain because the running
sandbox supports them. Two legacy OAuth URL routes are also retained for compatibility outside
OpenAPI, but their upstream redirects are currently misconfigured and are not operational.

### PHP 8.2 is now the minimum

1.x allowed `^7.4|^8.0`. PHP 7.4, 8.0 and 8.1 are all end-of-life and receive no security
patches, so 2.0.0 requires `^8.2`. The CI matrix covers 8.2 through 8.5.

No action needed beyond running a supported PHP version.

### Webhook signature verification was removed

**This is the change most likely to affect you, and it is a bug fix rather than a loss of
functionality.**

1.x shipped `WebhookVerifier::verify()`, which computed an HMAC-SHA256 over the request body
using a configured `webhook_secret`. The Assinafy API implements no such mechanism:

- `secret` appears nowhere in the published OpenAPI spec.
- `PUT /accounts/{id}/webhooks/subscriptions` accepts only `events`, `is_active`, `url` and
  `email` — there is no field in which to register a signing key.
- Real deliveries carry no signature header.

`verify()` therefore could never return `true` for a genuine delivery, yet the 1.x README and
examples told you to use it as a rejection guard:

```php
// 1.x — this dropped EVERY webhook.
if (!$verifier->verify($payload, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}
```

If you copied that snippet, your endpoint has been rejecting all events. If you wired it up
and "webhooks never worked", this is why.

**Migration:**

| 1.x | 2.0.0 |
|---|---|
| `$client->webhookVerifier()` | `$client->webhookEvents()` |
| `Support\WebhookVerifier` | `Support\WebhookEventParser` |
| `$verifier->verify($payload, $sig)` | *(removed — delete the check)* |
| `$verifier->extractEvent($payload)` | unchanged |
| `$verifier->getEventType($event)` | unchanged |
| `$verifier->getEventData($event)` | unchanged — returns the `object` entity |
| *(none)* | `$parser->getEventPayload($event)` — the `payload` key, previously unreachable |
| *(none)* | `$parser->getAccountId($event)` |

```php
// 2.0.0
$parser = $client->webhookEvents();
$event  = $parser->extractEvent(file_get_contents('php://input'));

if ($event === null) {
    http_response_code(400);
    exit('Malformed payload');
}

// Re-fetch rather than trusting the payload — see README "Securing your webhook endpoint".
$document = $client->documents()->get($parser->getEventData($event)['id']);
http_response_code(200);
```

`getEventData()` previously read `$event['data'] ?? $event['object'] ?? []`. Real deliveries
have no `data` key, so it always fell through to `object` — behaviour is unchanged; only the
dead branch is gone.

### `Configuration`: `$webhookSecret` removed

The fourth constructor argument is gone, along with `getWebhookSecret()`. It existed solely to
feed the removed verifier.

```php
// 1.x
new Configuration($apiKey, $accountId, $baseUrl, $webhookSecret, $timeout, $connectTimeout);
new Configuration('k', 'a', $url, null, 30, 10);

// 2.0.0
new Configuration($apiKey, $accountId, $baseUrl, $timeout, $connectTimeout);
new Configuration('k', 'a', $url, 30, 10);
```

`AssinafyClient::create()` likewise drops its `$webhookSecret` parameter.

If you passed positionally, `$timeout` now sits where `$webhookSecret` did — a `string` in an
`int` slot raises a `TypeError` immediately rather than misbehaving silently.

`Configuration::fromArray()` still **accepts and ignores** a `webhook_secret` key, so config
arrays carried over from 1.x keep working:

```php
Configuration::fromArray([
    'api_key'        => '…',
    'account_id'     => '…',
    'webhook_secret' => '…',  // ignored, no error
]);
```

### Remote base URLs now require HTTPS

To prevent credentials from crossing cleartext connections, 2.0 rejects `http://` base URLs for
remote hosts. Plain HTTP remains available only for loopback development hosts: `localhost`,
`*.localhost`, `127.0.0.1`, and `::1`. Base URLs must be absolute and cannot embed credentials, a
query string, or a fragment.

Replace a remote `http://` endpoint with a valid HTTPS URL and trusted certificate. Do not disable
TLS verification. Local test servers may continue to use, for example,
`http://127.0.0.1:8080/v1`.

### `HttpClientInterface` changed

Only relevant if you implement the interface yourself (the shipped `GuzzleHttpClient` is
unaffected):

- **Added** `patch(string $uri, ?array $data = null, array $headers = [], array $query = []): Response`.
- **Changed** `post()` and `put()` to the same nullable-data convention and added the optional
  query argument: `(?array $data = null, array $headers = [], array $query = [])`.
- **Changed** `delete()` to
  `(string $uri, array $headers = [], array $query = [], array $data = [])` so callers can send
  query parameters or the JSON body used by account deletion.

For `post()`, `put()`, and `patch()`, `null` means no request body. Passing an explicit array,
including `[]`, sends JSON. This distinction is required by no-body operations such as signer
terms acceptance and webhook deactivation. Update custom implementations to match the nullable
signatures exactly.

Guzzle is now a required runtime dependency
(`guzzlehttp/guzzle:^7.15.2 || ^8.0.2`), not a development-only suggestion. The SDK supports both
Guzzle 7 and 8 and normalizes their different exception hierarchies. The SDK transport remains
its own `HttpClientInterface`; 2.0 does not claim PSR-18 interoperability.

If your application subclasses an SDK resource, review overridden method signatures before
upgrading. Several public resource methods gained optional token, query, or payload parameters,
and PHP requires compatible overrides. Resource classes remain extensible, but overriding them
is an advanced integration point rather than a version-stable interface; prefer composition for
application-specific behavior.

### `list()` now returns a `pagination` key

Additive — `data` is untouched, so existing code keeps working.

The API sends no pagination in the body; there is no `meta` key on any endpoint and never
was. Pagination arrives in `X-Pagination-*` response headers, which 1.x captured and then
discarded, so callers could not paginate deterministically. 2.0.0 lifts them into the result:

```php
$result = $client->documents()->list(1, 20);
$result['data'];                       // unchanged
$result['pagination']['total_count'];  // new
$result['pagination']['page_count'];   // new
```

If you read `$page['meta']` in 1.x you were reading a key that never existed and getting
`null`. Use `pagination` instead. Endpoints that don't paginate omit the key — check with
`isset()`.

### `estimateCost()` no longer requires signer IDs

A bug fix; nothing to change unless you worked around it.

The API prices an assignment off the verification and notification methods alone, and the
docs say IDs "are not required" — but 1.x threw `ValidationException('Signer entry missing
id')` before issuing any request, making the documented flow unreachable:

```php
// Throws in 1.x, works in 2.0.0.
$client->assignments()->estimateCost($documentId, [
    ['verification_method' => 'Email', 'notification_methods' => ['Email']],
]);
```

`create()` still requires IDs — it has to know who signs.

Current `main` also matches the ordinary-assignment runtime rules: at most one notification
method; for Email/WhatsApp verification a non-empty notification must match; supplying only one
side lets the API infer the other; and omitting both defaults to Email. DigitalCertificate is
exempt from channel equality. An explicit `notification_methods: []` remains a sandbox-verified
`200` cost-estimate exception and is preserved. Remove payloads that send both Email and WhatsApp
or pair different non-empty Email/WhatsApp channels.

### Signer phone numbers require an explicit country code

The 1.x private normalizer stripped every non-digit and blindly prepended `+`, which could turn a
local number into an ambiguous international value. Create/update now require a leading `+` and
country code, accept common visual separators, and enforce 8–15 digits. The shared behavior is
public for callers that normalize input before building a request:

```php
$phone = SignerResource::normalizePhoneNumber('+55 (48) 99999-0000');
// +5548999990000
```

Local numbers such as `48999990000` now throw `ValidationException`; add the correct country code
at the source rather than assuming one in the SDK.

### Credentials are redacted from logs

No API change, but worth knowing if you parse your own logs.

1.x logged the whole request options array at debug level, writing plaintext passwords
(`login()`, `generateApiKey()`, `changePassword()`, `resetPassword()`), `Authorization`
bearer tokens, and response `access_token`s into your log files. 2.0.0 replaces those values
with `[redacted]`. Rotate any credential that may have been captured in existing logs.

### New: `$client->accounts()`

The `Accounts` tag was entirely unimplemented in 1.x. `accounts()->list()` is the documented
way to discover the account ID that every other resource needs. A `forAuth()` client is public
and sends no credentials, so pass the Bearer token returned by login:

```php
$bootstrap = AssinafyClient::forAuth(Configuration::SANDBOX_BASE_URL);
$session = $bootstrap->auth()->login($email, $password);
$accounts = $bootstrap->accounts()->list($session['access_token']);
```

### New: global Bearer authentication

API-key clients continue to work. For a user-session workflow, configure the access token once
and every workspace resource will send `Authorization: Bearer ...`:

```php
$client = AssinafyClient::forBearer(
    accessToken: $session['access_token'],
    accountId: $accounts['data'][0]['id'],
    baseUrl: Configuration::SANDBOX_BASE_URL,
);

$profile = $client->users()->get();
$documents = $client->documents()->list();
```

The per-call token arguments remain useful on a public bootstrap client. API-key lifecycle
methods now use nullable tokens:

```php
$client->auth()->generateApiKey(null, $currentPassword);
$client->auth()->getApiKey();
$client->auth()->deleteApiKey();
$client->auth()->changePassword(null, $email, $currentPassword, $newPassword);
```

Passing `null` uses the client's configured API key or global Bearer token. On a `forAuth()`
client, supply the explicit login token instead; the SDK rejects an unauthenticated call before
network I/O.

### Signer access codes moved to the query string

All signer-facing methods now follow the OpenAPI security scheme and send the credential as
`?signer-access-code=...`. If a custom transport or recorded request assertion expected the code
inside JSON, update it. `signerSession()->acceptTerms($accessCode)` sends the query parameter and
no request body; `verifyCode()` sends only `{ "verification-code": "..." }` as JSON.

Do not derive this code from an assignment's `signing_urls`. Those objects expose `signer_id` and
`url`, but the sandbox does not expose the one-time access code in a usable URL path segment; the
attempt returned `401` on 2026-08-05. Use `documents()->sendToken()` for a signer already assigned
to the document, then obtain the code from that signer's controlled inbox. Live signer-read tests
require explicit `ASSINAFY_SIGNER_ID` and `ASSINAFY_SIGNER_ACCESS_CODE` values and are independent
from notification-delivery tests.

### Legacy browser OAuth URL builders are not operational

`auth()->socialLoginUrl()` and `auth()->socialLoginCallbackUrl()` remain for compatibility, but
their two GET routes are no longer in OpenAPI. On 2026-08-19, sandbox produced a malformed
array-style `redirect_uri`, while production produced an example Google client ID and an HTTP
`api-dev` callback. Do not migrate an OAuth flow to these helpers until Assinafy publishes and
deploys a corrected contract.

### Preserve documented/runtime divergences

- Document-tag list/replace/append/detach operations are in the current OpenAPI document and
  map directly to `DocumentResource` methods. Although the body description calls the strings
  tag IDs, the sandbox and SDK use tag names and auto-create missing names.
- `GET /users/self` is published with `data: AuthUser`, but the sandbox returns
  `data: {user: AuthUser, accounts: AuthAccount[]}`. `users()->get()` now normalizes either shape
  to `AuthUser`; code using the SDK method keeps a stable return, while code coupled to a raw
  transport response should account for the nested sandbox payload.
- The published account and authenticated-user statistics routes both returned an
  application-level `404` route-not-deployed response in the sandbox on 2026-08-05. The SDK
  retains `accounts()->stats()` and `users()->stats()` for 89/89 OpenAPI coverage, but callers
  must treat deployment availability separately from publication in the contract.
- Public `send-token` is documented with `{email}`, but the sandbox requires
  `{recipient, channel}` and accepts only a recipient who is already a signer assigned to the
  target document. The SDK deliberately uses the runtime request shape; ensure the assignment
  exists before calling it.
- Template list, create-document-from-template, and template document-cost estimation are
  published. Template create/get/update/delete/page-download are not, but remain available
  because live tests prove the routes work. Do not remove them based only on an OpenAPI diff.

## v2.0.0 → v2.1.0

These additions are released in `v2.1.0`.

### Notification preferences

`users()->notificationPreferences()` maps the new GET operation and returns all nine booleans.
`users()->updateNotificationPreferences($partial)` maps PUT, rejects an empty/unknown/non-boolean
map locally, merges omitted keys unchanged, and returns the full map. The keys are
`DocumentCompleted`, `SignerDeclined`, `DocumentCancelled`, `DocumentAboutToExpire`,
`DocumentExpired`, `DocumentExpirationReset`, `DocumentProcessingFailed`,
`TemplateProcessingFailed`, and `SignerWhatsappFailed`.

Both methods are part of the current production OpenAPI contract but were not deployed in the
sandbox on 2026-08-19; GET returned application-level `404` (`Página não encontrada`). Do not
make a sandbox rollout depend on either route until Assinafy deploys them there.

### Digital-certificate assignments and artifacts

Assignment and template create/estimate payloads now accept `verification_method:
DigitalCertificate`. The contract requires the account feature, an existing signer with CPF/CNPJ
in `government_id`, and a signing step containing only that signer. Estimation adds two credits
per signer under `SignatureDigitalCertificate`, plus notification cost. Set the identifier first:

```php
$client->signers()->update($signerId, ['government_id' => $cpfOrCnpj]);
```

The SDK sends these published request shapes and validates the ordinary-assignment constraints;
template signer arrays remain pass-through for the live divergence below. The sandbox returned
`400` (`Invalid method`) for certificate assignment creation on 2026-08-19. The ordinary
`signerSession()->sign()` method cannot finish an ICP-Brasil certificate signature, and OpenAPI
defines no certificate start/complete routes; none were added speculatively.

Document and signer-document downloads now accept the `pades` artifact. It exists only for a
document with digital-certificate signers. A `bundle` is a ZIP of the original, certificated, and
certificate-page artifacts plus `pades` when present.

### Signer confirmation and contact updates

The `confirm-data` schema still lists only `full_name`, `email`, and `government_id`, while
`GET /sign` prose requires a DigitalCertificate signer to send `has_accepted_terms: true` in that
body. The SDK forwards it. Passing the similarly named query to `GET /sign` is too late to open
the gate, and the endpoint now documents `400` for missing confirmation/acceptance.

Signer update now accepts `government_id`. Formatted CPF/CNPJ input is accepted and normalized to
digits by the server, but signer responses omit that field and must not be used to verify it by
echo. Updating an already verified email/WhatsApp channel
on an in-flight document returns `400`; updating an unverified channel rotates its access and
verification codes, so resend the invitation. Certificated documents do not block channel
changes.
