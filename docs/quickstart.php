<?php

require __DIR__ . '/../vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\ValidationException;

echo "=== Assinafy PHP SDK - Quick Start Example ===\n\n";

$apiKey = $_ENV['ASSINAFY_API_KEY'] ?? '';
$accountId = $_ENV['ASSINAFY_ACCOUNT_ID'] ?? '';

if (empty($apiKey) || empty($accountId)) {
    echo "Error: Please set ASSINAFY_API_KEY and ASSINAFY_ACCOUNT_ID environment variables.\n";
    echo "\nExample:\n";
    echo "export ASSINAFY_API_KEY='your-api-key'\n";
    echo "export ASSINAFY_ACCOUNT_ID='your-account-id'\n";
    exit(1);
}

try {
    echo "1. Creating Assinafy client...\n";
    $client = AssinafyClient::create(
        apiKey: $apiKey,
        accountId: $accountId
    );
    echo "   Client created successfully\n\n";

    echo "2. Testing API connection by listing documents...\n";
    $documents = $client->documents()->list(page: 1, perPage: 5);
    $count = count($documents['data'] ?? []);
    echo "   Connection successful! Found {$count} documents\n\n";

    if ($count > 0) {
        echo "   Recent documents:\n";
        foreach (array_slice($documents['data'] ?? [], 0, 3) as $doc) {
            echo "   - {$doc['name']} (Status: {$doc['status']})\n";
        }
        echo "\n";
    }

    echo "3. Testing signers API...\n";
    $signers = $client->signers()->list(page: 1, perPage: 5);
    $signerCount = count($signers['data'] ?? []);
    echo "   Found {$signerCount} signers\n\n";

    echo "4. Checking webhook subscription...\n";
    $webhook = $client->webhooks()->get();
    if ($webhook) {
        echo "   Webhook registered: {$webhook['url']}\n";
        echo "   Status: " . ($webhook['is_active'] ? 'Active' : 'Inactive') . "\n";
    } else {
        echo "   No webhook registered\n";
    }
    echo "\n";

    echo "All tests passed successfully!\n";
    echo "\nYou can now:\n";
    echo "- Upload documents: \$client->documents()->upload(...)\n";
    echo "- Create signers: \$client->signers()->create(...)\n";
    echo "- Request signatures: \$client->assignments()->create(...)\n";
    echo "- Or use the convenience method: \$client->uploadAndRequestSignatures(...)\n";

} catch (ValidationException $e) {
    echo "Validation Error: {$e->getMessage()}\n";
    echo "Errors:\n";
    print_r($e->getErrors());
} catch (ApiException $e) {
    echo "API Error (HTTP {$e->getStatusCode()}): {$e->getMessage()}\n";
    
    if ($e->getStatusCode() === 401) {
        echo "\nTroubleshooting:\n";
        echo "- Verify your API key is correct\n";
        echo "- Check if your account is active in Assinafy dashboard\n";
    } elseif ($e->getStatusCode() === 403) {
        echo "\nTroubleshooting:\n";
        echo "- Check if you have permission to access this resource\n";
        echo "- Verify your account ID is correct\n";
    }
} catch (\Exception $e) {
    echo "Error: {$e->getMessage()}\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString();
}

