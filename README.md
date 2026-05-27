# Assinafy PHP SDK

Modern, framework-agnostic PHP SDK for the [Assinafy](https://assinafy.com.br) digital signature API (`https://api.assinafy.com.br/v1`). Built with PSR standards and SOLID principles.

The SDK covers **100% of the documented endpoints** in `https://api.assinafy.com.br/v1/docs` — documents and document tags, signers, signer sessions and signer documents, assignments, templates, workspace tags, field definitions, authentication, and the full webhooks surface (subscription, dispatch history, retry, event types). Every endpoint is verified against the live API by an integration test suite.

## Features

- PSR-4 autoloading, PSR-3 logging, PSR-18 HTTP client interface
- Framework agnostic — works with any PHP project
- Zero hidden state: every method maps to one API endpoint, with the path documented in the docblock
- 100 % unit test coverage of the resource layer + opt-in live integration suite
- PHP 7.4 – 8.4 compatible, PHPStan level 8 clean, PSR-12 compliant

## Requirements

- PHP 7.4 or higher (PHP 8.0+ recommended for named arguments)
- `ext-json`

## Installation

```bash
composer require assinafy/php-sdk
```

If you don't already have a PSR-18 client, install Guzzle:

```bash
composer require guzzlehttp/guzzle
```

## Quick start

```php
<?php

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;

$client = AssinafyClient::create(
    apiKey: 'your-api-key',
    accountId: 'your-account-id',
);

// 1. upload a PDF + 2. wait until it's metadata-ready + 3. dispatch a signature request
$result = $client->uploadAndRequestSignatures(
    filePath: '/path/to/contract.pdf',
    signers: [
        ['full_name' => 'John Doe',  'email' => 'john@example.com'],
        ['full_name' => 'Jane Smith','email' => 'jane@example.com'],
    ],
    message:   'Please sign this contract',
    expiresAt: '2026-12-31T23:59:00Z',
);

echo "Document ID: {$result['document']['id']}\n";
echo "Assignment ID: {$result['assignment']['id']}\n";
foreach ($result['assignment']['signing_urls'] ?? [] as $u) {
    echo "  • {$u['signer_id']} → {$u['url']}\n";
}
```

## Configuration

```php
use Assinafy\SDK\Configuration;
use Assinafy\SDK\AssinafyClient;

$config = new Configuration(
    apiKey: 'your-api-key',
    accountId: 'your-account-id',
    baseUrl: Configuration::DEFAULT_BASE_URL,    // or SANDBOX_BASE_URL
    webhookSecret: 'your-webhook-secret',        // optional
    timeout: 30,
    connectTimeout: 10,
);

$client = new AssinafyClient($config);

// or from an array
$client = AssinafyClient::fromArray([
    'api_key'        => 'your-api-key',
    'account_id'     => 'your-account-id',
    'webhook_secret' => 'your-webhook-secret',
    'base_url'       => Configuration::DEFAULT_BASE_URL,
]);
```

### Bootstrapping without credentials

When you don't yet have an API key (e.g. you're calling `auth()->login(...)` to obtain one
or hitting a public document endpoint), use `AssinafyClient::forAuth()`:

```php
$bootstrap = AssinafyClient::forAuth();              // or ::forAuth(Configuration::SANDBOX_BASE_URL)
$session   = $bootstrap->auth()->login('user@example.com', 'secret');
$apiKey    = $bootstrap->auth()->generateApiKey($session['access_token'], 'secret');

// Then build the real client with the credentials you just retrieved:
$client = AssinafyClient::create($apiKey['key'], $session['accounts'][0]['id']);
```

Calling an account-scoped resource (`signers()`, `documents()`, `assignments()`, …) on a
`forAuth()` client raises `RuntimeException` so misuse is caught at the call site rather
than as an obscure 401 from the API.

### Response shape: single-item vs list

Single-item methods (`get()`, `create()`, `update()`, `verify()`, …) return the inner
`data` object directly. List methods return the full envelope so you keep access to
pagination metadata:

```php
$page = $client->documents()->list(1, 20);
// $page['data'] => array of document objects
// $page['meta'] => pagination cursor / totals
```

## Endpoint coverage

Every endpoint exposed by the documented API is reachable through the SDK. Resource accessors on `$client` are singletons (lazy-instantiated).

### Documents — `$client->documents()`

| Method | Endpoint |
| --- | --- |
| `upload($filePath)` | `POST /accounts/{id}/documents` |
| `get($documentId)` | `GET /documents/{id}` |
| `list($page, $perPage, $filters)` | `GET /accounts/{id}/documents` |
| `delete($documentId)` | `DELETE /documents/{id}` |
| `download($documentId, $artifact)` | `GET /documents/{id}/download/{artifact_name}` |
| `downloadThumbnail($documentId)` | `GET /documents/{id}/thumbnail` |
| `downloadPage($documentId, $pageId)` | `GET /documents/{id}/pages/{page_id}/download` |
| `activities($documentId)` | `GET /documents/{id}/activities` |
| `statuses()` | `GET /documents/statuses` |
| `verify($hash)` | `GET /documents/{hash}/verify` |
| `publicInfo($documentId)` | `GET /public/documents/{id}` |
| `sendToken($documentId, $recipient, $channel)` | `PUT /public/documents/{id}/send-token` |
| `createFromTemplate($templateId, $signers, $options)` | `POST /accounts/{id}/templates/{id}/documents` |
| `estimateCostFromTemplate($templateId, $signers)` | `POST /accounts/{id}/templates/{id}/documents/estimate-cost` |
| `listTags($documentId)` | `GET /accounts/{id}/documents/{id}/tags` |
| `appendTags($documentId, $tagNames)` | `POST /accounts/{id}/documents/{id}/tags` |
| `replaceTags($documentId, $tagNames)` | `PUT /accounts/{id}/documents/{id}/tags` |
| `detachTag($documentId, $tagId)` | `DELETE /accounts/{id}/documents/{id}/tags/{tag_id}` |
| `waitUntilReady($documentId, $maxWait, $pollInterval)` | polls `GET /documents/{id}` |
| `isFullySigned($documentId)` | derived from `GET /documents/{id}` |
| `getSigningProgress($documentId)` | derived from `GET /documents/{id}` |

```php
// Upload (PDF only, ≤25 MB)
$doc = $client->documents()->upload('/path/to/contract.pdf');
$documentId = $doc['id'];

// Wait for the upload pipeline to finish
$client->documents()->waitUntilReady($documentId);

// Download artifacts
$pdf = $client->documents()->download($documentId, DocumentResource::ARTIFACT_CERTIFICATED);
$jpg = $client->documents()->downloadThumbnail($documentId);

// Verify a certificated document by signature hash (public)
$result = $client->documents()->verify('FE32EDDADE7CBDDCBB934E7402047450B0E59C02');

// Create from template
$doc = $client->documents()->createFromTemplate(
    templateId: 'fa7f3e524f3a2cc00a5ea4325e2',
    signers: [
        ['role_id' => 'fa8c14f32d732271e071998246e', 'id' => 'fa8c140cb49b79f940aab95fddd'],
    ],
    options: [
        'name'       => 'Service Contract 2026',
        'expires_at' => '2026-12-31T23:59:00Z',
    ],
);
```

### Signers — `$client->signers()`

| Method | Endpoint |
| --- | --- |
| `create($fullName, $email, $whatsappPhoneNumber)` | `POST /accounts/{id}/signers` |
| `get($signerId)` | `GET /accounts/{id}/signers/{id}` |
| `list($page, $perPage, $search)` | `GET /accounts/{id}/signers` |
| `update($signerId, $data)` | `PUT /accounts/{id}/signers/{id}` |
| `delete($signerId)` | `DELETE /accounts/{id}/signers/{id}` |
| `findByEmail($email)` | `GET /accounts/{id}/signers?search={email}` |

```php
$signer = $client->signers()->create(
    fullName: 'John Doe',
    email: 'john@example.com',
    whatsappPhoneNumber: '+5548999990000',
);

$client->signers()->update($signer['id'], ['full_name' => 'John R. Doe']);
$client->signers()->delete($signer['id']);
```

> The Assinafy signer model only has `full_name`, `email` and `whatsapp_phone_number`. The phone number is normalised to E.164 (e.g. `+5548999990000`) before being sent.

### Assignments — `$client->assignments()`

| Method | Endpoint |
| --- | --- |
| `create($documentId, $signers, $method, $options)` | `POST /documents/{id}/assignments` |
| `estimateCost($documentId, $signers, $method, $options)` | `POST /documents/{id}/assignments/estimate-cost` |
| `resend($documentId, $assignmentId, $signerId)` | `PUT /documents/{id}/assignments/{id}/signers/{id}/resend` |
| `estimateResendCost($documentId, $assignmentId, $signerId)` | `POST /documents/{id}/assignments/{id}/signers/{id}/estimate-resend-cost` |
| `resetExpiration($documentId, $assignmentId, $expiresAt)` | `PUT /documents/{id}/assignments/{id}/reset-expiration` |
| `whatsappNotifications($documentId, $assignmentId)` | `GET /documents/{id}/assignments/{id}/whatsapp-notifications` |

```php
use Assinafy\SDK\Resources\AssignmentResource;

// Virtual assignment (no input fields). Signers may be ID strings or full objects.
// Add `step` to a signer for sequential signing (signers in the same step sign in
// parallel; the next step is notified only once the previous step has all signed).
$assignment = $client->assignments()->create(
    documentId: $documentId,
    signers: [
        ['id' => $signerId1, 'step' => 1],
        ['id' => $signerId2, 'verification_method' => AssignmentResource::VERIFICATION_WHATSAPP, 'step' => 2],
    ],
    method: AssignmentResource::METHOD_VIRTUAL,
    options: [
        'message'    => 'Please sign this document',
        'expires_at' => '2026-12-31T23:59:00Z',
    ],
);

// Estimate cost before creating
$estimate = $client->assignments()->estimateCost(
    documentId: $documentId,
    signers: [['id' => $signerId1, 'verification_method' => 'Whatsapp']],
);

$client->assignments()->resend($documentId, $assignment['id'], $signerId1);
$client->assignments()->resetExpiration($documentId, $assignment['id'], '2027-01-31T23:59:00Z');
```

### Templates — `$client->templates()`

| Method | Endpoint |
| --- | --- |
| `list($page, $perPage, $filters)` | `GET /accounts/{id}/templates` |
| `get($templateId)` | `GET /accounts/{id}/templates/{id}` |

```php
$templates = $client->templates()->list(1, 20, ['status' => 'ready']);
$template  = $client->templates()->get('fa7f3e524f3a2cc00a5ea4325e2');
foreach ($template['roles'] as $role) {
    echo "{$role['id']}: {$role['name']}\n";
}
```

### Tags — `$client->tags()`

Workspace-scoped labels that can be attached to documents and templates. Tag names are unique per workspace (case-insensitive).

| Method | Endpoint |
| --- | --- |
| `list($search)` | `GET /accounts/{id}/tags` |
| `create($name, $color)` | `POST /accounts/{id}/tags` |
| `update($tagId, $data)` | `PUT /accounts/{id}/tags/{id}` |
| `delete($tagId, $force)` | `DELETE /accounts/{id}/tags/{id}` |

```php
$tag = $client->tags()->create('Contracts', 'ff8800');
$client->tags()->update($tag['id'], ['name' => 'Sales Contracts']);
$client->tags()->delete($tag['id'], force: true); // detach from everything, then delete
```

### Field definitions — `$client->fields()`

Reusable inputs (text, CPF, e-mail, date, …) that can be placed on a document for `collect` assignments.

| Method | Endpoint |
| --- | --- |
| `create($type, $name, $options)` | `POST /accounts/{id}/fields` |
| `list($includeInactive, $includeStandard)` | `GET /accounts/{id}/fields` |
| `get($fieldId)` | `GET /accounts/{id}/fields/{id}` |
| `update($fieldId, $data)` | `PUT /accounts/{id}/fields/{id}` |
| `delete($fieldId)` | `DELETE /accounts/{id}/fields/{id}` |
| `validate($fieldId, $value, $signerAccessCode)` | `POST /accounts/{id}/fields/{id}/validate` |
| `validateMultiple($values, $signerAccessCode)` | `POST /accounts/{id}/fields/validate-multiple` |
| `types()` | `GET /field-types` |

```php
$field = $client->fields()->create('cpf', 'Taxpayer ID');
$check = $client->fields()->validate($field['id'], '400.676.228-36');   // ['success' => true, …]
$types = $client->fields()->types();                                    // [['type' => 'text', 'name' => 'Text'], …]
```

### Webhooks — `$client->webhooks()`

| Method | Endpoint |
| --- | --- |
| `register($url, $email, $events, $isActive)` | `PUT /accounts/{id}/webhooks/subscriptions` |
| `get()` | `GET /accounts/{id}/webhooks/subscriptions` |
| `deactivate()` | `PUT /accounts/{id}/webhooks/inactivate` |
| `activate()` | re-sends the stored config via `PUT …/subscriptions` with `is_active: true` |
| `eventTypes()` | `GET /webhooks/event-types` |
| `dispatches($filters)` | `GET /accounts/{id}/webhooks` |
| `retryDispatch($dispatchId)` | `POST /accounts/{id}/webhooks/{dispatch_id}/retry` |

> The v1 API has no `DELETE` route for webhook subscriptions. The way to stop
> receiving events is `deactivate()` (the dedicated `inactivate` endpoint) — the
> configuration is preserved so you can `activate()` again later. `register()`
> requires `url`, `email`, `events`, and `is_active`.

```php
use Assinafy\SDK\Resources\WebhookResource;

$client->webhooks()->register(
    url: 'https://your-domain.com/webhooks/assinafy',
    email: 'admin@your-domain.com',
    events: WebhookResource::DEFAULT_EVENTS,
);

// Inspect and replay delivery history
$history = $client->webhooks()->dispatches(['delivered' => 'false']);
foreach ($history['data'] as $dispatch) {
    $client->webhooks()->retryDispatch($dispatch['id']);
}
```

### Authentication — `$client->auth()`

| Method | Endpoint |
| --- | --- |
| `login($email, $password)` | `POST /login` |
| `socialLogin($provider, $token, $hasAcceptedTerms)` | `POST /authentication/social-login` |
| `generateApiKey($accessToken, $password)` | `POST /users/api-keys` |
| `getApiKey($accessToken)` | `GET /users/api-keys` |
| `deleteApiKey($accessToken)` | `DELETE /users/api-keys` |
| `changePassword($accessToken, $email, $password, $newPassword)` | `PUT /authentication/change-password` |
| `requestPasswordReset($email)` | `PUT /authentication/request-password-reset` |
| `resetPassword($email, $token, $newPassword)` | `PUT /authentication/reset-password` |

```php
$session = $client->auth()->login('user@example.com', 'secret');
$accessToken = $session['access_token'];

$apiKey = $client->auth()->generateApiKey($accessToken, 'secret');
```

### Signer session (signer-facing) — `$client->signerSession()`

Endpoints authenticated with a signer's `signer-access-code` (not the workspace API key).

| Method | Endpoint |
| --- | --- |
| `self($accessCode)` | `GET /signers/self` |
| `acceptTerms($accessCode)` | `PUT /signers/accept-terms` |
| `verifyCode($accessCode, $verificationCode)` | `POST /verify` |
| `confirmData($documentId, $accessCode, $data)` | `PUT /documents/{id}/signers/confirm-data` |
| `uploadSignature($accessCode, $type, $bytes, $mime)` | `POST /signature` |
| `downloadSignature($accessCode, $type)` | `GET /signature/{type}` |
| `currentDocument($accessCode)` | `GET /sign` |
| `sign($documentId, $assignmentId, $accessCode, $fields)` | `POST /documents/{id}/assignments/{id}` |
| `decline($documentId, $assignmentId, $accessCode, $reason)` | `PUT /documents/{id}/assignments/{id}/reject` |

### Signer documents (signer-facing) — `$client->signerDocuments()`

Document list/sign/decline/download for a signer, authenticated by `signer-access-code`.

| Method | Endpoint |
| --- | --- |
| `current($signerId, $accessCode)` | `GET /signers/{id}/document` |
| `list($signerId, $accessCode, $filters)` | `GET /signers/{id}/documents` |
| `signMultiple($accessCode, $documentIds)` | `PUT /signers/documents/sign-multiple` |
| `declineMultiple($accessCode, $documentIds, $reason)` | `PUT /signers/documents/decline-multiple` |
| `download($signerId, $documentId, $accessCode, $artifact)` | `GET /signers/{id}/documents/{id}/download/{artifact_name}` |

## Webhook signature verification

```php
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_ASSINAFY_SIGNATURE'] ?? '';

$verifier = $client->webhookVerifier();

if (!$verifier->verify($payload, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event     = $verifier->extractEvent($payload);
$eventType = $verifier->getEventType($event);
```

## Logging

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('assinafy');
$logger->pushHandler(new StreamHandler('/path/to/assinafy.log', Logger::DEBUG));

$client = new AssinafyClient($config, null, $logger);
```

## Exception handling

```php
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Exceptions\NetworkException;

try {
    $client->documents()->upload('/path/to/file.pdf');
} catch (ValidationException $e) {
    echo "Validation: {$e->getMessage()}\n";
    print_r($e->getErrors());
} catch (ApiException $e) {
    echo "API {$e->getStatusCode()}: {$e->getMessage()}\n";
    print_r($e->getResponseData());
} catch (NetworkException $e) {
    echo "Network: {$e->getMessage()}\n";
}
```

## Tests & quality

```bash
# Unit tests (mocked HTTP — no network)
vendor/bin/phpunit --testsuite=unit

# Live integration tests against the real API (incurs credit cost)
ASSINAFY_INTEGRATION=1 \
ASSINAFY_API_KEY=your-key \
ASSINAFY_ACCOUNT_ID=your-account \
vendor/bin/phpunit --testsuite=integration

# Static analysis (PHPStan level 8)
vendor/bin/phpstan analyse

# Coding standard (PSR-12)
vendor/bin/phpcs
```

**Current status**: PSR-12 compliant · PHPStan level 8 (zero errors) · 116 unit tests + 17 live integration tests · PHP 7.4 – 8.4 compatible.

## License

MIT
