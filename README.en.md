# Assinafy PHP SDK

*[Leia em português](README.md) · English*

Framework-independent PHP client for the [Assinafy v1 API](https://api.assinafy.com.br/v1/docs).
It covers workspace administration, document preparation, signature requests, signer sessions,
artifacts, templates, tags, fields, and webhooks.

This guide follows a document from upload through certification. Every SDK method also carries
its own request and response payloads in its docblock, so an IDE shows the exact shapes at the
call site. For the same material as a single reference, use
[docs/API_REFERENCE.md](docs/API_REFERENCE.md); additional focused examples are in
[docs/EXAMPLES.md](docs/EXAMPLES.md).

Contributing or working on the SDK itself? [ARCHITECTURE.md](ARCHITECTURE.md) covers the internal
structure, and [Testing](#testing) below describes the quality gate and the live sandbox suite.

**Contents**

- [Requirements](#requirements) · [Installation](#installation)
- **Document workflow** — [1. Configure the client](#1-configure-the-client) ·
  [2. Upload and prepare the PDF](#2-upload-and-prepare-the-pdf) ·
  [3. Create or reuse signers](#3-create-or-reuse-signers) ·
  [4. Estimate the assignment](#4-estimate-the-assignment) ·
  [5. Assign and notify](#5-assign-and-notify) ·
  [6. Complete the signer flow](#6-complete-the-signer-flow) ·
  [7. Monitor progress](#7-monitor-progress) ·
  [8. Download and verify](#8-download-and-verify)
- [Organize documents with tags](#organize-documents-with-tags) ·
  [Reuse a template](#reuse-a-template) · [Receive webhooks](#receive-webhooks)
- [Responses and pagination](#responses-and-pagination) ·
  [Errors, logging, and secrets](#errors-logging-and-secrets) ·
  [Resource map](#resource-map)
- [Sandbox and production differences](#sandbox-and-production-differences) ·
  [Testing](#testing) · [Upgrading and license](#upgrading-and-license)

## Requirements

- PHP 8.2 through PHP 8.5
- `ext-json`
- `ext-mbstring`
- Composer 2
- TLS 1.2 or newer (the default client refuses older versions)

The default transport uses Guzzle. Applications may inject a PSR-3 logger or the SDK's own
`HttpClientInterface`; the transport is not a PSR-18 implementation.

## Installation

```bash
composer require assinafy/php-sdk
```

The package is published on Packagist as
[`assinafy/php-sdk`](https://packagist.org/packages/assinafy/php-sdk); no repository
configuration is needed. See [docs/INSTALLATION.md](docs/INSTALLATION.md) for version constraints
and development setup. Keep API keys and account identifiers in a secret manager or environment
variables, never in `composer.json`, source code, fixtures, or CI configuration.

## Document workflow

### 1. Configure the client

Use production unless the operation is intentionally a sandbox test:

```php
<?php

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;

$client = AssinafyClient::create(
    apiKey: (string) getenv('ASSINAFY_API_KEY'),
    accountId: (string) getenv('ASSINAFY_ACCOUNT_ID'),
    baseUrl: Configuration::DEFAULT_BASE_URL,
);
```

For development, change only the base URL:

```php
$sandbox = AssinafyClient::create(
    apiKey: (string) getenv('ASSINAFY_API_KEY'),
    accountId: (string) getenv('ASSINAFY_ACCOUNT_ID'),
    baseUrl: Configuration::SANDBOX_BASE_URL,
);
```

Use `Configuration` directly to control timeouts or inject a logger:

```php
$logger = new \Psr\Log\NullLogger(); // Replace with your application's PSR-3 logger.

$configuration = new Configuration(
    apiKey: (string) getenv('ASSINAFY_API_KEY'),
    accountId: (string) getenv('ASSINAFY_ACCOUNT_ID'),
    baseUrl: Configuration::DEFAULT_BASE_URL,
    timeout: 30,
    connectTimeout: 10,
);

$client = new AssinafyClient($configuration, logger: $logger);
```

The bundled transport enforces `User-Agent: Assinafy-PHP-SDK/v{SDK_VERSION}` on every request—for
example, version 2.4.2 sends `Assinafy-PHP-SDK/v2.4.2`. This applies to authenticated, public,
signer, JSON, multipart-upload, raw-body, and binary-download requests.
`Configuration::SDK_VERSION` is the single source for the header version.
Applications that replace the bundled `HttpClientInterface` transport must send the same exact
header on every Assinafy request.

Remote and custom base URLs must use HTTPS. Plain HTTP is accepted only for loopback development
hosts. Credentials, query strings, and fragments are rejected in base URLs, and timeouts must be
positive.

### Authentication modes

Workspace API-key authentication is the normal mode for document operations. Login and other
public operations start without workspace credentials:

```php
$public = AssinafyClient::forAuth(Configuration::DEFAULT_BASE_URL);
$session = $public->auth()->login(
    'developer@example.test',
    (string) getenv('ASSINAFY_PASSWORD'),
);

$accounts = $public->accounts()->list($session['access_token']);
```

After selecting an account, a Bearer client can call account-scoped resources:

```php
$bearerClient = AssinafyClient::forBearer(
    accessToken: $session['access_token'],
    accountId: $accounts['data'][0]['id'],
    baseUrl: Configuration::DEFAULT_BASE_URL,
);
```

### Marketplace OAuth

Use OAuth when your application acts on **someone else's** workspace, without ever handling their
password or API key. Automating your own workspace needs an API key instead.

`oauth()` returns an `OAuthResource` covering the whole flow: PKCE material, the authorization
URL, callback validation, the token exchange, refresh, revocation, userinfo and both discovery
documents. The [OAuth guide](docs/OAUTH.md) has the complete payloads, scope rules and go-live
checklist.

```php
use Assinafy\SDK\Resources\OAuthResource;

$oauth = AssinafyClient::forAuth()->oauth(
    (string) getenv('ASSINAFY_OAUTH_CLIENT_ID'),
    (string) getenv('ASSINAFY_OAUTH_CLIENT_SECRET') ?: null,
);

// 1. Send the browser to Assinafy, keeping the transaction in the user's session.
$start = $oauth->startAuthorization('https://app.example.com/callback', [
    OAuthResource::SCOPE_DOCUMENTS_READ,
    OAuthResource::SCOPE_DOCUMENTS_WRITE,
    OAuthResource::SCOPE_OFFLINE_ACCESS,
]);
$_SESSION['assinafy_oauth'] = $start;
header('Location: ' . $start['authorization_url'], true, 302);

// 2. On the redirect URI, validate the callback and exchange the code.
$transaction = $_SESSION['assinafy_oauth'];
unset($_SESSION['assinafy_oauth']);
$tokens = $oauth->exchangeCode($oauth->handleCallback($_GET, $transaction), $transaction);

// 3. The token belongs to the one workspace the user chose.
$accounts = AssinafyClient::forAuth()->accounts()->list($tokens['access_token']);
$connected = AssinafyClient::forBearer($tokens['access_token'], $accounts['data'][0]['id']);
```

`startAuthorization()` mints a fresh `code_verifier` and `state` on every attempt.
`handleCallback()` compares `state` with `hash_equals` and requires `iss` to be the expected
authorization server before the code is used anywhere. Pass `null` as the second argument to
`oauth()` for a public application, which authenticates with PKCE alone and is never issued a
secret.

Token responses are flat JSON with no `data` envelope. Read the returned `scope` rather than
assuming every requested permission was granted; `refresh_token` needs approved `offline_access`
and `id_token` needs `openid`.

```php
$renewed = $oauth->refresh($connection->refreshToken);   // returns a NEW refresh token
$store->saveRefreshToken($renewed['refresh_token']);     // persist it before anything else
$claims  = $oauth->userinfo($renewed['access_token']);   // {sub, name?, email?, email_verified?}
// On disconnect, revoke the token you stored most recently — never a retired copy.
$oauth->revoke($store->currentRefreshToken(), OAuthResource::TOKEN_TYPE_HINT_REFRESH);
```

Every refresh retires the token it used. A replayed refresh token is indistinguishable from a
stolen one, so the server ends the whole connection: persist the replacement before anything else,
refresh one at a time per connection, and never resend a refresh token after a timeout: re-read
what you stored, and if it is still the token you sent, ask the user to reconnect. Only a failure
that provably happened before sending (DNS, refused connection, TLS handshake) is safe to retry. Access tokens last
one hour. A refresh token lasts 30 days and every refresh returns a new one with a fresh 30 days,
so a connection only expires after 30 days without a refresh.

The SDK stores no tokens, holds no locks and renews nothing automatically. Create one client per
connection and never share a mutable credential between users. OAuth is deployed to production;
sandbox does not serve these routes. The legacy `socialLoginUrl()` and `socialLoginCallbackUrl()`
helpers are separate from this flow.

API keys, Bearer tokens, and signer access codes are separate credentials. A public client sends
neither `X-Api-Key` nor `Authorization`; calling an account-scoped resource on it fails locally.

### 2. Upload and prepare the PDF

Uploads accept a readable PDF up to 25 MB. The SDK checks the extension, PDF header, end marker,
readability, and size before opening a network connection.

```php
$document = $client->documents()->upload('/absolute/path/to/agreement.pdf');
$documentId = $document['id'];

// Processing is asynchronous. Continue only after the document is usable.
$document = $client->documents()->waitUntilReady(
    documentId: $documentId,
    maxWaitSeconds: 60,
    pollIntervalSeconds: 2,
);
```

The upload returns the document object. Common fields include:

```php
[
    'id' => 'document-id',
    'account_id' => 'account-id',
    'name' => 'agreement.pdf',
    'status' => 'metadata_ready',
    'artifacts' => [],
    'tags' => [],
    'created_at' => '2026-01-01T12:00:00Z',
]
```

Rename before starting the signature process:

```php
$document = $client->documents()->rename($documentId, 'Service agreement.pdf');
```

Once an assignment exists, treat the document name and signer plan as part of the immutable
signature record.

### 3. Create or reuse signers

Signers belong to the configured workspace. Search before creating one when your application
uses email as its identity key:

```php
$email = 'signer@example.test';

$signer = $client->signers()->findByEmail($email)
    ?? $client->signers()->create(
        fullName: 'Example Signer',
        email: $email,
    );

$signerId = $signer['id'];
```

`findByEmail()` returns the stored signer without changing its name or phone. Apply intentional
changes explicitly:

```php
$signer = $client->signers()->update($signerId, [
    'full_name' => 'Updated Signer Name',
]);
```

WhatsApp numbers require `+`, a country code, and 8–15 digits. Visual separators are normalized:

```php
$signer = $client->signers()->create(
    fullName: 'Mobile Signer',
    whatsappPhoneNumber: '+55 (48) 99999-0000',
);
```

Digital-certificate assignments require an account with that feature enabled and an existing
signer whose `government_id` has been set with `signers()->update()`. A certificate signer must
be alone in its signing step. The v1 signer-session methods do not expose a certificate
start/complete protocol; use the completion flow provided by Assinafy for the account.

### 4. Estimate the assignment

Estimate before creating an assignment so the application can verify balances and display cost.
Signer IDs are optional for an estimate because the cost is based on verification and notification
methods.

```php
use Assinafy\SDK\Resources\AssignmentResource;

$signerPlan = [[
    'id' => $signerId,
    'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
    'notification_methods' => [AssignmentResource::NOTIFICATION_EMAIL],
    'step' => 1,
]];

$estimate = $client->assignments()->estimateCost(
    documentId: $documentId,
    signers: $signerPlan,
    method: AssignmentResource::METHOD_VIRTUAL,
);

if (!($estimate['has_sufficient_resources'] ?? false)) {
    throw new RuntimeException($estimate['blocking_reason'] ?? 'Insufficient account resources');
}
```

The estimate data contains `documents`, `credits`, `needs_extra_document`,
`extra_document_cost`, `total_credits`, `breakdown`, `document_balance`, `credit_balance`,
`has_sufficient_resources`, `blocking_reason`, and `message`.

Each signer carries a verification method (how they prove their identity) coupled to one
notification method (how they receive the invitation). Supply one side, both or neither: the
missing side is inferred, and omitting both defaults to Email. The SDK enforces the matrix locally
before sending the request.

| Verification | Allowed notification | Requirements | Credits per signer |
| --- | --- | --- | --- |
| `VERIFICATION_EMAIL` | `Email` | An email address on the signer | 0 |
| `VERIFICATION_WHATSAPP` | `Whatsapp` | `whatsapp_phone_number` and a paid subscription | 0.45 |
| `VERIFICATION_DIGITAL_CERTIFICATE` | `Email` or `Whatsapp` | The account's Digital Certificate feature (Standard and Pro plans), a CPF in `government_id`, and the signer alone in its step | 2 for the signature, on top of its notification |

`DigitalCertificate` covers the ICP-Brasil **A1** (a software file on the device) and **A3** (a
smart card or token) certificates. Both use this one value and the same payload — they differ only
in where the private key lives. The signature is completed in the browser through the Web PKI
extension, so `signerSession()->sign()` cannot finish it and the API publishes no certificate
start/complete operation. The result is a qualified PAdES signature, downloadable as the `pades`
artifact and billed in the breakdown under `SignatureDigitalCertificate`.

Exactly one notification method is allowed per signer; an explicit empty list is preserved rather
than replaced.

### 5. Assign and notify

Creating an assignment starts the signature request and sends the configured notifications:

```php
$assignment = $client->assignments()->create(
    documentId: $documentId,
    signers: $signerPlan,
    method: AssignmentResource::METHOD_VIRTUAL,
    options: [
        'message' => 'Please sign this agreement.',
        'expires_at' => '2027-12-31T23:59:00Z',
    ],
);

$assignmentId = $assignment['id'];
```

The assignment includes its ID, method, expiration, message, signers, items, summary, copy
receivers, and signing URLs. Treat signing URLs as credentials even though the separate signer
access code is delivered through the selected channel.

Before resending, estimate the additional cost:

```php
$resendEstimate = $client->assignments()->estimateResendCost(
    $documentId,
    $assignmentId,
    $signerId,
);

if ($resendEstimate['has_sufficient_credits'] ?? false) {
    $client->assignments()->resend($documentId, $assignmentId, $signerId);
}
```

Use `resetExpiration()` to change the assignment deadline and
`whatsappNotifications()` to inspect rendered WhatsApp delivery history.

For the standard virtual flow, the high-level helper performs upload, readiness polling, signer
lookup/creation, and assignment creation:

```php
$result = $client->uploadAndRequestSignatures(
    filePath: '/absolute/path/to/agreement.pdf',
    signers: [[
        'full_name' => 'Example Signer',
        'email' => 'signer@example.test',
        'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
        'notification_methods' => [AssignmentResource::NOTIFICATION_EMAIL],
        'step' => 1,
    ]],
    message: 'Please sign this agreement.',
    expiresAt: '2027-12-31T23:59:00Z',
);

$document = $result['document'];
$assignment = $result['assignment'];
$signerIds = $result['signer_ids'];
```

The helper validates all signer descriptions before upload. Remote objects created before a later
API failure are not automatically rolled back. Use the separate calls when you need to persist
each ID and control recovery; SDK logs do not contain response IDs.

### 6. Complete the signer flow

Signer operations use `signer-access-code`, not the workspace API key. Send a fresh token only to
an address already assigned to the document:

```php
$public = AssinafyClient::forAuth(Configuration::DEFAULT_BASE_URL);

$public->documents()->sendToken(
    documentId: $documentId,
    recipient: 'signer@example.test',
);
```

Obtain the signer access code through the controlled delivery channel. Do not derive it from a
signing URL, log it, or persist it in plain text.

```php
$accessCode = (string) getenv('ASSINAFY_SIGNER_ACCESS_CODE');

$profile = $public->signerSession()->self($accessCode);
$public->signerSession()->acceptTerms($accessCode);
$public->signerSession()->verifyCode(
    $accessCode,
    (string) getenv('ASSINAFY_VERIFICATION_CODE'),
);

```

For a virtual assignment, confirm the signer data and finalize with an empty field list:

```php
$public->signerSession()->confirmData($documentId, $accessCode, [
    'full_name' => 'Example Signer',
    'email' => 'signer@example.test',
    'has_accepted_terms' => true,
]);

$current = $public->signerSession()->currentDocument($accessCode);

$public->signerSession()->sign(
    documentId: $documentId,
    assignmentId: $assignmentId,
    accessCode: $accessCode,
    fields: [],
);
```

For a collect assignment, submit the requested field values returned by the current document:

```php
$public->signerSession()->sign(
    documentId: $documentId,
    assignmentId: $assignmentId,
    accessCode: $accessCode,
    fields: [[
        'itemId' => 'assignment-item-id',
        'fieldId' => 'field-id',
        'pageId' => 'page-id',
        'value' => 'Approved',
    ]],
);
```

Signers may upload a PNG or JPEG signature/initial, decline with a reason, list their documents,
download permitted artifacts, or sign/decline multiple virtual documents:

```php
use Assinafy\SDK\Resources\SignerSessionResource;

$signatureBytes = file_get_contents('/absolute/path/to/signature.png');
if ($signatureBytes === false) {
    throw new RuntimeException('Unable to read the signature image');
}

$public->signerSession()->uploadSignature(
    accessCode: $accessCode,
    type: SignerSessionResource::TYPE_SIGNATURE,
    imageBytes: $signatureBytes,
    mimeType: 'image/png',
);

$signerDocuments = $public->signerDocuments()->list($signerId, $accessCode);
```

### 7. Monitor progress

Use the document as the source of truth and webhooks for prompt updates:

```php
$document = $client->documents()->get($documentId);
$progress = $client->documents()->getSigningProgress($documentId);

printf(
    "%d of %d signed (%.2f%%)\n",
    $progress['signed'],
    $progress['total'],
    $progress['percentage'],
);

if ($client->documents()->isFullySigned($documentId)) {
    // Certification may still be finishing; poll get() for the desired artifact state.
}

$activity = $client->documents()->activities($documentId);
```

Document status constants live on `DocumentResource`. `ready`, `certificating`, and
`certificated` indicate that every signer has completed; download availability still depends on
the requested artifact. Paginated workspace views are available through `documents()->list()`,
`documents()->search()`, and `assignments()->list()`.

### 8. Download and verify

Download methods return raw bytes. Persist them using the access controls and storage rules of
your application:

```php
use Assinafy\SDK\Resources\DocumentResource;

$pdf = $client->documents()->download(
    $documentId,
    DocumentResource::ARTIFACT_CERTIFICATED,
);

if (file_put_contents('/secure/output/agreement-signed.pdf', $pdf, LOCK_EX) === false) {
    throw new RuntimeException('Unable to store the signed document');
}
```

Available artifact names are `original`, `certificated`, `certificate-page`, `pades`, and
`bundle`. `pades` applies to digital-certificate documents; `bundle` is a ZIP. Thumbnails and
rendered pages also return binary image bytes.

Document verification is public and uses the signature hash printed in the certificate data:

```php
$signatureHash = (string) getenv('ASSINAFY_DOCUMENT_SIGNATURE_HASH');
if ($signatureHash === '') {
    throw new RuntimeException('ASSINAFY_DOCUMENT_SIGNATURE_HASH is required');
}

$public = AssinafyClient::forAuth(Configuration::DEFAULT_BASE_URL);
$verification = $public->documents()->verify($signatureHash);
$publicDocument = $public->documents()->publicInfo($documentId);
```

## Organize documents with tags

Tag names are attached to documents. Unknown names are created when they are appended or replace
the current set:

```php
$client->documents()->appendTags($documentId, ['contracts', '2026']);
$tags = $client->documents()->listTags($documentId);

$client->documents()->detachTag($documentId, $tags[0]['id']);
$client->documents()->replaceTags($documentId, ['completed']);
```

Use `tags()` to list, create, update, or delete workspace tags. Deleting a tag is distinct from
detaching it from one document.

## Reuse a template

Template uploads use the same PDF validation and asynchronous processing as documents.

> **Signing roles come from the web app.** A template uploaded through the API is given exactly
> one role, and its `assignment_type` is `Editor` — an editing role, not a signing one. Binding a
> signer to it makes `createFromTemplate()` fail with
> `400 "Pelo menos um signatário deve ter uma função de assinatura."` Configure the signing roles
> and field placements for a template in Assinafy before creating documents from it.
> `create`, `get`, `update`, `delete`, `waitUntilReady`, `downloadPage`, and
> `estimateCostFromTemplate` all work on an API-created template.

```php
$templateId = (string) getenv('ASSINAFY_TEMPLATE_ID');
$template = $client->templates()->get($templateId);
$signingRoles = array_values(array_filter(
    $template['roles'],
    static fn (array $role): bool => $role['assignment_type'] !== 'Editor',
));
if ($signingRoles === []) {
    throw new RuntimeException('Configure a signing role in the Assinafy template editor');
}
$roleId = $signingRoles[0]['id'];

$estimate = $client->documents()->estimateCostFromTemplate($template['id'], [[
    'role_id' => $roleId,
    'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
    'notification_methods' => [AssignmentResource::NOTIFICATION_EMAIL],
]]);

$document = $client->documents()->createFromTemplate(
    templateId: $template['id'],
    signers: [[
        'role_id' => $roleId,
        'id' => $signerId,
        'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
        'notification_methods' => [AssignmentResource::NOTIFICATION_EMAIL],
    ]],
    options: [
        'name' => 'Agreement from template.pdf',
        'message' => 'Please sign this agreement.',
        'tags' => ['contracts'],
    ],
);
```

Template management also provides list, get, update, delete, and rendered-page download methods.

This example assumes one signing role and no required editor fields. For other templates, provide
one signer binding per required role and include every required editor-field value.

## Receive webhooks

Each workspace has one webhook subscription. Registering it creates or replaces that
configuration:

```php
use Assinafy\SDK\Resources\WebhookResource;

$subscription = $client->webhooks()->register(
    url: 'https://hooks.example.test/assinafy/a-long-random-path',
    email: 'ops@example.test',
    events: WebhookResource::DEFAULT_EVENTS,
);
```

Parse incoming JSON defensively and then re-fetch the referenced object with authenticated
credentials:

```php
$payload = file_get_contents('php://input');
if ($payload === false) {
    http_response_code(400);
    exit;
}

$event = $client->webhookEvents()->extractEvent($payload);

if ($event === null) {
    http_response_code(400);
    exit;
}

// Deduplicate by event ID, then re-fetch the referenced Assinafy entity before acting.
```

The v1 webhook contract has no signing secret or signature header. Use HTTPS, an unguessable
endpoint path, strict method/body limits, event-ID idempotency, and an authenticated re-fetch
before consequential work. `deactivate()` pauses delivery without deleting the stored
configuration; `activate()` re-enables it. Use `dispatches()` and `retryDispatch()` to inspect and
retry deliveries.

## Responses and pagination

Single-object operations normally return the API envelope's `data` value. Paginated operations
return the envelope and add pagination normalized from `X-Pagination-*` response headers:

```php
$result = $client->documents()->list(page: 1, perPage: 100);

foreach ($result['data'] as $document) {
    echo $document['id'];
}

$pagination = $result['pagination'];
// current_page, page_count, per_page, total_count
```

Pages start at 1 and `perPage` accepts 1–100. Binary methods return raw bytes. JSON operations
with an empty or 204 response return an empty array.

## Errors, logging, and secrets

```php
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\NetworkException;
use Assinafy\SDK\Exceptions\ValidationException;

try {
    $document = $client->documents()->get($documentId);
} catch (ValidationException $exception) {
    // The SDK rejected local input before sending a request.
} catch (ApiException $exception) {
    $status = $exception->getStatusCode();
    $details = $exception->getResponseData();
} catch (NetworkException $exception) {
    // Connection, timeout, malformed response, or transport failure.
}
```

The default transport rejects unsuccessful HTTP responses and unsuccessful API envelopes.
Redirect following is disabled so custom credentials are not forwarded to another origin.

Inject any PSR-3 logger through the constructor or `setLogger()`. Logger changes propagate to
resources that have already been created. The default transport redacts API keys, Bearer tokens,
signer access codes, passwords, OTP values, and credentials embedded in response URLs. Avoid
logging complete request/response payloads in application code because they may contain personal
or signature data.

## Resource map

| Accessor | Purpose |
| --- | --- |
| `accounts()` | Workspace profile, theme, logo, and document statistics |
| `users()` | Authenticated user, notification preferences, and user statistics |
| `documents()` | Upload, metadata, status, artifacts, public access, tags, and template documents |
| `signers()` | Workspace signer CRUD and search |
| `assignments()` | Cost estimates, signature requests, resend, expiration, and delivery history |
| `templates()` | Template upload, metadata, lifecycle, and page rendering |
| `tags()` | Workspace tag CRUD |
| `fields()` | Field definitions, types, and value validation |
| `webhooks()` | Subscription, event types, delivery history, and retries |
| `auth()` | Login, social authentication, API-key lifecycle, password reset, and password change |
| `oauth()` | Marketplace authorization: PKCE, callback validation, token exchange, refresh, revocation, userinfo, and discovery |
| `signerSession()` | Signer identity, terms, verification, signature image, sign, and decline actions |
| `signerDocuments()` | Signer document list, search, bulk actions, and downloads |
| `webhookEvents()` | Incoming webhook payload parsing |

## Sandbox and production differences

Account/user statistics and notification preferences are available in the sandbox. Features such
as WhatsApp notification and digital-certificate signing still depend on the account plan and
server deployment. A 403 can indicate a plan restriction; inspect the response message before
changing the request.

Marketplace OAuth is deployed to production. Sandbox answers `/oauth/token`, `/oauth/revoke`
and `/oauth/userinfo` with a framework 404, and its origin does not serve the protected-resource
document, so `oauth()` calls skip rather than fail there. Read endpoint URLs from the discovery
metadata of the intended environment, and never mix a sandbox resource URL with production
authorization. A workspace API key cannot replace OAuth application credentials or a signer's
access code.

A framework routing 404 (`name: Not Found`) is different from a resource-not-found API envelope.
Keep supported resource methods when an environment has not deployed a route yet.

## Testing

Run all local quality checks:

```bash
composer check
```

The individual commands are:

```bash
composer test
composer phpstan
composer phpcs
composer audit:dependencies
composer validate --strict --no-check-lock
```

Live tests are opt-in and reject the production API URL unless explicitly overridden. Enter secrets without placing them in
shell history:

```bash
read -rs ASSINAFY_API_KEY
export ASSINAFY_API_KEY
export ASSINAFY_ACCOUNT_ID='sandbox-account-id'
export ASSINAFY_BASE_URL='https://sandbox.assinafy.com.br/v1'
export ASSINAFY_INTEGRATION=1
vendor/bin/phpunit --testsuite=integration
```

That run covers document preparation and assignment management — upload, estimate, assign, resend,
reset expiration, progress, download, templates, tags, fields, webhooks, and accounts — with no
further switches. Recipients are unique addresses in the reserved `example.com` domain, so the
API accepts them without delivering to a real inbox. It does not prove email delivery or signature completion.

To send notifications to operator-controlled inboxes instead of reserved recipients:

```bash
export ASSINAFY_NOTIFICATION_TESTS=1
export ASSINAFY_TEST_EMAIL='first-controlled-address@example.test'
export ASSINAFY_TEST_EMAIL_ALT='second-controlled-address@example.test'
```

Signer-session checks additionally require a signer ID and the access code received through the
controlled channel:

```bash
export ASSINAFY_SIGNER_ID='sandbox-signer-id'
read -rs ASSINAFY_SIGNER_ACCESS_CODE
export ASSINAFY_SIGNER_ACCESS_CODE
```

State-changing shared-account checks and permanent disposable-account deletion have separate
switches:

```bash
export ASSINAFY_STATEFUL_TESTS=1
export ASSINAFY_DESTRUCTIVE_TESTS=1
```

Enable only the category whose side effects are acceptable. The live suite never deletes the
configured account or supplied API key. Login/reset completion, OTP completion, social-provider
flows, password changes, and API-key deletion need the corresponding disposable credentials,
inbox access, or provider token.

GitLab CI is the canonical pipeline. The mirrored GitHub Actions pipeline runs the supported PHP
matrix, dependency ranges, unit tests, static analysis, formatting checks, coverage, dependency
security checks, and production-dependency smoke tests. GitHub does not run sandbox tests. Live integration is an explicit local command or an optional
protected GitLab job.

## Upgrading and license

See [UPGRADING.md](UPGRADING.md) for release migration guidance. Licensed under the
[MIT License](LICENSE).
