# Assinafy PHP SDK examples

These examples target the Assinafy v1 sandbox. Keep production and sandbox credentials separate, load secrets from environment variables or a secret manager, and use disposable sandbox entities for operations that create, update, sign, or delete data.

The complete endpoint and response mapping is in [API_REFERENCE.md](API_REFERENCE.md).
These examples target repository release `v2.0.0`. Packagist does not currently expose
`assinafy/php-sdk`, so use the tagged VCS/path instructions in
[INSTALLATION.md](INSTALLATION.md) until the package is published there.

## Sandbox setup

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException("Missing required environment variable: {$name}");
    }

    return $value;
}

$client = AssinafyClient::create(
    apiKey: requiredEnv('ASSINAFY_API_KEY'),
    accountId: requiredEnv('ASSINAFY_ACCOUNT_ID'),
    baseUrl: Configuration::SANDBOX_BASE_URL,
);

$signerEmail = requiredEnv('ASSINAFY_TEST_SIGNER_EMAIL');
```

Do not print or log `ASSINAFY_API_KEY`, signer access codes, verification codes, passwords, or Bearer tokens.

WhatsApp signer numbers must include an explicit country code. The same public normalizer used by
signer create/update is available when preparing input elsewhere:

```php
use Assinafy\SDK\Resources\SignerResource;

$whatsappNumber = SignerResource::normalizePhoneNumber('+55 (48) 99999-0000');
// +5548999990000
```

## Upload and request signatures

The convenience workflow validates signer descriptions, uploads the PDF, waits for document metadata by default, reuses an exact email match when one exists, creates missing signers, and creates a virtual assignment.

```php
$result = $client->uploadAndRequestSignatures(
    filePath: requiredEnv('ASSINAFY_TEST_PDF'),
    signers: [
        [
            'full_name' => 'Sandbox Signer',
            'email' => $signerEmail,
        ],
    ],
    message: 'Sandbox signature request',
    expiresAt: (new DateTimeImmutable('+7 days'))->format(DateTimeInterface::ATOM),
);

$documentId = $result['document']['id'];
$assignmentId = $result['assignment']['id'];
$signerId = $result['signer_ids'][0];
```

The helper accepts existing signer IDs as strings and accepts `verification_method`,
`notification_methods`, and `step` in signer arrays. Notifications may independently be empty or
include Email, WhatsApp, or both; they need not match the verification method. The helper does
not accept a `fileName` argument; the uploaded file supplies its name.

## Long-form document workflow

```php
use Assinafy\SDK\Resources\AssignmentResource;

$document = $client->documents()->upload(requiredEnv('ASSINAFY_TEST_PDF'));
$document = $client->documents()->waitUntilReady(
    documentId: $document['id'],
    maxWaitSeconds: 60,
    pollIntervalSeconds: 2,
);

$signer = $client->signers()->findByEmail($signerEmail);
if ($signer === null) {
    $signer = $client->signers()->create('Sandbox Signer', $signerEmail);
}

$assignment = $client->assignments()->create(
    documentId: $document['id'],
    signers: [
        [
            'id' => $signer['id'],
            'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
            'notification_methods' => [AssignmentResource::NOTIFICATION_EMAIL],
        ],
    ],
    method: AssignmentResource::METHOD_VIRTUAL,
    options: ['message' => 'Sandbox signature request'],
);
```

`waitUntilReady()` returns when document status reaches `metadata_ready`, `pending_signature`, `ready`, `certificating`, or `certificated`. It throws for terminal failure/rejection states or when the timeout expires.

## Estimate assignment cost

Signer IDs are optional for cost estimation because cost depends on delivery and verification methods.

```php
$estimate = $client->assignments()->estimateCost(
    documentId: $document['id'],
    signers: [
        [
            'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
            'notification_methods' => [AssignmentResource::NOTIFICATION_EMAIL],
        ],
    ],
);

if (!($estimate['has_sufficient_resources'] ?? false)) {
    throw new RuntimeException('The sandbox account does not have sufficient resources.');
}
```

## List and search documents

Paginated methods retain the API envelope and add a normalized `pagination` entry from response headers.

```php
$page = $client->documents()->list(
    page: 1,
    perPage: 20,
    filters: ['status' => 'pending_signature'],
);

$matches = $client->documents()->search(
    term: 'sandbox',
    page: 1,
    perPage: 20,
    filters: ['status' => 'pending_signature'],
);

foreach ($matches['data'] ?? [] as $match) {
    echo ($match['name'] ?? 'Unnamed document') . PHP_EOL;
}
```

## Account and authenticated-user statistics

These methods implement the published contract: account statistics cover the configured
workspace, and user statistics aggregate every account available to the authenticated user.
They are **not currently runnable against the audited sandbox**: on 2026-08-05, both published
routes returned an application-level `404` route-not-deployed response. The following is the
contract usage for a deployment where Assinafy has enabled the routes:

```php
use Assinafy\SDK\Resources\AccountResource;
use Assinafy\SDK\Resources\UserResource;

$accountMonthly = $client->accounts()->stats(AccountResource::GRANULARITY_MONTHLY);
$accountDaily = $client->accounts()->stats(
    AccountResource::GRANULARITY_DAILY,
    (new DateTimeImmutable())->format('Y-m'),
);

$profile = $client->users()->get();
$userMonthly = $client->users()->stats(UserResource::GRANULARITY_MONTHLY);
```

Daily granularity requires a `YYYY-MM` month. `users()->get()` and `users()->stats()` also accept
an optional Bearer token when used during authentication bootstrap. Keep production handling for
API errors even after the statistics routes become available in your target environment.

## Download document artifacts

Download methods return raw bytes rather than JSON.

```php
use Assinafy\SDK\Resources\DocumentResource;

$originalPdf = $client->documents()->download(
    $documentId,
    DocumentResource::ARTIFACT_ORIGINAL,
);

$signedPdf = $client->documents()->download(
    $documentId,
    DocumentResource::ARTIFACT_CERTIFICATED,
);

file_put_contents('sandbox-original.pdf', $originalPdf);
file_put_contents('sandbox-certificated.pdf', $signedPdf);
```

## Templates

Template upload, detail, update, delete, and page download work in the API runtime but are not currently present in the published OpenAPI document. Keep sandbox coverage around them.

```php
$template = $client->templates()->create(requiredEnv('ASSINAFY_TEST_PDF'));
$template = $client->templates()->waitUntilReady(
    templateId: $template['id'],
    maxWaitSeconds: 60,
    pollIntervalSeconds: 2,
);

$client->templates()->update($template['id'], [
    'name' => 'Sandbox template',
    'document_name' => 'Sandbox generated document',
    'message' => 'Please review this sandbox document.',
]);

if (isset($template['pages'][0]['id'])) {
    $pageImage = $client->templates()->downloadPage(
        $template['id'],
        $template['pages'][0]['id'],
    );
    file_put_contents('sandbox-template-page.jpg', $pageImage);
}
```

Use `documents()->createFromTemplate()` only after roles and field placements have been configured in Assinafy. Bind each signer to a real `role_id` returned by the selected template rather than hard-coding an ID.

```php
$roleId = $template['roles'][0]['id'];

$created = $client->documents()->createFromTemplate(
    templateId: $template['id'],
    signers: [
        ['role_id' => $roleId, 'id' => $signerId],
    ],
    options: ['name' => 'Sandbox generated document'],
);
```

## Public authentication and OAuth URL helpers

Use a public client before an API key exists. OAuth helpers build browser URLs; they do not send the redirect request through the JSON transport.

```php
use Assinafy\SDK\Resources\AuthResource;

$publicClient = AssinafyClient::forAuth(Configuration::SANDBOX_BASE_URL);

$oauthStartUrl = $publicClient->auth()->socialLoginUrl(AuthResource::PROVIDER_GOOGLE);
$oauthCallbackUrl = $publicClient->auth()->socialLoginCallbackUrl();

// In a web controller, redirect the user's browser to $oauthStartUrl.
// Configure $oauthCallbackUrl with the OAuth integration as required by Assinafy.
```

For password login, load both values from secret input and never commit them:

```php
$session = $publicClient->auth()->login(
    requiredEnv('ASSINAFY_LOGIN_EMAIL'),
    requiredEnv('ASSINAFY_LOGIN_PASSWORD'),
);

$accessToken = $session['access_token'];
```

Use the explicit token while discovering accounts, then configure it globally for every
workspace resource:

```php
$accounts = $publicClient->accounts()->list($accessToken);

$bearerClient = AssinafyClient::forBearer(
    accessToken: $accessToken,
    accountId: $accounts['data'][0]['id'],
    baseUrl: Configuration::SANDBOX_BASE_URL,
);

$authenticatedUser = $bearerClient->users()->get();
$bearerDocuments = $bearerClient->documents()->list(page: 1, perPage: 20);
$maskedApiKey = $bearerClient->auth()->getApiKey();
```

OpenAPI declares the `/users/self` `data` member as an `AuthUser`. The sandbox currently wraps it
as `{user: AuthUser, accounts: AuthAccount[]}`; `users()->get()` normalizes `data.user`, so
`$authenticatedUser` is the user object in either case. Continue using `accounts()->list()` for
account discovery.

API-key lifecycle and password-change methods accept a nullable per-call token. Pass `null` to
fall back to the API key or global Bearer token configured on the client; pass `$accessToken`
when using `forAuth()`:

```php
// These mutate credentials. Run them only for a disposable sandbox user.
$generatedApiKey = $bearerClient->auth()->generateApiKey(
    null,
    requiredEnv('ASSINAFY_LOGIN_PASSWORD'),
);

$bearerClient->auth()->changePassword(
    null,
    requiredEnv('ASSINAFY_LOGIN_EMAIL'),
    requiredEnv('ASSINAFY_LOGIN_PASSWORD'),
    requiredEnv('ASSINAFY_LOGIN_NEW_PASSWORD'),
);

$bearerClient->auth()->deleteApiKey();
```

The methods above are separately unit-tested. A successful live run requires a disposable user,
password control, and—after deletion or password change—a recovery plan; the API key and email
addresses alone are not sufficient authorization to exercise them safely.

## Public document send-token

This endpoint sends a real sandbox notification. The published body still shows `{email}`, but
the running sandbox requires `{recipient, channel}`. The recipient must already be a signer
assigned to the target document; an arbitrary email address is rejected. The workflow above
created that assignment before this call, and the SDK deliberately sends the runtime body shape:

```php
use Assinafy\SDK\Resources\DocumentResource;

$publicClient->documents()->sendToken(
    documentId: $documentId,
    recipient: $signerEmail,
    channel: DocumentResource::SEND_TOKEN_CHANNEL_EMAIL,
);
```

The successful request asks Assinafy to deliver the one-time access code; it does not return that
code. Read it from the assigned signer's controlled inbox. The assignment's `signing_urls` expose
only signer IDs and URLs, and a URL path segment is not a usable substitute—the audited sandbox
returned `401` for that heuristic.

## Signer-facing flow

Signer-facing methods do not authenticate with the workspace API key. The SDK sends the
inbox-delivered per-signer code in the `signer-access-code` query parameter. Treat that code as a
secret and use a public client when implementing the signer experience. These calls are runnable
only when you control the recipient inbox and supply a real code:

```php
$signerClient = AssinafyClient::forAuth(Configuration::SANDBOX_BASE_URL);
$accessCode = requiredEnv('ASSINAFY_SIGNER_ACCESS_CODE');
$documentId = requiredEnv('ASSINAFY_TEST_DOCUMENT_ID');
$assignmentId = requiredEnv('ASSINAFY_TEST_ASSIGNMENT_ID');

$session = $signerClient->signerSession();

$signerProfile = $session->self($accessCode);
$session->acceptTerms($accessCode);
$session->verifyCode(
    $accessCode,
    requiredEnv('ASSINAFY_TEST_VERIFICATION_CODE'),
);
```

`acceptTerms()` sends `signer-access-code` in the query and deliberately sends no request body.
The published `confirm-data` body fields are `full_name`, `email`, and `government_id`. Terms
acceptance is a separate call.

```php
$confirmedSigner = $session->confirmData(
    documentId: $documentId,
    accessCode: $accessCode,
    data: [
        'full_name' => 'Sandbox Signer',
        'email' => requiredEnv('ASSINAFY_TEST_SIGNER_EMAIL'),
        'government_id' => requiredEnv('ASSINAFY_TEST_GOVERNMENT_ID'),
    ],
);

$currentDocument = $session->currentDocument($accessCode);
```

Collect assignments accept field entries using the API's camelCase field names:

```php
$session->sign($documentId, $assignmentId, $accessCode, [
    [
        'itemId' => requiredEnv('ASSINAFY_TEST_ITEM_ID'),
        'fieldId' => requiredEnv('ASSINAFY_TEST_FIELD_ID'),
        'pageId' => requiredEnv('ASSINAFY_TEST_PAGE_ID'),
        'value' => 'Sandbox value',
    ],
]);
```

Signer document methods use the same query authentication, including search and download:

```php
$signerId = requiredEnv('ASSINAFY_SIGNER_ID');
$signerDocuments = $signerClient->signerDocuments();

$current = $signerDocuments->current($signerId, $accessCode);
$matches = $signerDocuments->search($signerId, $accessCode, 'sandbox');
$list = $signerDocuments->list($signerId, $accessCode, ['page' => 1, 'per-page' => 20]);

$pdf = $signerDocuments->download(
    $signerId,
    $documentId,
    $accessCode,
    DocumentResource::ARTIFACT_ORIGINAL,
);
```

## Fields and tags

```php
$field = $client->fields()->create('text', 'Sandbox field', [
    'is_required' => true,
]);

$validation = $client->fields()->validate($field['id'], 'Sandbox value');
$fieldTypes = $client->fields()->types();

$tag = $client->tags()->create('Sandbox');
$client->documents()->appendTags($documentId, [$tag['name']]);
$documentTags = $client->documents()->listTags($documentId);
```

Document-tag list/replace/append/detach routes are part of the current OpenAPI document. Its body
description calls the values tag IDs, but the sandbox and SDK use names and auto-create missing
names. These operations should not be confused with template create/get/update/delete and page
download, which remain live-tested runtime extensions absent from the published path inventory.

## Webhook subscription and receiver

The API maintains one subscription per account. Registration is an upsert; deactivation pauses delivery without deleting the stored configuration.

```php
use Assinafy\SDK\Resources\WebhookResource;

$subscription = $client->webhooks()->register(
    url: requiredEnv('ASSINAFY_TEST_WEBHOOK_URL'),
    email: requiredEnv('ASSINAFY_TEST_NOTIFICATION_EMAIL'),
    events: [
        WebhookResource::EVENT_DOCUMENT_READY,
        WebhookResource::EVENT_SIGNER_SIGNED,
        WebhookResource::EVENT_SIGNER_REJECTED,
    ],
);

$eventTypes = $client->webhooks()->eventTypes();
$dispatches = $client->webhooks()->dispatches(['delivered' => 'false']);
```

The current API contract does not define a webhook signature or secret. `webhookEvents()` parses an event; it does not authenticate it. Re-fetch the referenced entity before any side effect.

```php
$rawBody = file_get_contents('php://input');
if (!is_string($rawBody)) {
    http_response_code(400);
    exit;
}

$parser = $client->webhookEvents();
$event = $parser->extractEvent($rawBody);

if ($event === null) {
    http_response_code(400);
    exit;
}

$eventType = $parser->getEventType($event);
$entity = $parser->getEventData($event);

if ($eventType === WebhookResource::EVENT_DOCUMENT_READY && isset($entity['id'])) {
    $authoritativeDocument = $client->documents()->get((string) $entity['id']);
    // Enqueue idempotent processing using the authenticated API result.
}

http_response_code(200);
```

## Logging and redaction

Pass any PSR-3 logger to the client constructor or install one later. The internal `MutableLogger` proxy propagates `setLogger()` to already-created resources and the default transport.

```php
use Psr\Log\LoggerInterface;

/** @var LoggerInterface $logger */
$client->documents(); // A resource may already exist.
$client->setLogger($logger);
```

The default transport passes diagnostic context through `LogRedactor`. Applications can use the same utility before recording their own SDK-adjacent context:

```php
use Assinafy\SDK\Http\LogRedactor;

$safeContext = LogRedactor::redact([
    'document_id' => $documentId,
    'authorization' => 'Bearer ' . $accessToken,
]);

$logger->debug('Assinafy application context', $safeContext);
```

This does not sanitize logging performed elsewhere by the application; never log raw webhook bodies or secret environment variables.

## Error handling

```php
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\NetworkException;
use Assinafy\SDK\Exceptions\ValidationException;

try {
    $client->documents()->get($documentId);
} catch (ValidationException $exception) {
    // Local structured validation failure.
    $errors = $exception->getErrors();
} catch (ApiException $exception) {
    // Non-success response from the API.
    $status = $exception->getStatusCode();
    $response = $exception->getResponseData();
} catch (NetworkException $exception) {
    // DNS, TLS, connection, or timeout failure.
    $message = $exception->getMessage();
}
```
