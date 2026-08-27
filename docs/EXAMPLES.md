# Assinafy PHP SDK examples

These examples target the Assinafy v1 sandbox. Keep production and sandbox credentials separate, load secrets from environment variables or a secret manager, and use disposable sandbox entities for operations that create, update, sign, or delete data.

The complete endpoint and response mapping is in [API_REFERENCE.md](API_REFERENCE.md).
These examples target the `v2.1.2` release. Packagist does not currently expose
`assinafy/php-sdk`; see
[INSTALLATION.md](INSTALLATION.md) for the released tag and repository installation choices.

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
`notification_methods`, and `step` in signer arrays. An ordinary assignment allows zero or one
notification method. For Email/WhatsApp verification, a non-empty notification must match; when
only one is supplied, the API infers the other, and omitting both defaults to Email.
DigitalCertificate is exempt from channel equality. The API accepts an explicit empty
notification list, which the SDK forwards unchanged.
An exact email match is reused without applying the supplied name or phone; update and verify the
stored signer first if WhatsApp is required. For DigitalCertificate, supply an existing signer ID
after setting its `government_id`; the helper deliberately does not resolve certificate signers by
email. The helper does not accept a `fileName` argument; the uploaded file supplies its name.

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

`waitUntilReady()` returns when document status reaches `metadata_ready`, `pending_signature`,
`ready`, `certificating`, or `certificated`. It throws for terminal failure/rejection states or
when the timeout expires. Webhook processing can emit `ready`, which is exposed as
`DocumentResource::STATUS_READY`.

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

### Digital-certificate assignments

The current contract accepts `DigitalCertificate` for ordinary and template assignment creation
and estimation. Each certificate signer costs two credits in addition to notification cost,
requires the account's Digital Certificate feature, must have a CPF/CNPJ in `government_id`, and
must be alone in its signing step. Signer creation has no `government_id` field, so update an
existing signer first. Formatted CPF/CNPJ input is accepted and normalized to digits by the
server. The update response omits `government_id`, so its absence there does not indicate failure.
Sandbox currently rejects certificate assignment creation with `400` (`Invalid method`). Run the
following only after Assinafy confirms the feature and completion protocol are enabled in the
target environment:

```php
$certificateSignerId = requiredEnv('ASSINAFY_CERTIFICATE_SIGNER_ID');

$client->signers()->update($certificateSignerId, [
    'government_id' => requiredEnv('ASSINAFY_TEST_GOVERNMENT_ID'),
]);

$certificateEstimate = $client->assignments()->estimateCost(
    documentId: $document['id'],
    signers: [[
        'verification_method' => AssignmentResource::VERIFICATION_DIGITAL_CERTIFICATE,
        'notification_methods' => [AssignmentResource::NOTIFICATION_EMAIL],
    ]],
);

$certificateAssignment = $client->assignments()->create(
    documentId: $document['id'],
    signers: [[
        'id' => $certificateSignerId,
        'verification_method' => AssignmentResource::VERIFICATION_DIGITAL_CERTIFICATE,
        'notification_methods' => [AssignmentResource::NOTIFICATION_EMAIL],
        'step' => 1, // No other signer may share this step.
    ]],
);
```

The ordinary `signerSession()->sign()` path cannot complete an ICP-Brasil certificate signature.
The sign-operation prose names `POST /v1/signers/certificate/start` and
`POST /v1/signers/certificate/complete`, but publishes no path, authentication, request, or
response contract for either route. The SDK does not call them.

Changing a signer's already verified email or WhatsApp number is rejected while that signer has
an in-flight document. Changing an unverified channel succeeds but rotates its access and
verification codes, invalidating earlier links/OTPs; call `assignments()->resend()` afterward.
Certificated documents do not block channel updates.

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
These published routes are not currently available in sandbox. The following is the usage for a
deployment where Assinafy has enabled them:

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

$certificateDocumentId = getenv('ASSINAFY_CERTIFICATE_DOCUMENT_ID');
if (is_string($certificateDocumentId) && $certificateDocumentId !== '') {
    $padesPdf = $client->documents()->download(
        $certificateDocumentId,
        DocumentResource::ARTIFACT_PADES,
    );
    file_put_contents('sandbox-pades.pdf', $padesPdf);
}
```

The `pades` artifact contains the signers' ICP-Brasil signatures plus Assinafy's certification box
and exists only when the document had digital-certificate signers. `bundle` returns a ZIP of the
original, certificated, and certificate-page artifacts, plus `pades` when present. Do not request
`pades` unconditionally in a general document workflow.

## Templates

Template upload, detail, update, delete, and page download are runtime-supported compatibility
operations outside the published OpenAPI document.

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

Do not apply the ordinary assignment's max-one/coupling validation to template payloads. Template
cost estimation and creation accept duplicate entries such as
`notification_methods: ['Email', 'Email']`, which the SDK preserves.

## Public authentication

Use a public client before an API key exists:

```php
$publicClient = AssinafyClient::forAuth(Configuration::SANDBOX_BASE_URL);
```

`socialLoginUrl()` and `socialLoginCallbackUrl()` remain as compatibility helpers, but both GET
routes are outside the current OpenAPI document. Their upstream sandbox and production
configurations do not currently produce usable redirects. They are not an operational OAuth
integration and are intentionally omitted from executable examples.

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

`users()->get()` normalizes both an `AuthUser` response and the wrapped
`{user: AuthUser, accounts: AuthAccount[]}` form, so `$authenticatedUser` is always the user
object. Continue using `accounts()->list()` for account discovery.

## Notification preferences (published; not deployed in sandbox)

The authenticated user's nine owner-facing document email preferences default to `true` and are
always returned as a complete map. A non-empty update may contain any subset; omitted values stay
unchanged. Account and security email such as welcome messages, password resets, invitations, and
account deletion cannot be disabled here. These methods are in the current production OpenAPI but
are not currently available in sandbox. The following is the published usage and response shape,
not a runnable sandbox example:

```php
$preferences = $bearerClient->users()->notificationPreferences();
// [
//     'DocumentCompleted' => true,
//     'SignerDeclined' => true,
//     'DocumentCancelled' => true,
//     'DocumentAboutToExpire' => true,
//     'DocumentExpired' => true,
//     'DocumentExpirationReset' => true,
//     'DocumentProcessingFailed' => true,
//     'TemplateProcessingFailed' => true,
//     'SignerWhatsappFailed' => true,
// ]

$updatedPreferences = $bearerClient->users()->updateNotificationPreferences([
    'DocumentAboutToExpire' => false,
    'SignerWhatsappFailed' => true,
]);
// The response is the same complete nine-key boolean map.
```

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

Using the credential-mutating methods above requires a disposable user, password control, and—after
deletion or password change—a recovery plan. The API key and email addresses alone are not
sufficient authorization to exercise them safely.

## Public document send-token

This endpoint sends a real sandbox notification. Supply a recipient and channel; the recipient
must already be a signer assigned to the target document. An arbitrary email address is rejected.
The workflow above creates that assignment before this call:

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
only signer IDs and URLs; a URL path segment is not the one-time access code.

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
For a virtual assignment, confirm the signer data, load the current document, and finalize with
an empty field list:

```php
$confirmedSigner = $session->confirmData(
    documentId: $documentId,
    accessCode: $accessCode,
    data: [
        'full_name' => 'Sandbox Signer',
        'email' => requiredEnv('ASSINAFY_TEST_SIGNER_EMAIL'),
        'has_accepted_terms' => true,
    ],
);

$currentDocument = $session->currentDocument($accessCode);
$session->sign($documentId, $assignmentId, $accessCode, []);
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

For a DigitalCertificate assignment, `confirmData()` also needs the signer's `government_id` and
`has_accepted_terms: true` before `currentDocument()`. The certificate completion routes have no
published wire contract and are not implemented by this SDK.

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

Document-tag list/replace/append/detach methods use names and auto-create missing names. Template
create/get/update/delete and page download are separate runtime-supported compatibility methods
outside the published OpenAPI path inventory.

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
use Psr\Log\NullLogger;

$logger = new NullLogger();
$client->documents(); // A resource may already exist.
$client->setLogger($logger);
```

The default transport passes diagnostic context through `LogRedactor`. Applications can use the same utility before recording their own SDK-adjacent context:

```php
use Assinafy\SDK\Http\LogRedactor;

$documentId = requiredEnv('ASSINAFY_DOCUMENT_ID');
$accessToken = requiredEnv('ASSINAFY_ACCESS_TOKEN');
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
