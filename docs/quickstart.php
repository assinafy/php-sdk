<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\NetworkException;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\AccountResource;
use Assinafy\SDK\Resources\UserResource;

/**
 * Read a required secret without embedding a fallback value in this example.
 */
function requiredEnvironmentValue(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException("Missing required environment variable: {$name}");
    }

    return $value;
}

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    echo "Run this read-only example from the command line.\n";
    exit(1);
}

echo "Assinafy PHP SDK sandbox quick start\n";
echo "This script performs read-only requests.\n\n";

try {
    $client = AssinafyClient::create(
        apiKey: requiredEnvironmentValue('ASSINAFY_API_KEY'),
        accountId: requiredEnvironmentValue('ASSINAFY_ACCOUNT_ID'),
        baseUrl: Configuration::SANDBOX_BASE_URL,
    );

    $account = $client->accounts()->get();
    echo isset($account['id'])
        ? "Workspace authentication succeeded.\n"
        : "Workspace response did not contain an ID.\n";

    $user = $client->users()->get();
    echo isset($user['id'])
        ? "Authenticated-user profile loaded.\n"
        : "User response did not contain an ID.\n";

    $documents = $client->documents()->list(page: 1, perPage: 5);
    echo sprintf(
        "Documents on first page: %d; total: %d.\n",
        count($documents['data'] ?? []),
        (int) ($documents['pagination']['total_count'] ?? 0),
    );

    $searchTerm = getenv('ASSINAFY_TEST_SEARCH_TERM');
    if (!is_string($searchTerm) || $searchTerm === '') {
        $searchTerm = 'sandbox';
    }

    $matches = $client->documents()->search($searchTerm, page: 1, perPage: 5);
    echo sprintf("Document search matches on first page: %d.\n", count($matches['data'] ?? []));

    $accountStats = $client->accounts()->stats(AccountResource::GRANULARITY_MONTHLY);
    $userStats = $client->users()->stats(UserResource::GRANULARITY_MONTHLY);
    echo sprintf(
        "Monthly statistic rows: account=%d, user=%d.\n",
        count($accountStats),
        count($userStats),
    );

    $templateId = getenv('ASSINAFY_TEST_TEMPLATE_ID');
    if (is_string($templateId) && $templateId !== '') {
        $client->templates()->waitUntilReady(
            templateId: $templateId,
            maxWaitSeconds: 30,
            pollIntervalSeconds: 2,
        );
        echo "Configured sandbox template is ready.\n";
    } else {
        echo "Template readiness check skipped; ASSINAFY_TEST_TEMPLATE_ID is not set.\n";
    }

    $signerId = getenv('ASSINAFY_TEST_SIGNER_ID');
    $signerAccessCode = getenv('ASSINAFY_TEST_SIGNER_ACCESS_CODE');
    if (
        is_string($signerId)
        && $signerId !== ''
        && is_string($signerAccessCode)
        && $signerAccessCode !== ''
    ) {
        $signerClient = AssinafyClient::forAuth(Configuration::SANDBOX_BASE_URL);
        $signerMatches = $signerClient->signerDocuments()->search(
            $signerId,
            $signerAccessCode,
            $searchTerm,
        );
        echo sprintf("Signer document search matches: %d.\n", count($signerMatches));
    } else {
        echo "Signer query-auth check skipped; signer test variables are not set.\n";
    }

    $publicClient = AssinafyClient::forAuth(Configuration::SANDBOX_BASE_URL);
    echo "OAuth start URL: " . $publicClient->auth()->socialLoginUrl() . "\n";
    echo "OAuth callback URL: " . $publicClient->auth()->socialLoginCallbackUrl() . "\n";

    echo "\nRead-only sandbox quick start completed.\n";
} catch (ValidationException $exception) {
    fwrite(STDERR, "Local validation failed: {$exception->getMessage()}\n");
    exit(1);
} catch (ApiException $exception) {
    fwrite(STDERR, "Assinafy API request failed with HTTP {$exception->getStatusCode()}.\n");
    exit(1);
} catch (NetworkException $exception) {
    fwrite(STDERR, "Could not reach the Assinafy sandbox.\n");
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, "Quick start failed: {$exception->getMessage()}\n");
    exit(1);
}
