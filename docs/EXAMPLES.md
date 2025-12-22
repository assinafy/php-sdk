# Assinafy PHP SDK - Examples

## Basic Setup

```php
<?php

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;

$client = AssinafyClient::create(
    apiKey: 'your-api-key',
    accountId: 'your-account-id',
    webhookSecret: 'your-webhook-secret'
);
```

## Example 1: Simple Document Upload and Signature

```php
<?php

$result = $client->uploadAndRequestSignatures(
    filePath: '/path/to/contract.pdf',
    fileName: 'Employment Contract.pdf',
    signers: [
        [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'cpf' => '12345678900',
        ],
    ],
    message: 'Please sign the employment contract'
);

echo "Document ID: {$result['document']['document_id']}\n";
```

## Example 2: Multiple Signers with Metadata

```php
<?php

$document = $client->documents()->upload(
    filePath: 'contracts/nda.pdf',
    fileName: 'Non-Disclosure Agreement.pdf',
    metadata: [
        'contract_type' => 'nda',
        'department' => 'legal',
        'year' => 2024,
    ]
);

$documentId = $document['document_id'];

$client->documents()->waitUntilReady($documentId, maxWaitSeconds: 60);

$signers = [
    [
        'name' => 'Alice Johnson',
        'email' => 'alice@company.com',
        'cpf' => '11111111111',
        'role' => 'employee',
    ],
    [
        'name' => 'Bob Manager',
        'email' => 'bob@company.com',
        'cpf' => '22222222222',
        'role' => 'manager',
    ],
];

$signerIds = [];
foreach ($signers as $signerData) {
    $signer = $client->signers()->create(
        name: $signerData['name'],
        email: $signerData['email'],
        cpf: $signerData['cpf'],
        metadata: ['role' => $signerData['role']]
    );
    
    $signerIds[] = $signer['data']['id'];
}

$assignment = $client->assignments()->create(
    documentId: $documentId,
    signers: $signerIds,
    message: 'Please review and sign the NDA',
    expiresAt: date('Y-m-d', strtotime('+30 days'))
);

echo "Assignment created successfully!\n";
```

## Example 3: Checking Document Status

```php
<?php

$documentId = 'doc_abc123';

$document = $client->documents()->get($documentId);

echo "Status: {$document['status']}\n";

$progress = $client->documents()->getSigningProgress($documentId);

echo "Signing Progress:\n";
echo "  Total signers: {$progress['total']}\n";
echo "  Signed: {$progress['signed']}\n";
echo "  Pending: {$progress['pending']}\n";
echo "  Percentage: {$progress['percentage']}%\n";

if ($client->documents()->isFullySigned($documentId)) {
    echo "Document is fully signed!\n";
    
    $pdfContent = $client->documents()->download($documentId);
    file_put_contents("signed-{$documentId}.pdf", $pdfContent);
    echo "Signed document downloaded.\n";
}
```

## Example 4: Webhook Handler

```php
<?php

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;

$client = AssinafyClient::fromArray([
    'api_key' => $_ENV['ASSINAFY_API_KEY'],
    'account_id' => $_ENV['ASSINAFY_ACCOUNT_ID'],
    'webhook_secret' => $_ENV['ASSINAFY_WEBHOOK_SECRET'],
]);

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_ASSINAFY_SIGNATURE'] ?? '';

$verifier = $client->webhookVerifier();

if (!$verifier->verify($payload, $signature)) {
    http_response_code(401);
    exit(json_encode(['error' => 'Invalid signature']));
}

$event = $verifier->extractEvent($payload);
$eventType = $verifier->getEventType($event);
$eventData = $verifier->getEventData($event);

error_log("Received webhook: {$eventType}");

switch ($eventType) {
    case 'document_ready':
        handleDocumentReady($eventData);
        break;
        
    case 'signer_signed_document':
        handleSignerSigned($eventData);
        break;
        
    case 'signer_rejected_document':
        handleSignerRejected($eventData);
        break;
        
    case 'document_processing_failed':
        handleProcessingFailed($eventData);
        break;
        
    default:
        error_log("Unhandled event type: {$eventType}");
}

http_response_code(200);
echo json_encode(['success' => true]);

function handleDocumentReady(array $data): void
{
    $documentId = $data['id'] ?? null;
    error_log("Document {$documentId} is ready for signing");
}

function handleSignerSigned(array $data): void
{
    $documentId = $data['id'] ?? null;
    error_log("Signer signed document {$documentId}");
}

function handleSignerRejected(array $data): void
{
    $documentId = $data['id'] ?? null;
    $reason = $data['decline_reason'] ?? 'Unknown';
    error_log("Document {$documentId} was rejected: {$reason}");
}

function handleProcessingFailed(array $data): void
{
    $documentId = $data['id'] ?? null;
    error_log("Document {$documentId} processing failed");
}
```

## Example 5: Laravel Integration

```php
<?php

namespace App\Services;

use Assinafy\SDK\AssinafyClient;
use Illuminate\Support\Facades\Log;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class AssinafyService
{
    private AssinafyClient $client;
    
    public function __construct()
    {
        $logger = new Logger('assinafy');
        $logger->pushHandler(
            new StreamHandler(storage_path('logs/assinafy.log'), Logger::INFO)
        );
        
        $this->client = AssinafyClient::fromArray([
            'api_key' => config('services.assinafy.api_key'),
            'account_id' => config('services.assinafy.account_id'),
            'webhook_secret' => config('services.assinafy.webhook_secret'),
        ]);
        
        $this->client->setLogger($logger);
    }
    
    public function sendContract(string $filePath, array $signers, string $message = ''): array
    {
        try {
            return $this->client->uploadAndRequestSignatures(
                filePath: $filePath,
                fileName: basename($filePath),
                signers: $signers,
                message: $message
            );
        } catch (\Exception $e) {
            Log::error('Failed to send contract', [
                'error' => $e->getMessage(),
                'file' => $filePath,
            ]);
            throw $e;
        }
    }
    
    public function getDocumentStatus(string $documentId): array
    {
        return $this->client->documents()->get($documentId);
    }
    
    public function downloadSignedDocument(string $documentId, string $savePath): void
    {
        $content = $this->client->documents()->download($documentId);
        file_put_contents($savePath, $content);
    }
}
```

## Example 6: Symfony Integration

```php
<?php

namespace App\Service;

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;
use Psr\Log\LoggerInterface;

class AssinafyService
{
    private AssinafyClient $client;
    
    public function __construct(
        string $apiKey,
        string $accountId,
        string $webhookSecret,
        LoggerInterface $logger
    ) {
        $config = new Configuration(
            apiKey: $apiKey,
            accountId: $accountId,
            webhookSecret: $webhookSecret
        );
        
        $this->client = new AssinafyClient($config, null, $logger);
    }
    
    public function createAndSendContract(
        string $pdfPath,
        array $signers,
        array $metadata = []
    ): string {
        $result = $this->client->uploadAndRequestSignatures(
            filePath: $pdfPath,
            fileName: basename($pdfPath),
            signers: $signers,
            metadata: $metadata
        );
        
        return $result['document']['document_id'];
    }
}
```

### services.yaml

```yaml
services:
    App\Service\AssinafyService:
        arguments:
            $apiKey: '%env(ASSINAFY_API_KEY)%'
            $accountId: '%env(ASSINAFY_ACCOUNT_ID)%'
            $webhookSecret: '%env(ASSINAFY_WEBHOOK_SECRET)%'
            $logger: '@monolog.logger'
```

## Example 7: Error Handling

```php
<?php

use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Exceptions\NetworkException;
use Assinafy\SDK\Exceptions\AssinafyException;

try {
    $document = $client->documents()->upload(
        filePath: 'contract.pdf',
        fileName: 'Contract.pdf'
    );
    
} catch (ValidationException $e) {
    echo "Validation failed:\n";
    foreach ($e->getErrors() as $field => $error) {
        echo "  {$field}: {$error}\n";
    }
    
} catch (ApiException $e) {
    echo "API Error (HTTP {$e->getStatusCode()}): {$e->getMessage()}\n";
    
    if ($e->getStatusCode() === 401) {
        echo "Authentication failed. Check your API key.\n";
    } elseif ($e->getStatusCode() === 429) {
        echo "Rate limit exceeded. Try again later.\n";
    }
    
} catch (NetworkException $e) {
    echo "Network error: {$e->getMessage()}\n";
    echo "Please check your internet connection.\n";
    
} catch (AssinafyException $e) {
    echo "Assinafy SDK error: {$e->getMessage()}\n";
    print_r($e->getContext());
}
```

## Example 8: Batch Processing

```php
<?php

$contracts = [
    ['file' => 'contract1.pdf', 'email' => 'john@example.com', 'name' => 'John Doe'],
    ['file' => 'contract2.pdf', 'email' => 'jane@example.com', 'name' => 'Jane Smith'],
    ['file' => 'contract3.pdf', 'email' => 'bob@example.com', 'name' => 'Bob Johnson'],
];

$results = [];

foreach ($contracts as $contract) {
    try {
        $result = $client->uploadAndRequestSignatures(
            filePath: $contract['file'],
            fileName: basename($contract['file']),
            signers: [
                [
                    'name' => $contract['name'],
                    'email' => $contract['email'],
                ],
            ],
            message: 'Please sign this contract'
        );
        
        $results[] = [
            'success' => true,
            'file' => $contract['file'],
            'document_id' => $result['document']['document_id'],
        ];
        
        echo "Sent to {$contract['name']}: {$result['document']['document_id']}\n";
        
        sleep(1);
        
    } catch (\Exception $e) {
        $results[] = [
            'success' => false,
            'file' => $contract['file'],
            'error' => $e->getMessage(),
        ];
        
        echo "Failed to send to {$contract['name']}: {$e->getMessage()}\n";
    }
}

echo "\nSummary:\n";
echo "  Total: " . count($contracts) . "\n";
echo "  Success: " . count(array_filter($results, fn($r) => $r['success'])) . "\n";
echo "  Failed: " . count(array_filter($results, fn($r) => !$r['success'])) . "\n";
```

