# Upgrading

Install a published 2.x release with `composer require assinafy/php-sdk:^2.1`. Remove obsolete
VCS/path repository overrides if they prevent Composer from resolving the Packagist package.
Use the documentation shipped with the installed tag; `main` may include unreleased changes.

## Upgrading to 2.1.4

The transport rejects an injected concrete Guzzle client with a default `auth` option. Supply
workspace authentication through `Configuration`; supply explicit per-request Bearer headers only
where the operation supports them. Default `Authorization` and `X-Api-Key` headers remain rejected.

Transport exception causes expose the exception class and numeric code without retaining the
original Guzzle exception/request, including when PHP records exception arguments. Code inspecting
`getPrevious()` must not expect a Guzzle request object. `LogRedactor` also masks keys named `code`
and `code_verifier` for OAuth credentials. The SDK User-Agent is `Assinafy-PHP-SDK/v2.1.4`.

`estimateResendCost()` returns `total`, `breakdown`, `credit_balance`, and
`has_sufficient_credits`. Update resend guards to use that credit flag; assignment creation
estimates continue to use `has_sufficient_resources`. Source archives omit local configuration,
environment files, and installed dependencies.

No resource method was removed. Marketplace OAuth integration uses the existing public HTTP
transport and `forBearer()`; see [docs/OAUTH.md](docs/OAUTH.md) for the full lifecycle.
GitHub CI does not run sandbox tests. Live tests remain explicit local commands and an optional
protected GitLab job.

## Runtime and transport requirements for 2.x

Use PHP 8.2 or later with `json`, `mbstring`, Composer 2 and valid TLS trust roots. The supported
matrix covers PHP 8.2–8.5. Guzzle `^7.15.2 || ^8.0.2` is a runtime dependency.

Remote base URLs require HTTPS. Only `localhost`, `*.localhost`, `127.0.0.1`, and `::1` allow HTTP
for local development. Base URLs cannot contain credentials, queries or fragments. Timeouts must
be positive integers. Injected Guzzle base URIs must match the configured API base, including its
trailing slash; direct transport requests use relative paths beneath that base.

Custom `HttpClientInterface` implementations must support these signatures:

```php
use Assinafy\SDK\Http\Response;

interface HttpClientInterface
{
    public function get(string $uri, array $params = [], array $headers = []): Response;
    public function post(string $uri, ?array $data = null, array $headers = [], array $query = []): Response;
    public function put(string $uri, ?array $data = null, array $headers = [], array $query = []): Response;
    public function patch(string $uri, ?array $data = null, array $headers = [], array $query = []): Response;
    public function delete(string $uri, array $headers = [], array $query = [], array $data = []): Response;
    public function uploadFile(string $uri, string $filePath, array $data = [], array $headers = []): Response;
    public function postRaw(string $uri, string $body, string $contentType, array $query = [], array $headers = []): Response;
}
```

For POST/PUT/PATCH, `null` omits the body and an explicit array, including `[]`, sends JSON.
DELETE omits an empty body. Send `Assinafy-PHP-SDK/v{Configuration::SDK_VERSION}` on every request,
including public, signer, multipart and binary calls. The bundled transport disables redirects,
validates successful JSON/envelopes, and raises `ApiException` for unsuccessful envelope statuses
as well as unsuccessful HTTP statuses. Invalid response structure raises `NetworkException`.

Review method-signature compatibility if extending resource classes. Prefer composing resources
in application services rather than overriding their behavior.

## Configuration and authentication

`Configuration` takes `(apiKey, accountId, baseUrl, timeout, connectTimeout, accessToken)`.
Remove positional `webhookSecret` arguments from `Configuration` and `AssinafyClient::create()`;
`getWebhookSecret()` is unavailable. The legacy `webhook_secret` array key is accepted and ignored.
Supply an API key or a global Bearer token, never both.

Use `AssinafyClient::forAuth()` for public authentication. Discover accounts by passing the login
or OAuth access token to `accounts()->list($token)`, then construct
`AssinafyClient::forBearer($token, $accountId)`. Nullable per-call token arguments fall back to the
configured API key or global Bearer credential. An unauthenticated public client must receive an
explicit token for protected bootstrap operations.

`generateApiKey(null, $password)`, `getApiKey()`, `deleteApiKey()` and
`changePassword(null, $email, $currentPassword, $newPassword)` use configured authentication.
Credential rotation/deletion should target a disposable user during testing. Do not log token or
password responses. Legacy social URL builders are separate from marketplace OAuth authorization.

## Webhook handling

Use `$client->webhookEvents()` and `Support\WebhookEventParser`. Remove calls to
`webhookVerifier()` and `WebhookVerifier::verify()`: Assinafy deliveries are unsigned.
`extractEvent()` decodes the envelope, `getEventType()` reads `event`, `getEventData()` reads
`object`, `getEventPayload()` reads `payload`, and `getAccountId()` reads `account_id`.

```php
$payload = file_get_contents('php://input');
$parser = $client->webhookEvents();
$event = is_string($payload) ? $parser->extractEvent($payload) : null;
if ($event === null) {
    http_response_code(400);
    exit;
}
$entity = $parser->getEventData($event);
// Deduplicate the event and re-fetch the referenced entity before consequential work.
http_response_code(200);
```

Use HTTPS, an unguessable endpoint path, request limits and idempotent processing. Registration
requires URL, email, events and `is_active`; it replaces the workspace's single subscription.
Use `deactivate()` to stop delivery and `activate()` to resume. A new workspace can return a
subscription object with an empty URL; `get()` returns null only for empty/null response data.

## Document and signer behavior

- Read native `id` fields. Paginated list methods retain the envelope and add `pagination` from
  response headers. Use `pagination.page_count` and `pagination.total_count`; do not read `meta`.
  Unpaginated tags, fields, catalogs and activities return direct arrays.
- Upload readable PDFs up to 25 MB with valid header/EOF markers. Rename before signing starts.
  `waitUntilReady()` waits for preparation; `isFullySigned()` recognizes `ready`, `certificating`
  and `certificated`. Final artifact availability may lag signature completion.
- Assignments use `signers: [{id, verification_method, notification_methods, step}]`.
  Cost estimates do not require signer IDs. Ordinary assignments accept at most one notification
  method, matching Email/Whatsapp verification; omitted sides are inferred. Empty notification
  arrays are preserved. Template signer arrays retain their own deployed behavior.
- Provide explicit international phone prefixes, such as `+12025550123`. Local/ambiguous numbers
  raise `ValidationException`. Reusing an email match does not update the stored name or phone.
- Signer updates accept `government_id`. Responses may omit or mask the identifier. Updating a
  verified channel on an in-flight assignment may be rejected; changing an unverified channel can
  rotate its codes, requiring invitation resend.
- DigitalCertificate assignments require the account feature, CPF/CNPJ and an isolated signing
  step. Use `pades` only for certificate-signed documents. The ordinary signer-session sign method
  does not implement certificate completion.
- Public send-token uses `{recipient, channel: 'email'}` for a signer already assigned to the
  document. Its result contains the public document projection, channel and recipient.
- Signer methods send `signer-access-code` as a query parameter. Update custom transport assertions
  accordingly. Signing URL paths do not expose that code; obtain it through the signer's channel.
- `confirmData()` forwards `has_accepted_terms`; for certificate signers confirm identity and terms
  before loading the current document. Virtual signing uses `[]`; collect uses field-value arrays.
- API-created templates initially have an Editor role. Configure signing roles in the web app
  before generating documents. Template management remains available outside OpenAPI.

## Validation

Run `composer check` for the development gate. Run the live integration suite explicitly with
sandbox environment credentials and the required opt-in switches. See [README.md](README.md) for
commands, cleanup behavior and the extra credentials needed for signer, user-account and OAuth flows.
