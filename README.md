# Assinafy PHP SDK

Modern, framework-agnostic PHP SDK for the Assinafy digital signature API. Built with PSR standards and SOLID principles.

## Features

- PSR-4 autoloading
- PSR-3 logging support
- PSR-18 HTTP client interface
- Framework agnostic - works with any PHP project
- Clean architecture with SOLID principles
- Comprehensive exception handling
- Fluent API design
- Type-safe with PHP 8.1+

## Requirements

- PHP 7.4 or higher (PHP 8.0+ recommended for named arguments)
- ext-json

## Installation

Install via Composer:

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
        [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'cpf' => '12345678900',
        ],
        [
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'cpf' => '09876543211',
        ],
    ],
    message: 'Please sign this contract'
);

echo "Document ID: {$result['document']['document_id']}\n";
```

## Configuration

### From Constructor

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
```

### From Array

```php
$client = AssinafyClient::fromArray([
    'api_key' => 'your-api-key',
    'account_id' => 'your-account-id',
    'webhook_secret' => 'your-webhook-secret',
    'base_url' => 'https://api.assinafy.com.br/v1',
    'timeout' => 30,
]);
```

## Usage

### Documents

#### Upload a Document

```php
$document = $client->documents()->upload(
    filePath: '/path/to/document.pdf',
    fileName: 'contract.pdf',
    metadata: ['type' => 'service_contract']
);

$documentId = $document['document_id'];
```

#### Get Document Details

```php
$document = $client->documents()->get($documentId);
```

#### List Documents

```php
$documents = $client->documents()->list(
    page: 1,
    perPage: 20,
    filters: ['status' => 'signed']
);
```

#### Wait for Document to be Ready

```php
$document = $client->documents()->waitUntilReady(
    documentId: $documentId,
    maxWaitSeconds: 30,
    pollIntervalSeconds: 2
);
```

#### Download Signed Document

```php
$pdfContent = $client->documents()->download($documentId);
file_put_contents('signed-contract.pdf', $pdfContent);
```

#### Check Signing Progress

```php
$progress = $client->documents()->getSigningProgress($documentId);

echo "Signed: {$progress['signed']}/{$progress['total']}\n";
echo "Progress: {$progress['percentage']}%\n";
```

### Signers

#### Create a Signer

```php
$signer = $client->signers()->create(
    name: 'John Doe',
    email: 'john@example.com',
    cpf: '12345678900',
    phone: '+5511999999999',
    metadata: ['department' => 'sales']
);

$signerId = $signer['data']['id'];
```

#### Get Signer Details

```php
$signer = $client->signers()->get($signerId);
```

#### List Signers

```php
$signers = $client->signers()->list(
    page: 1,
    perPage: 20,
    search: 'john@example.com'
);
```

#### Find Signer by Email

```php
$signer = $client->signers()->findByEmail('john@example.com');
```

### Assignments

#### Request Signatures

```php
$assignment = $client->assignments()->create(
    documentId: $documentId,
    signers: [$signerId1, $signerId2],
    method: 'virtual',
    message: 'Please sign this document',
    expiresAt: '2024-12-31'
);
```

#### Cancel Signature Request

```php
$result = $client->assignments()->cancel(
    documentId: $documentId,
    reason: 'No longer needed'
);
```

#### Resend Notification

```php
$result = $client->assignments()->resendNotification(
    documentId: $documentId,
    signerId: $signerId
);
```

### Webhooks

#### Register Webhook

```php
$webhook = $client->webhooks()->register(
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

#### Get Webhook Subscription

```php
$subscription = $client->webhooks()->get();
```

#### Delete Webhook

```php
$client->webhooks()->delete();
```

### Webhook Verification

```php
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_ASSINAFY_SIGNATURE'] ?? '';

$verifier = $client->webhookVerifier();

if (!$verifier->verify($payload, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

$event = $verifier->extractEvent($payload);
$eventType = $verifier->getEventType($event);
$eventData = $verifier->getEventData($event);

switch ($eventType) {
    case 'document_ready':
        break;
    case 'signer_signed_document':
        break;
    case 'signer_rejected_document':
        break;
}
```

## Logging

The SDK supports PSR-3 logging:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('assinafy');
$logger->pushHandler(new StreamHandler('/path/to/assinafy.log', Logger::DEBUG));

$client = new AssinafyClient($config, null, $logger);
```

## Custom HTTP Client

You can provide your own PSR-18 compatible HTTP client:

```php
use Assinafy\SDK\Http\HttpClientInterface;

class CustomHttpClient implements HttpClientInterface
{
    public function get(string $uri, array $params = [], array $headers = []): Response
    {
    }
    
}

$client = new AssinafyClient($config, new CustomHttpClient());
```

## Exception Handling

```php
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Exceptions\NetworkException;

try {
    $client->documents()->upload('/path/to/file.pdf', 'contract.pdf');
} catch (ValidationException $e) {
    echo "Validation error: {$e->getMessage()}\n";
    print_r($e->getErrors());
} catch (ApiException $e) {
    echo "API error: {$e->getMessage()}\n";
    echo "Status code: {$e->getStatusCode()}\n";
    print_r($e->getResponseData());
} catch (NetworkException $e) {
    echo "Network error: {$e->getMessage()}\n";
}
```

## Complete Workflow Example

```php
<?php

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;

$client = AssinafyClient::create(
    apiKey: $_ENV['ASSINAFY_API_KEY'],
    accountId: $_ENV['ASSINAFY_ACCOUNT_ID']
);

try {
    $document = $client->documents()->upload(
        filePath: 'contract.pdf',
        fileName: 'Service Contract.pdf',
        metadata: ['type' => 'service', 'year' => 2024]
    );
    
    $documentId = $document['document_id'];
    echo "Document uploaded: {$documentId}\n";
    
    $client->documents()->waitUntilReady($documentId);
    echo "Document is ready for signing\n";
    
    $signer1 = $client->signers()->create(
        name: 'John Doe',
        email: 'john@example.com',
        cpf: '12345678900'
    );
    
    $signer2 = $client->signers()->create(
        name: 'Jane Smith',
        email: 'jane@example.com',
        cpf: '09876543211'
    );
    
    $assignment = $client->assignments()->create(
        documentId: $documentId,
        signers: [$signer1['data']['id'], $signer2['data']['id']],
        message: 'Please review and sign this contract'
    );
    
    echo "Signature request sent successfully!\n";
    
    $progress = $client->documents()->getSigningProgress($documentId);
    echo "Progress: {$progress['signed']}/{$progress['total']} ({$progress['percentage']}%)\n";
    
} catch (\Exception $e) {
    echo "Error: {$e->getMessage()}\n";
}
```

## Code Quality

The SDK maintains high code quality standards:

```bash
# Check code style (PSR-12)
make phpcs

# Fix code style automatically
make phpcbf

# Run static analysis (PHPStan level 8)
make phpstan

# Run all quality checks
make quality
```

**Current Status**:
- ✅ PSR-12 compliant (100%)
- ✅ PHPStan level 8 (zero errors)
- ✅ PHP 7.4 - 8.3 compatible

## Testing

```bash
composer test
```

## License

MIT

## Support

For issues, questions, or contributions, please visit the GitHub repository.

