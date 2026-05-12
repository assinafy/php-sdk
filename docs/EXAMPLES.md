# Assinafy PHP SDK — Examples

Every example below uses the v1 SDK against `https://api.assinafy.com.br/v1`. Each one maps
directly to a documented API endpoint.

## Basic Setup

```php
<?php

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;

$client = AssinafyClient::create(
    apiKey: 'your-api-key',
    accountId: 'your-account-id',
    baseUrl: Configuration::DEFAULT_BASE_URL,   // or SANDBOX_BASE_URL
    webhookSecret: 'your-webhook-secret',
);
```

## 1. Upload a document and request signatures (one-call helper)

```php
$result = $client->uploadAndRequestSignatures(
    filePath: '/path/to/contract.pdf',
    signers: [
        ['full_name' => 'John Doe',  'email' => 'john@example.com'],
        ['full_name' => 'Jane Smith','email' => 'jane@example.com', 'whatsapp_phone_number' => '+5548999990000'],
    ],
    message:   'Please sign the employment contract',
    expiresAt: '2026-12-31T23:59:00Z',
);

echo "Document ID: {$result['document']['id']}\n";
echo "Assignment ID: {$result['assignment']['id']}\n";
```

## 2. Long-form upload → wait → assign

```php
use Assinafy\SDK\Resources\AssignmentResource;

// 1. Upload the PDF
$document   = $client->documents()->upload('contracts/nda.pdf');
$documentId = $document['id'];

// 2. Wait until metadata extraction completes
$client->documents()->waitUntilReady($documentId, maxWaitSeconds: 60);

// 3. Create (or look up) each signer
$signerIds = [];
foreach ([
    ['full_name' => 'Alice Johnson', 'email' => 'alice@company.com'],
    ['full_name' => 'Bob Manager',   'email' => 'bob@company.com'],
] as $s) {
    $existing = $client->signers()->findByEmail($s['email']);
    $signer   = $existing ?: $client->signers()->create($s['full_name'], $s['email']);
    $signerIds[] = $signer['id'];
}

// 4. Dispatch the assignment
$assignment = $client->assignments()->create(
    documentId: $documentId,
    signers: $signerIds,
    method: AssignmentResource::METHOD_VIRTUAL,
    options: [
        'message'    => 'Please sign the NDA',
        'expires_at' => '2026-12-31T23:59:00Z',
    ],
);

foreach ($assignment['signing_urls'] as $url) {
    echo "{$url['signer_id']}: {$url['url']}\n";
}
```

## 3. Use WhatsApp verification

```php
use Assinafy\SDK\Resources\AssignmentResource;

$assignment = $client->assignments()->create(
    documentId: $documentId,
    signers: [
        [
            'id' => $signerId,
            'verification_method'  => AssignmentResource::VERIFICATION_WHATSAPP,
            'notification_methods' => ['Whatsapp'],
        ],
    ],
);
```

## 4. Estimate cost before creating

```php
$estimate = $client->assignments()->estimateCost(
    documentId: $documentId,
    signers: [['id' => $signerId, 'verification_method' => 'Whatsapp']],
);

if (!$estimate['has_sufficient_resources']) {
    throw new RuntimeException('Insufficient credits');
}
```

## 5. Download signed artifacts

```php
use Assinafy\SDK\Resources\DocumentResource;

// Original PDF
file_put_contents('original.pdf',
    $client->documents()->download($documentId, DocumentResource::ARTIFACT_ORIGINAL)
);

// Certificated (signed) PDF
file_put_contents('signed.pdf',
    $client->documents()->download($documentId, DocumentResource::ARTIFACT_CERTIFICATED)
);

// Thumbnail JPEG
file_put_contents('thumb.jpg',
    $client->documents()->downloadThumbnail($documentId)
);
```

## 6. Track signing progress

```php
$progress = $client->documents()->getSigningProgress($documentId);
echo "{$progress['signed']}/{$progress['total']} signed ({$progress['percentage']}%)\n";

if ($client->documents()->isFullySigned($documentId)) {
    echo "Document is fully signed and certificated\n";
}
```

## 7. Inspect document activity history

```php
foreach ($client->documents()->activities($documentId) as $event) {
    echo "[{$event['created_at']}] {$event['event']}: {$event['message']}\n";
}
```

## 8. Verify a certificated document by hash

```php
$result = $client->documents()->verify('FE32EDDADE7CBDDCBB934E7402047450B0E59C02');
echo $result['is_valid'] ? 'Valid' : 'Invalid';
```

## 9. Public document info + send sign-token

These endpoints don't require authentication.

```php
$info = $client->documents()->publicInfo($documentId);
echo "Document `{$info['name']}` has {$info['page_count']} page(s).\n";

$client->documents()->sendToken($documentId, 'signer@example.com', 'email');
```

## 10. Create a document from a template

```php
$doc = $client->documents()->createFromTemplate(
    templateId: 'fa7f3e524f3a2cc00a5ea4325e2',
    signers: [
        ['role_id' => 'fa8c14f32d732271e071998246e', 'id' => $signerId],
    ],
    options: [
        'name'       => 'Service Contract 2026',
        'message'    => 'Please review and sign',
        'expires_at' => '2026-12-31T23:59:00Z',
        'editor_fields' => [
            ['field_id' => 'fa9...', 'value' => 'R$ 5,000.00'],
        ],
    ],
);
```

## 11. Webhook subscription

```php
use Assinafy\SDK\Resources\WebhookResource;

$client->webhooks()->register(
    url: 'https://example.com/webhooks/assinafy',
    email: 'admin@example.com',
    events: [
        WebhookResource::EVENT_DOCUMENT_READY,
        WebhookResource::EVENT_SIGNER_SIGNED,
        WebhookResource::EVENT_SIGNER_REJECTED,
        WebhookResource::EVENT_DOCUMENT_PROCESSING_FAILED,
    ],
);

$current = $client->webhooks()->get();
$client->webhooks()->delete();
```

## 12. Webhook receiver

```php
$payload   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_ASSINAFY_SIGNATURE'] ?? '';

$verifier = $client->webhookVerifier();
if (!$verifier->verify($payload, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = $verifier->extractEvent($payload);
switch ($verifier->getEventType($event)) {
    case 'signer_signed_document':
        $data = $verifier->getEventData($event);
        // handle …
        break;
}
```

## 13. Login / API-key bootstrap

```php
$session = $client->auth()->login('user@example.com', 'secret');
$accessToken = $session['access_token'];

$apiKey = $client->auth()->generateApiKey($accessToken, 'secret');
echo $apiKey['api_key'];
```

## 14. Signer-facing endpoints (the signer's browser flow)

These calls do NOT use the workspace API key — they use the per-signer `signer-access-code`
that Assinafy emails to each signer.

```php
$session = $client->signerSession();

$me = $session->self($accessCode);                       // GET /signers/self
$session->acceptTerms($accessCode);                      // PUT /signers/accept-terms
$session->verifyCode($accessCode, '123456');             // POST /verify
$session->confirmData($documentId, $accessCode, [        // PUT /documents/{id}/signers/confirm-data
    'email' => 'signer@example.com',
    'has_accepted_terms' => true,
]);

// Upload a signature image
$session->uploadSignature(
    $accessCode,
    \Assinafy\SDK\Resources\SignerSessionResource::TYPE_SIGNATURE,
    file_get_contents('signature.png'),
    'image/png',
);

// Download the stored signature
$png = $session->downloadSignature($accessCode, 'signature');
```

## 15. Error handling

```php
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Exceptions\NetworkException;

try {
    $client->documents()->upload('/path/to/file.pdf');
} catch (ValidationException $e) {
    // Client-side validation: bad email, missing file, wrong artifact name, etc.
    print_r($e->getErrors());
} catch (ApiException $e) {
    // Server-side error from Assinafy
    printf("API %d: %s\n", $e->getStatusCode(), $e->getMessage());
    print_r($e->getResponseData());
} catch (NetworkException $e) {
    // Transport-level error (timeout, DNS, etc.)
    echo "Network: {$e->getMessage()}\n";
}
```
