# Assinafy PHP SDK

Framework-independent PHP client for the [Assinafy v1 API](https://api.assinafy.com.br/v1/docs).
It covers workspace administration, document preparation, signature requests, signer sessions,
artifacts, templates, tags, fields, and webhooks.

This guide follows a document from upload through certification. Every SDK method also carries
its own request and response payloads in its docblock, so an IDE shows the exact shapes at the
call site. For the same material as a single reference, use
[docs/API_REFERENCE.md](docs/API_REFERENCE.md); additional focused examples are in
[docs/EXAMPLES.md](docs/EXAMPLES.md).

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
example, version 2.1.3 sends `Assinafy-PHP-SDK/v2.1.3`. This applies to authenticated, public,
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

An ordinary Email or WhatsApp assignment uses at most one notification method, and its
verification and delivery channels must match. `DigitalCertificate` uses its own verification
rules and may still use Email notification.

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

if ($resendEstimate['has_sufficient_resources'] ?? false) {
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
API failure are not automatically rolled back; record the returned or logged IDs and apply your
application's cleanup policy.

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

$current = $public->signerSession()->currentDocument($accessCode);
```

For a virtual assignment, confirm the signer data and finalize with an empty field list:

```php
$public->signerSession()->confirmData($documentId, $accessCode, [
    'full_name' => 'Example Signer',
    'email' => 'signer@example.test',
    'has_accepted_terms' => true,
]);

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

Template uploads use the same PDF validation and asynchronous processing as documents. Configure
roles and field placements in Assinafy before creating documents from the template.

```php
$template = $client->templates()->create('/absolute/path/to/template.pdf');
$template = $client->templates()->waitUntilReady($template['id']);
$template = $client->templates()->get($template['id']);

$roleId = $template['roles'][0]['id'];

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
| `signerSession()` | Signer identity, terms, verification, signature image, sign, and decline actions |
| `signerDocuments()` | Signer document list, search, bulk actions, and downloads |
| `webhookEvents()` | Incoming webhook payload parsing |

## Sandbox and production differences

The sandbox does not serve every production route. These three answer normally on
`api.assinafy.com.br` but return `404 {"name":"Not Found","message":"Página não encontrada."}`
on `sandbox.assinafy.com.br`:

| SDK method | Endpoint |
| --- | --- |
| `accounts()->stats()` | `GET /accounts/{account_id}/stats` |
| `users()->stats()` | `GET /users/self/stats` |
| `users()->notificationPreferences()` and `updateNotificationPreferences()` | `GET`/`PUT /users/self/notification-preferences` |

A 404 from the sandbox is therefore not evidence that a route is gone. To tell a missing route
from a missing resource, read the error body rather than the status: a framework routing miss
carries a `name` key (`{"name":"Not Found", …}`), while a real route reporting a missing resource
returns the API envelope instead (`{"status":404,"data":null,"message":"Documento não
encontrado."}`).

The same distinction works without credentials, because routing resolves before authentication:
an unauthenticated request to a route that exists answers
`401 {"status":401,"data":null,"message":"Credenciais inválidas."}`, and one to a route that does
not exist answers the framework 404 above.

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

Live tests are opt-in and reject the production API URL. Enter secrets without placing them in
shell history:

```bash
read -rs ASSINAFY_API_KEY
export ASSINAFY_API_KEY
export ASSINAFY_ACCOUNT_ID='sandbox-account-id'
export ASSINAFY_BASE_URL='https://sandbox.assinafy.com.br/v1'
export ASSINAFY_INTEGRATION=1
vendor/bin/phpunit --testsuite=integration
```

Tests that send notifications require addresses controlled by the operator:

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
security checks, and production-dependency smoke tests. Sandbox integration runs only through a
manual workflow with explicit secrets and safety switches.

## Upgrading and license

See [UPGRADING.md](UPGRADING.md) for release migration guidance. Licensed under the
[MIT License](LICENSE).
