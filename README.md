# Assinafy PHP SDK

Framework-independent PHP client for the [Assinafy v1 API](https://api.assinafy.com.br/v1/docs).

The SDK exposes every operation on all 67 paths in the current OpenAPI document (89/89). Every
published JSON, multipart, binary, and signer operation has a typed resource method. Five
template-management operations outside OpenAPI are retained because sandbox regression tests
prove they work. Two legacy OAuth URL builders also target routes outside OpenAPI, but the
upstream redirects are currently misconfigured and are not advertised as an operational login
flow.

The complete method-by-method contract—including HTTP method, path, authentication, request
payload, response payload, errors, and documented/runtime differences—is in
[docs/API_REFERENCE.md](docs/API_REFERENCE.md).

## Requirements

- PHP 8.2 through PHP 8.5 (the complete supported CI matrix)
- `ext-json`
- `ext-mbstring`
- Composer 2

Guzzle is a runtime dependency and powers the default HTTP transport. PSR-3 loggers are
supported. The package does not claim PSR-18 interoperability; its injectable transport uses the
SDK's own `HttpClientInterface`.

## Installation

Version 2.1.0 is released as repository tag `v2.1.0`, but Packagist does not yet expose
`assinafy/php-sdk`. After the package is published to Packagist:

```bash
composer require assinafy/php-sdk:^2.1
```

For installation of the `v2.1.0` tag before Packagist publication, use a Composer VCS/path repository as
described in [docs/INSTALLATION.md](docs/INSTALLATION.md). Never commit API keys to Composer
configuration, application source, fixtures, or CI files.

This README describes the `v2.1.0` release and current repository `main`. Use the documentation
shipped with a tag when installing that tag.

## Quick start

```php
<?php

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;

$client = AssinafyClient::create(
    apiKey: getenv('ASSINAFY_API_KEY'),
    accountId: getenv('ASSINAFY_ACCOUNT_ID'),
    baseUrl: Configuration::SANDBOX_BASE_URL,
);

$result = $client->uploadAndRequestSignatures(
    filePath: '/absolute/path/to/contract.pdf',
    signers: [
        [
            'full_name' => 'Alex Example',
            'email' => 'alex@example.test',
            'verification_method' => 'Email',
            'notification_methods' => ['Email'],
            'step' => 1,
        ],
    ],
    message: 'Please sign this contract',
    expiresAt: '2027-12-31T23:59:00Z',
);

echo $result['document']['id'];
echo $result['assignment']['id'];
```

`uploadAndRequestSignatures()` validates every signer before uploading, creates signers supplied
as `{full_name, email, whatsapp_phone_number?}`, preserves per-signer verification/notification
settings, waits for document processing, and creates the assignment. If a later remote step
fails, earlier remote objects are not automatically rolled back; catch the exception and clean up
the returned/known IDs according to your application's workflow.

An exact email match is reused as stored; the helper does not update that signer's supplied name
or phone. If the assignment needs WhatsApp, update and verify the stored signer before invoking
the helper. DigitalCertificate entries cannot use email reuse: supply an existing signer ID after
setting its `government_id` with `signers()->update()`.

## Configuration and authentication

```php
use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;

$config = new Configuration(
    apiKey: getenv('ASSINAFY_API_KEY'),
    accountId: getenv('ASSINAFY_ACCOUNT_ID'),
    baseUrl: Configuration::DEFAULT_BASE_URL,
    timeout: 30,
    connectTimeout: 10,
);

$client = new AssinafyClient($config);
```

Use `Configuration::SANDBOX_BASE_URL` while developing. Remote/custom base URLs must use HTTPS.
Plain HTTP is accepted only for loopback development hosts (`localhost`, `*.localhost`,
`127.0.0.1`, or `::1`). Base URLs cannot embed credentials, a query, or a fragment; timeouts
must be positive.

For login, public document verification, or signer-access-code flows, create a client without
workspace credentials:

```php
$bootstrap = AssinafyClient::forAuth(Configuration::SANDBOX_BASE_URL);
$session = $bootstrap->auth()->login('developer@example.test', 'password-from-a-secret-store');

$accounts = $bootstrap->accounts()->list($session['access_token']);
$user = $bootstrap->users()->get($session['access_token']);
```

The published `/users/self` response declares `data: AuthUser`, but the sandbox currently sends
`data: {user: AuthUser, accounts: AuthAccount[]}`. `users()->get()` normalizes either wire shape
and returns the `AuthUser` object; use `accounts()->list()` when you need the account collection.

A public client omits both `X-Api-Key` and `Authorization`. Bootstrap-capable account, user, and
authentication methods accept an explicit Bearer token. Once an account ID is known, a global
Bearer client applies the login token to every workspace resource:

```php
$bearerClient = AssinafyClient::forBearer(
    accessToken: $session['access_token'],
    accountId: $accounts['data'][0]['id'],
    baseUrl: Configuration::SANDBOX_BASE_URL,
);

$profile = $bearerClient->users()->get();
$documents = $bearerClient->documents()->list();

// Nullable per-call tokens fall back to the client's configured authentication.
$maskedApiKey = $bearerClient->auth()->getApiKey();
$generatedApiKey = $bearerClient->auth()->generateApiKey(
    accessToken: null,
    password: (string) getenv('ASSINAFY_CURRENT_PASSWORD'),
);
```

`generateApiKey()` and `changePassword()` require a nullable token argument before their other
required values; pass `null` to use configured API-key or global-Bearer authentication.
`getApiKey()` and `deleteApiKey()` default that argument to `null`. On a public bootstrap client,
pass the login token explicitly. API-key, Bearer-token, and signer-access-code schemes remain
separate.

The current production OpenAPI publishes methods to read all nine owner-facing email preferences
or merge a partial update. When deployed, every read and update returns the complete map; omitted
update keys keep their current values:

```php
$preferences = $client->users()->notificationPreferences();
// [
//   'DocumentCompleted' => true,
//   'SignerDeclined' => true,
//   'DocumentCancelled' => true,
//   'DocumentAboutToExpire' => true,
//   'DocumentExpired' => true,
//   'DocumentExpirationReset' => true,
//   'DocumentProcessingFailed' => true,
//   'TemplateProcessingFailed' => true,
//   'SignerWhatsappFailed' => true,
// ]

$preferences = $client->users()->updateNotificationPreferences([
    'DocumentAboutToExpire' => false,
]);
```

Neither notification-preference route was deployed in the sandbox on 2026-08-19; its GET returned
an application-level `404` (`Página não encontrada`). Treat the example above as contract usage
for an environment where Assinafy has deployed the routes, not as a runnable sandbox call.

Account/security emails such as password resets and invitations are not configurable through
these methods.

## Resources

Resource accessors are lazily created and reused:

| Accessor | Scope |
| --- | --- |
| `accounts()` | Workspace CRUD, theme/logo, and published document statistics (currently unavailable in sandbox) |
| `users()` | Authenticated user plus published notification preferences and cross-account statistics (the latter routes are currently unavailable in sandbox) |
| `documents()` | Upload, search/list, metadata, artifacts, tags, templates, public endpoints |
| `signers()` | Workspace signer CRUD/search |
| `assignments()` | Signature requests, estimates, resend, expiration, notification history |
| `templates()` | Runtime-supported template upload/list/get/update/delete/page download/polling |
| `tags()` | Workspace tags |
| `fields()` | Field definitions, catalog, single/bulk validation |
| `webhooks()` | Subscription, event types, dispatch history, retry |
| `auth()` | Login, social auth, API-key/password lifecycle, and legacy non-operational OAuth URL builders |
| `signerSession()` | Terms, OTP verification, identity confirmation, signature/sign/decline |
| `signerDocuments()` | Signer current/list/search/bulk actions/artifact download |
| `webhookEvents()` | Defensive webhook-envelope parsing helpers |

See [docs/API_REFERENCE.md](docs/API_REFERENCE.md) for all public methods and exact payloads, and
[docs/EXAMPLES.md](docs/EXAMPLES.md) for longer workflows.

Signer phone inputs require an explicit `+` and country code. The public static
`SignerResource::normalizePhoneNumber()` removes common visual separators, validates 8–15
digits, and never guesses a country for a local number.

Ordinary assignment create/estimate requests allow at most one notification method. For Email or
WhatsApp verification, a non-empty notification must match; when only one side is supplied, the
API infers the other, and omitting both defaults to Email. DigitalCertificate is exempt from the
channel-equality rule. The SDK preserves an explicit `notification_methods: []`; an ordinary
assignment estimate with that body was live-verified `200`.

`DigitalCertificate` is accepted by assignment and template create/estimate methods. It costs two
credits per signer in addition to notification cost, requires the account feature and a signer
with `government_id`, and the signer must be alone in its signing step. Set the identifier with
`signers()->update()` before assignment creation. Formatted CPF/CNPJ input is accepted and
normalized to digits by the server; signer responses do not expose `government_id`, so do not
expect the update response to echo it. The ordinary `signerSession()->sign()` route
cannot complete an ICP-Brasil certificate signature, and the published contract defines no
certificate start/complete operations. The sandbox returned `400` (`Invalid method`) for a
digital-certificate assignment on 2026-08-19, so this flow is contract-supported but not claimed
as operational in that environment.

## Responses and pagination

Single-object operations return the API envelope's `data` value. Collection operations either
return the documented collection or an envelope with `data` plus normalized pagination:

```php
$result = $client->documents()->list(page: 1, perPage: 100);

foreach ($result['data'] as $document) {
    echo $document['id'];
}

$nextPage = $result['pagination']['current_page'] + 1;
$pageCount = $result['pagination']['page_count'];
```

Assinafy provides pagination through `X-Pagination-*` response headers, not a `meta` body field.
The SDK exposes `current_page`, `page_count`, `per_page`, and `total_count`. Pages begin at 1 and
`perPage` is limited to 1–100.

Binary download methods return raw bytes. Artifact names are `original`, `certificated`,
`certificate-page`, `pades`, and `bundle`; `pades` exists only for documents with
digital-certificate signers, and `bundle` is a ZIP that includes it when present. Empty/204
responses return an empty array. The default
transport rejects every non-empty, non-binary response that is not a JSON object or array with a
`NetworkException`; this prevents HTML proxy pages and unexpected JSON scalars from looking like
successful API responses. A standalone `Response` value remains a tolerant parser and exposes
`null` for scalar or malformed JSON.

## Signer-facing authentication

Signer operations use the exact `signer-access-code` query parameter declared by OpenAPI:

```php
$profile = $client->signerSession()->self($accessCode);
$client->signerSession()->acceptTerms($accessCode); // query credential, no request body
$documents = $client->signerDocuments()->list($signerId, $accessCode);
$matches = $client->signerDocuments()->search($signerId, $accessCode, 'contract');
```

Treat signer access codes and signing URLs as credentials. Do not log or persist them in plain
text. The built-in transport redacts API keys, Bearer tokens, access codes, passwords, OTPs, and
credentials embedded inside response URLs.

Assignment `signing_urls` contain signer IDs and URLs, but they do not expose the one-time
`signer-access-code`. A 2026-08-05 sandbox attempt to derive the code from a URL path segment was
invalid and received `401`. Request delivery with public `sendToken()` and obtain the code from
the assigned signer's controlled inbox; never scrape or guess it from `signing_urls`.

## Webhooks

```php
use Assinafy\SDK\Resources\WebhookResource;

$client->webhooks()->register(
    url: 'https://hooks.example.test/assinafy/a-long-random-path',
    email: 'ops@example.test',
    events: WebhookResource::DEFAULT_EVENTS,
);

$event = $client->webhookEvents()->extractEvent(file_get_contents('php://input'));
if ($event === null) {
    http_response_code(400);
    exit;
}

// Deliveries are not signed by the current v1 API. Treat the event as a hint and
// re-fetch the referenced entity with authenticated API credentials before acting.
```

The v1 subscription contract has no webhook secret/signature field or signature header. Use TLS,
an unguessable endpoint, strict HTTP-method/body limits, idempotency keyed by event ID, and an API
re-fetch before performing consequential work.

## Errors and logging

```php
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\NetworkException;
use Assinafy\SDK\Exceptions\ValidationException;

try {
    $client->documents()->get($documentId);
} catch (ValidationException $e) {
    // Local input failed before a network request.
} catch (ApiException $e) {
    $status = $e->getStatusCode();
    $details = $e->getResponseData();
} catch (NetworkException $e) {
    // DNS, connection, or timeout failure.
}
```

Inject any PSR-3 logger in the client constructor or call `setLogger()`. Logger changes propagate
to already-created resources and the default transport. Redirect following is disabled so a
cross-origin redirect cannot receive the custom API-key header.

## Confirmed specification differences

The SDK documents sandbox behavior where it differs from the current OpenAPI document:

| Area | Published contract | Confirmed sandbox behavior / SDK choice |
| --- | --- | --- |
| Document tags | List/replace/append/detach are current operations; the body description calls values tag IDs | Sandbox and SDK use tag names and auto-create missing names; these routes remain distinct from undocumented template management |
| Authenticated-user payload | `GET /users/self` declares `data: AuthUser` | Sandbox returns `data: {user: AuthUser, accounts: AuthAccount[]}`; the SDK normalizes `data.user` to the documented `AuthUser` return |
| Statistics deployment | `GET /accounts/{accountId}/stats` and `GET /users/self/stats` are published operations | Both returned an application-level `404` route-not-deployed response in the sandbox on 2026-08-05; SDK methods remain for 89/89 contract coverage, but sandbox availability must not be assumed |
| Notification-preference deployment | GET and PUT `/users/self/notification-preferences` are published operations | Neither route was deployed in sandbox on 2026-08-19; GET returned application-level `404` (`Página não encontrada`), so SDK methods are contract-complete but no sandbox round trip is claimed |
| Public send-token | Body is shown as `{email}` | Sandbox requires `{recipient, channel}`, and `recipient` must belong to a signer already assigned to the document; the SDK preserves the runtime body shape |
| Assignment list | Documents only pagination | Sandbox also requires camel-case `accountId`; the SDK supplies the configured account ID |
| Ordinary assignment notification rules | Schema prose permits any Email/WhatsApp combination | Runtime allows at most one method, couples/infer Email or WhatsApp, yet accepts explicit `[]`; SDK validates the one-method/coupling rules and preserves `[]` |
| Template notification rules | Template create prose says one method and inference; estimate is looser | Sandbox template create and estimate accepted duplicate `['Email', 'Email']`; the SDK does not apply ordinary-assignment constraints to these template payloads |
| Pagination | Response descriptions are inconsistent | Sandbox uses `X-Pagination-*`; the SDK normalizes those headers |
| Template management | List, document creation, and document-cost estimation are published | Create/get/update/delete/page-download remain live and tested outside OpenAPI, so no working functionality was removed |
| OAuth start/callback | Both GET routes are absent from current OpenAPI | Compatibility URL builders remain, but sandbox and production redirect configuration is currently invalid; do not use them as an operational OAuth flow |
| Digital-certificate assignment | `DigitalCertificate` is published and costs two credits per signer | SDK request shaping supports it; sandbox assignment creation returned `400` (`Invalid method`), and no end-to-end certificate completion route is published |
| Certificate signer gate | `GET /sign` prose requires `confirm-data` with `has_accepted_terms: true`, but that field is absent from the `confirm-data` schema | The SDK forwards it; the optional `GET /sign` query is too late to open the digital-certificate gate, and `GET /sign` also documents `400` |
| `ready` status | The status catalog omits `ready` | Webhook prose and runtime use `ready`; SDK helpers retain `STATUS_READY` and callers should trust the actual returned status |

These differences and their regression status are also marked in the API reference.

## Verification

Local, non-network checks:

```bash
composer check
```

Equivalent individual commands:

```bash
composer test
composer phpstan
composer phpcs
composer audit:dependencies
composer validate --strict
```

Sandbox tests are deliberately opt-in and refuse production by default:

```bash
read -s ASSINAFY_API_KEY
export ASSINAFY_API_KEY ASSINAFY_ACCOUNT_ID='your-sandbox-account-id'
export ASSINAFY_INTEGRATION=1
export ASSINAFY_BASE_URL='https://sandbox.assinafy.com.br/v1'
vendor/bin/phpunit --testsuite=integration
```

Additional integration categories require explicit configuration:

```bash
# Sends real sandbox notifications to addresses you control.
export ASSINAFY_NOTIFICATION_TESTS=1
export ASSINAFY_TEST_EMAIL='first-address-you-control@example.test'
export ASSINAFY_TEST_EMAIL_ALT='second-address-you-control@example.test'

# Separately enables authenticated signer-read checks. Obtain the code from the controlled
# recipient inbox after send-token; assignment signing_urls do not contain it.
export ASSINAFY_SIGNER_ID='assigned-signer-id'
read -s ASSINAFY_SIGNER_ACCESS_CODE
export ASSINAFY_SIGNER_ACCESS_CODE

# Creates and then permanently deletes only a disposable sandbox workspace.
export ASSINAFY_DESTRUCTIVE_TESTS=1
```

Notification coverage and authenticated signer-read coverage are deliberately separate. A
successful notification request proves delivery was requested; it does not prove a signer read
succeeded. Signer-read checks run only when both `ASSINAFY_SIGNER_ID` and
`ASSINAFY_SIGNER_ACCESS_CODE` are supplied.

Password login/reset completion, OTP completion, social-provider login/linking, API-key deletion,
and password changes require disposable user credentials, inbox access, or provider tokens. They
are fully unit-tested but cannot be truthfully called successful end-to-end tests from an API key
and email addresses alone. The live suite never deletes the configured account or supplied API
key.

GitLab CI is the canonical pipeline; the mirrored GitHub Actions pipeline covers all supported
PHP versions, lowest/highest dependencies, static analysis, PSR-12, coverage, dependency audit,
and a production-dependency smoke test. A separate manual workflow runs only against the sandbox.

## Upgrading and license

See [UPGRADING.md](UPGRADING.md) and [CHANGELOG.md](CHANGELOG.md). Licensed under the [MIT
License](LICENSE).
