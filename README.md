# Assinafy PHP SDK

Modern, framework-agnostic PHP SDK for the [Assinafy](https://assinafy.com.br) digital signature API (`https://api.assinafy.com.br/v1`). Built with PSR standards and SOLID principles.

## Features

- PSR-4 autoloading
- PSR-3 logging support
- PSR-18 HTTP client interface
- Framework agnostic — works with any PHP project
- Clean architecture with SOLID principles
- Comprehensive exception handling
- Type-safe with PHP 8.1+

## Requirements

- PHP 7.4 or higher (PHP 8.0+ recommended for named arguments)
- `ext-json`

## Installation

```bash
composer require assinafy/php-sdk
```

If you want to use the default HTTP client (Guzzle):

```bash
composer require guzzlehttp/guzzle
```

## Quick Start

```php
<?php

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;

$client = AssinafyClient::create(
    apiKey: 'your-api-key',
    accountId: 'your-account-id',
    webhookSecret: 'your-webhook-secret'
);

$result = $client->uploadAndRequestSignatures(
    filePath: '/path/to/contract.pdf',
    fileName: 'contract.pdf',
    signers: [
        ['name' => 'John Doe',   'email' => 'john@example.com',  'cpf' => '12345678900'],
        ['name' => 'Jane Smith', 'email' => 'jane@example.com',  'cpf' => '09876543211'],
    ],
    message: 'Please sign this contract'
);

echo "Document ID: {$result['document']['document_id']}\n";
```

## Configuration

```php
use Assinafy\SDK\Configuration;
use Assinafy\SDK\AssinafyClient;

$config = new Configuration(
    apiKey: 'your-api-key',
    accountId: 'your-account-id',
    baseUrl: 'https://api.assinafy.com.br/v1',
    webhookSecret: 'your-webhook-secret',
    timeout: 30,
    connectTimeout: 10
);

$client = new AssinafyClient($config);

// or from array
$client = AssinafyClient::fromArray([
    'api_key'        => 'your-api-key',
    'account_id'     => 'your-account-id',
    'webhook_secret' => 'your-webhook-secret',
    'base_url'       => 'https://api.assinafy.com.br/v1',
]);
```

## Usage

### Documents

All document endpoints are accessed via `$client->documents()`.

| Method | Endpoint | Description |
| --- | --- | --- |
| `upload($filePath, $fileName, $metadata)` | `POST /accounts/{id}/documents` | Upload a PDF |
| `get($documentId)` | `GET /documents/{id}` | Get document details |
| `list($page, $perPage, $filters)` | `GET /accounts/{id}/documents` | List workspace documents |
| `download($documentId)` | `GET /accounts/{id}/documents/{id}/download` | Download the signed PDF |
| `verify($hash)` | `GET /documents/{hash}/verify` | Verify a certificated document by its signature hash |
| `createFromTemplate($templateId, $signers, $options)` | `POST /accounts/{id}/templates/{templateId}/documents` | Create a document from a template |
| `estimateCostFromTemplate($templateId, $signers)` | `POST /accounts/{id}/templates/{templateId}/documents/estimate-cost` | Estimate credit cost |
| `waitUntilReady($documentId, $maxWait, $pollInterval)` | polls `GET /documents/{id}` | Poll until status is ready |
| `getSigningProgress($documentId)` | derived from `GET /documents/{id}` | Return signed/total/percentage summary |
| `isFullySigned($documentId)` | derived from `GET /documents/{id}` | Boolean check |

```php
// Upload
$doc = $client->documents()->upload('/path/to/contract.pdf', 'contract.pdf');
$documentId = $doc['document_id'];

// Wait for processing
$client->documents()->waitUntilReady($documentId);

// Download the certificated PDF
$pdf = $client->documents()->download($documentId);
file_put_contents('signed.pdf', $pdf);

// Verify a document by hash (public endpoint)
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
    ]
);

// Estimate cost before creating
$estimate = $client->documents()->estimateCostFromTemplate(
    templateId: 'fa7f3e524f3a2cc00a5ea4325e2',
    signers: [['role_id' => 'fa8c14f32d732271e071998246e']]
);
echo "Total cost: {$estimate['data']['total']} credits\n";
```

### Signers

All signer endpoints are accessed via `$client->signers()`.

| Method | Endpoint | Description |
| --- | --- | --- |
| `create($name, $email, $cpf, $phone, $metadata)` | `POST /accounts/{id}/signers` | Create a signer — `$cpf` and `$phone` are sanitized (digits only) before sending |
| `get($signerId)` | `GET /accounts/{id}/signers/{id}` | Get signer details |
| `list($page, $perPage, $search)` | `GET /accounts/{id}/signers` | List signers |
| `update($signerId, $data)` | `PUT /accounts/{id}/signers/{id}` | Update signer fields |
| `delete($signerId)` | `DELETE /accounts/{id}/signers/{id}` | Delete a signer |
| `findByEmail($email)` | `GET /accounts/{id}/signers?search={email}` | Look up signer by email |

```php
// Create (cpf and phone are optional; digits are stripped automatically)
$signer = $client->signers()->create(
    name: 'John Doe',
    email: 'john@example.com',
    cpf: '12345678900',
    phone: '+5511999999999',
    metadata: ['department' => 'sales']
);
$signerId = $signer['data']['id'];

// Update
$client->signers()->update($signerId, [
    'full_name'             => 'John R. Doe',
    'whatsapp_phone_number' => '+5548999990000',
]);

// Delete
$client->signers()->delete($signerId);

// Find by email
$signer = $client->signers()->findByEmail('john@example.com');
```

### Assignments

All assignment endpoints are accessed via `$client->assignments()`.

| Method | Endpoint | Description |
| --- | --- | --- |
| `create($documentId, $signers, $method, $message, $expiresAt)` | `POST /documents/{id}/assignments` | Request signatures |
| `cancel($documentId, $reason)` | `POST /accounts/{id}/signature-requests/{id}/cancel` | Cancel a request |
| `resendNotification($documentId, $signerId)` | legacy resend endpoint | Resend via legacy path |
| `estimateCost($documentId, $signers, $method, $entries)` | `POST /documents/{id}/assignments/estimate-cost` | Estimate credit cost |
| `resend($documentId, $assignmentId, $signerId)` | `PUT /documents/{id}/assignments/{id}/signers/{id}/resend` | Resend notification |
| `estimateResendCost($documentId, $assignmentId, $signerId)` | `POST /documents/{id}/assignments/{id}/signers/{id}/estimate-resend-cost` | Estimate resend cost |
| `resetExpiration($documentId, $assignmentId, $expiresAt)` | `PUT /documents/{id}/assignments/{id}/reset-expiration` | Extend the deadline |

```php
// Create virtual assignment
$assignment = $client->assignments()->create(
    documentId: $documentId,
    signers: [$signerId1, $signerId2],
    method: 'virtual',
    message: 'Please sign this document',
    expiresAt: '2026-12-31T23:59:00Z'
);

// Estimate cost before creating
$estimate = $client->assignments()->estimateCost(
    documentId: $documentId,
    signers: [['verification_method' => 'Whatsapp']],
    method: 'virtual'
);

// Resend notification to a signer
$client->assignments()->resend($documentId, $assignmentId, $signerId);

// Extend the deadline
$client->assignments()->resetExpiration($documentId, $assignmentId, '2027-01-31T23:59:00Z');

// Cancel
$client->assignments()->cancel($documentId, 'Contract renegotiated');
```

### Templates

All template endpoints are accessed via `$client->templates()`.

| Method | Endpoint | Description |
| --- | --- | --- |
| `list($page, $perPage, $filters)` | `GET /accounts/{id}/templates` | List workspace templates |
| `get($templateId)` | `GET /accounts/{id}/templates/{id}` | Get template with roles and pages |

```php
// List ready templates
$templates = $client->templates()->list(1, 20, ['status' => 'ready']);

// Get template roles for document creation
$template = $client->templates()->get('fa7f3e524f3a2cc00a5ea4325e2');
foreach ($template['roles'] as $role) {
    echo "{$role['id']}: {$role['name']}\n";
}
```

### Webhooks

All webhook endpoints are accessed via `$client->webhooks()`.

| Method | Endpoint | Description |
| --- | --- | --- |
| `register($url, $email, $events)` | `PUT /accounts/{id}/webhooks/subscriptions` | Register or update subscription |
| `get()` | `GET /accounts/{id}/webhooks/subscriptions` | Get current subscription |
| `delete()` | `DELETE /accounts/{id}/webhooks/subscriptions` | Delete subscription |

```php
$client->webhooks()->register(
    url: 'https://your-domain.com/webhooks/assinafy',
    email: 'admin@your-domain.com',
    events: [
        'document_ready',
        'signer_signed_document',
        'signer_rejected_document',
        'document_processing_failed',
    ]
);
```

### Webhook Verification

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

switch ($eventType) {
    case 'document_ready':
        // ...
        break;
    case 'signer_signed_document':
        // ...
        break;
}
```

## Logging

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('assinafy');
$logger->pushHandler(new StreamHandler('/path/to/assinafy.log', Logger::DEBUG));

$client = new AssinafyClient($config, null, $logger);
```

## Exception Handling

```php
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Exceptions\NetworkException;

try {
    $client->documents()->upload('/path/to/file.pdf', 'contract.pdf');
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

## Code Quality

```bash
# Check code style (PSR-12)
vendor/bin/phpcs src/

# Fix code style automatically
vendor/bin/phpcbf src/

# Static analysis (PHPStan level 8)
vendor/bin/phpstan analyse --level=8 src/
```

**Current status**: PSR-12 compliant · PHPStan level 8 (zero errors) · PHP 7.4 – 8.3 compatible

## License

MIT
