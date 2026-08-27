<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Integration;

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Resources\AssignmentResource;
use Assinafy\SDK\Resources\DocumentResource;
use Assinafy\SDK\Resources\WebhookResource;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end integration tests against the Assinafy sandbox API.
 *
 * Enabled when ASSINAFY_INTEGRATION=1 in the environment. Requires:
 *   ASSINAFY_API_KEY    – API key for the target environment
 *   ASSINAFY_ACCOUNT_ID – workspace account id
 *   ASSINAFY_BASE_URL   – optional, defaults to Configuration::SANDBOX_BASE_URL
 *   ASSINAFY_NOTIFICATION_TESTS – set to 1 to enable notification flows
 *   ASSINAFY_TEST_EMAIL / ASSINAFY_TEST_EMAIL_ALT – controlled notification recipients
 *   ASSINAFY_STATEFUL_TESTS – set to 1 to modify and restore shared account settings
 *
 * These tests perform real network calls and may incur sandbox credit costs. They
 * refuse to target production unless ASSINAFY_ALLOW_PRODUCTION=1 is also set.
 */
final class LiveApiTest extends TestCase
{
    private AssinafyClient $client;
    /** @var array<int, string> document ids we created and need to clean up */
    private array $createdDocuments = [];
    /** @var array<int, string> signer ids we created and need to clean up */
    private array $createdSigners = [];
    /** @var array<int, string> temporary fixture paths we created */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        if (getenv('ASSINAFY_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set ASSINAFY_INTEGRATION=1 to run live API tests');
        }

        $apiKey = (string) getenv('ASSINAFY_API_KEY');
        $accountId = (string) getenv('ASSINAFY_ACCOUNT_ID');

        if ($apiKey === '' || $accountId === '') {
            $this->markTestSkipped('Set ASSINAFY_API_KEY and ASSINAFY_ACCOUNT_ID to run live API tests');
        }

        $baseUrl = (string) getenv('ASSINAFY_BASE_URL');
        if ($baseUrl === '') {
            $baseUrl = Configuration::SANDBOX_BASE_URL;
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            self::fail('Refusing live API tests over anything except HTTPS.');
        }
        if ($host !== 'sandbox.assinafy.com.br' && getenv('ASSINAFY_ALLOW_PRODUCTION') !== '1') {
            self::fail(
                'Refusing to run mutating integration tests outside sandbox.assinafy.com.br. '
                . 'Set ASSINAFY_ALLOW_PRODUCTION=1 only for an intentional production run.'
            );
        }

        $this->client = AssinafyClient::create($apiKey, $accountId, $baseUrl);

        // The shared sandbox enforces a short rolling request limit. Pacing tests
        // avoids turning a correct endpoint assertion into a rate-limit failure.
        sleep(1);
    }

    protected function tearDown(): void
    {
        $cleanupErrors = [];

        foreach ($this->createdDocuments as $id) {
            try {
                $this->deleteDocumentForCleanup($id);
            } catch (\Throwable $e) {
                $cleanupErrors[] = "document {$id}: {$e->getMessage()}";
            }
        }
        foreach ($this->createdSigners as $id) {
            try {
                $this->retryRateLimited(fn () => $this->client->signers()->delete($id));
            } catch (\Throwable $e) {
                $cleanupErrors[] = "signer {$id}: {$e->getMessage()}";
            }
        }
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path) && !unlink($path)) {
                $cleanupErrors[] = "temporary file {$path}";
            }
        }

        $this->createdDocuments = [];
        $this->createdSigners = [];
        $this->temporaryFiles = [];

        if ($cleanupErrors !== []) {
            self::fail('Sandbox cleanup failed: ' . implode('; ', $cleanupErrors));
        }
    }

    private function deleteDocumentForCleanup(string $documentId): void
    {
        $deadline = time() + 45;
        do {
            try {
                $this->client->documents()->delete($documentId);
                return;
            } catch (ApiException $e) {
                if ($e->getStatusCode() === 404) {
                    return;
                }
                if ($e->getStatusCode() === 429) {
                    $this->waitForRateLimit($e);
                    continue;
                }
                if ($e->getStatusCode() === 400 && time() < $deadline) {
                    sleep(2);
                    continue;
                }

                throw $e;
            }
        } while (time() < $deadline);

        throw new \RuntimeException('Document did not become deletable before cleanup timeout');
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function retryRateLimited(callable $operation): mixed
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $operation();
            } catch (ApiException $e) {
                if ($e->getStatusCode() !== 429 || $attempt === 2) {
                    throw $e;
                }
                $this->waitForRateLimit($e);
            }
        }

        throw new \LogicException('Unreachable rate-limit retry state');
    }

    private function waitForRateLimit(ApiException $exception): void
    {
        $retryAfter = (int) $exception->getResponseHeaderLine('Retry-After');
        if ($retryAfter <= 0 && preg_match('/(?:retry|wait).*?(\d+)\s*seconds?/i', $exception->getMessage(), $match)) {
            $retryAfter = (int) $match[1];
        }

        // The sandbox currently omits Retry-After on some 429 responses while
        // enforcing a roughly ten-second rolling window.
        sleep(max(1, min(15, $retryAfter > 0 ? $retryAfter : 10)));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function notificationEmails(): array
    {
        if (getenv('ASSINAFY_NOTIFICATION_TESTS') !== '1') {
            $this->markTestSkipped('Set ASSINAFY_NOTIFICATION_TESTS=1 to send sandbox test emails');
        }

        $email = (string) getenv('ASSINAFY_TEST_EMAIL');
        $alternateEmail = (string) getenv('ASSINAFY_TEST_EMAIL_ALT');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            self::fail('ASSINAFY_TEST_EMAIL must be a valid email address');
        }
        if ($alternateEmail === '') {
            $alternateEmail = $email;
        }
        if (filter_var($alternateEmail, FILTER_VALIDATE_EMAIL) === false) {
            self::fail('ASSINAFY_TEST_EMAIL_ALT must be a valid email address when set');
        }

        return [$email, $alternateEmail];
    }

    /**
     * @return array<string, mixed>
     */
    private function controlledSigner(string $email, string $label): array
    {
        $signer = $this->client->signers()->findByEmail($email);
        if ($signer === null) {
            $signer = $this->client->signers()->create($label . ' ' . uniqid(), $email);
            $signerId = $signer['id'] ?? null;
            if (!is_string($signerId) || trim($signerId) === '') {
                self::fail('Controlled signer creation returned no id');
            }
            $this->createdSigners[] = $signerId;
        }

        $signerId = $signer['id'] ?? null;
        if (!is_string($signerId) || trim($signerId) === '') {
            self::fail('Controlled signer lookup returned no id');
        }

        return $signer;
    }

    public function testStatusesEndpointReturnsKnownCodes(): void
    {
        $statuses = $this->client->documents()->statuses();
        $codes = array_column($statuses, 'code');

        $this->assertContains('uploaded', $codes);
        $this->assertContains('metadata_ready', $codes);
        $this->assertContains('certificated', $codes);
    }

    public function testSignerLifecycle(): void
    {
        $signers = $this->client->signers();

        $created = $signers->create(
            'SDK Test ' . uniqid(),
            'sdk-test+' . uniqid() . '@example.com'
        );

        $this->assertNotEmpty($created['id']);
        $this->createdSigners[] = (string) $created['id'];

        $fetched = $signers->get($created['id']);
        $this->assertSame($created['id'], $fetched['id']);

        $updated = $signers->update($created['id'], [
            'full_name' => 'SDK Updated',
            // The sandbox accepts punctuation and stores CPF/CNPJ digits only.
            'government_id' => '390.533.447-05',
        ]);
        $this->assertSame('SDK Updated', $updated['full_name']);

        $found = $signers->findByEmail((string) $created['email']);
        $this->assertNotNull($found);
        $this->assertSame($created['id'], $found['id']);

        $signers->delete($created['id']);
        $this->createdSigners = array_values(array_filter(
            $this->createdSigners,
            static fn (string $id): bool => $id !== $created['id']
        ));
    }

    public function testDocumentUploadGetActivitiesAndDelete(): void
    {
        $pdf = $this->makePdfFixture();

        $doc = $this->client->documents()->upload($pdf);
        $this->createdDocuments[] = $doc['id'];

        $this->assertNotEmpty($doc['id']);
        $this->assertContains($doc['status'], ['uploaded', 'uploading', 'metadata_processing', 'metadata_ready']);

        $ready = $this->client->documents()->waitUntilReady($doc['id'], 60, 2);
        $this->assertContains($ready['status'], DocumentResource::READY_STATUSES);

        $activities = $this->client->documents()->activities($doc['id']);
        $this->assertIsArray($activities);

        $publicInfo = $this->client->documents()->publicInfo($doc['id']);
        $this->assertSame($doc['id'], $publicInfo['id']);

        $original = $this->client->documents()->download($doc['id'], DocumentResource::ARTIFACT_ORIGINAL);
        $this->assertNotEmpty($original);
        $this->assertStringStartsWith('%PDF', $original);

        $this->client->documents()->delete($doc['id']);
        $this->createdDocuments = array_values(array_filter($this->createdDocuments, fn ($id) => $id !== $doc['id']));
    }

    public function testListDocumentsUsesHyphenPerPage(): void
    {
        $page = $this->client->documents()->list(1, 1);
        $this->assertArrayHasKey('data', $page);
    }

    public function testTemplatesList(): void
    {
        $page = $this->client->templates()->list(1, 5);
        $this->assertArrayHasKey('data', $page);
    }

    /** Full template management lifecycle: create → get → update → page download → delete. */
    public function testTemplateManagementLifecycle(): void
    {
        $templates = $this->client->templates();
        $pdf = $this->makePdfFixture();

        $created = $templates->create($pdf);
        $templateId = (string) ($created['id'] ?? '');
        $this->assertNotSame('', $templateId, 'template create must return an id');

        try {
            $this->assertSame('template', $created['resource'] ?? null);

            // The page render is asynchronous — poll get() until the template is Ready.
            $template = $created;
            for ($i = 0; $i < 30; $i++) {
                $template = $templates->get($templateId);
                if (strtolower((string) ($template['status'] ?? '')) === 'ready') {
                    break;
                }
                sleep(2);
            }
            $this->assertSame(
                'ready',
                strtolower((string) ($template['status'] ?? '')),
                'template never reached Ready'
            );
            $this->assertSame($templateId, $template['id'] ?? null);

            // update editable metadata
            $newName = 'SDK Renamed ' . uniqid();
            $updated = $templates->update($templateId, [
                'document_name' => $newName,
                'message' => 'SDK integration message',
            ]);
            $this->assertSame($newName, $updated['document_name'] ?? null);
            $this->assertSame('SDK integration message', $updated['message'] ?? null);

            // download the first rendered page
            $pages = $template['pages'] ?? [];
            $this->assertNotEmpty($pages, 'Ready template should expose at least one page');
            $pageImage = $templates->downloadPage($templateId, (string) $pages[0]['id']);
            $this->assertNotEmpty($pageImage, 'template page download returned empty body');
        } finally {
            $templates->delete($templateId);
        }

        $this->expectException(ApiException::class);
        $templates->get($templateId);
    }

    public function testWebhookSubscriptionRoundTrip(): void
    {
        $webhooks = $this->client->webhooks();
        $sub = $webhooks->get();
        $this->assertTrue(is_array($sub) || $sub === null);
    }

    /** Read-only artifact downloads after metadata_ready. */
    public function testDocumentThumbnailAndPageDownload(): void
    {
        $pdf = $this->makePdfFixture();
        $doc = $this->client->documents()->upload($pdf);
        $this->createdDocuments[] = $doc['id'];
        $ready = $this->client->documents()->waitUntilReady($doc['id'], 60, 2);

        $thumb = $this->client->documents()->downloadThumbnail($doc['id']);
        $this->assertNotEmpty($thumb, 'Thumbnail download returned empty body');

        $pages = $ready['pages'] ?? [];
        $this->assertNotEmpty($pages, 'metadata_ready document should expose at least one page');
        $pageId = $pages[0]['id'] ?? null;
        $this->assertIsString($pageId, 'page entry should carry an id');

        $pageImage = $this->client->documents()->downloadPage($doc['id'], $pageId);
        $this->assertNotEmpty($pageImage, 'Page download returned empty body');
    }

    /** verify() on a bogus hash should be reachable and refused with a 4xx. */
    public function testVerifyEndpointRejectsBogusHash(): void
    {
        $bogusHash = str_repeat('0', 40);

        try {
            $result = $this->client->documents()->verify($bogusHash);
            // Some implementations return a payload with is_valid=false instead of an error.
            $this->assertIsArray($result);
            $this->assertArrayHasKey('is_valid', $result);
            $this->assertFalse($result['is_valid']);
        } catch (ApiException $e) {
            $this->assertGreaterThanOrEqual(400, $e->getStatusCode());
            $this->assertLessThan(500, $e->getStatusCode());
        }
    }

    /** Exercise TemplateResource::get when the sandbox account contains a template. */
    public function testTemplatesGetWhenAvailable(): void
    {
        $page = $this->client->templates()->list(1, 1);
        $items = $page['data'] ?? [];

        if ($items === []) {
            $this->markTestSkipped('No templates in sandbox account — cannot exercise templates()->get');
        }

        $first = $items[0];
        $template = $this->client->templates()->get($first['id']);
        $this->assertSame($first['id'], $template['id'] ?? null);
        $this->assertArrayHasKey(
            'roles',
            $template,
            'Template detail response must expose `roles` — the SDK readme relies on it'
        );
    }

    /** estimateCostFromTemplate is read-only, but needs a real template with roles. */
    public function testEstimateCostFromTemplateWhenAvailable(): void
    {
        $page = $this->client->templates()->list(1, 1, ['status' => 'ready']);
        $items = $page['data'] ?? [];

        if ($items === []) {
            $this->markTestSkipped('No ready templates in sandbox — cannot estimate cost from template');
        }

        $template = $this->client->templates()->get($items[0]['id']);
        $roleIds = array_column($template['roles'] ?? [], 'id');
        if ($roleIds === []) {
            $this->markTestSkipped('Template has no roles — cannot build signer/role mapping');
        }

        $signerEntries = [];
        foreach ($roleIds as $roleId) {
            $signer = $this->client->signers()->create(
                'SDK estimateCost ' . uniqid(),
                'sdk-integration+' . uniqid() . '@example.com'
            );
            $this->createdSigners[] = $signer['id'];
            $signerEntries[] = ['role_id' => $roleId, 'id' => $signer['id']];
        }

        $estimate = $this->client->documents()->estimateCostFromTemplate($template['id'], $signerEntries);
        $this->assertIsArray($estimate);
    }

    /** Full assignment lifecycle (estimateCost → create → estimateResendCost → resend → resetExpiration). */
    public function testAssignmentFullLifecycle(): void
    {
        [$email] = $this->notificationEmails();
        $pdf = $this->makePdfFixture();
        $doc = $this->client->documents()->upload($pdf);
        $this->createdDocuments[] = $doc['id'];
        $this->client->documents()->waitUntilReady($doc['id'], 60, 2);

        $signer = $this->controlledSigner($email, 'SDK assignment');

        $signerEntries = [[
            'id' => $signer['id'],
            'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
        ]];

        // 1. Estimate cost without changing remote state.
        $estimate = $this->client->assignments()->estimateCost(
            $doc['id'],
            $signerEntries,
            AssignmentResource::METHOD_VIRTUAL
        );
        $this->assertIsArray($estimate);

        // 2. Create the assignment and notify the controlled recipient.
        $assignment = $this->client->assignments()->create(
            $doc['id'],
            $signerEntries,
            AssignmentResource::METHOD_VIRTUAL,
            [
                'message' => 'SDK integration test — no action required',
                'expires_at' => '2099-12-31T23:59:00Z',
            ]
        );
        $this->assertArrayHasKey('id', $assignment);
        $assignmentId = (string) $assignment['id'];

        // 3. Estimate the resend cost for the existing assignment.
        $resendEstimate = $this->client->assignments()->estimateResendCost(
            $doc['id'],
            $assignmentId,
            $signer['id']
        );
        $this->assertIsArray($resendEstimate);

        // 4. Resend to the controlled recipient.
        $resend = $this->client->assignments()->resend($doc['id'], $assignmentId, $signer['id']);
        $this->assertIsArray($resend);

        // 5. Extend the assignment deadline.
        $reset = $this->client->assignments()->resetExpiration(
            $doc['id'],
            $assignmentId,
            '2100-01-31T23:59:00Z'
        );
        $this->assertIsArray($reset);
    }

    /** Exercises the high-level workflow and sends assignment/access-token emails. */
    public function testNotificationFlowForAssignedSigners(): void
    {
        [$email, $alternateEmail] = $this->notificationEmails();
        $emails = [$email];
        if (strcasecmp($alternateEmail, $email) !== 0) {
            $emails[] = $alternateEmail;
        }

        $assignmentSigners = [];
        foreach ($emails as $recipient) {
            $signer = $this->controlledSigner($recipient, 'SDK notification test');
            $assignmentSigners[] = [
                'id' => (string) $signer['id'],
                'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
                'notification_methods' => [AssignmentResource::NOTIFICATION_EMAIL],
            ];
        }

        $pdf = $this->makePdfFixture();
        try {
            $result = $this->client->uploadAndRequestSignatures(
                $pdf,
                $assignmentSigners,
                'Assinafy PHP SDK sandbox integration test'
            );
        } catch (\Throwable $e) {
            try {
                $this->trackDocumentByNameForCleanup(basename($pdf));
            } catch (\Throwable $cleanupError) {
                throw new \RuntimeException(
                    'Workflow failed and its document could not be located for cleanup: '
                    . $cleanupError->getMessage(),
                    0,
                    $e
                );
            }
            throw $e;
        }
        $documentId = (string) $result['document']['id'];
        $this->createdDocuments[] = $documentId;
        $this->assertSame(array_column($assignmentSigners, 'id'), $result['signer_ids']);
        $this->assertNotEmpty($result['assignment']['signing_urls'] ?? []);

        $publicClient = AssinafyClient::forAuth($this->client->getConfig()->getBaseUrl());
        $tokenResult = $publicClient->documents()->sendToken($documentId, $alternateEmail);
        $this->assertIsArray($tokenResult);
    }

    /**
     * Exercises signer-authenticated reads with a one-time code retrieved from the
     * notification inbox. Assignment signing URLs do not expose this credential.
     */
    public function testSignerFacingReadFlowWithProvidedAccessCode(): void
    {
        $signerId = (string) getenv('ASSINAFY_SIGNER_ID');
        $accessCode = (string) getenv('ASSINAFY_SIGNER_ACCESS_CODE');
        if ($signerId === '' || $accessCode === '') {
            $this->markTestSkipped(
                'Set ASSINAFY_SIGNER_ID and ASSINAFY_SIGNER_ACCESS_CODE from a current sandbox email'
            );
        }

        $publicClient = AssinafyClient::forAuth($this->client->getConfig()->getBaseUrl());

        $current = $publicClient->signerDocuments()->current($signerId, $accessCode);
        $documentId = $current['id'] ?? null;
        $this->assertIsString($documentId);
        $this->assertNotSame('', $documentId);

        $documents = $publicClient->signerDocuments()->list($signerId, $accessCode, [
            'page' => 1,
            'per-page' => 10,
        ]);
        $this->assertArrayHasKey('data', $documents);

        $matches = $publicClient->signerDocuments()->search($signerId, $accessCode, 'SDK');
        $this->assertIsArray($matches);

        $profile = $publicClient->signerSession()->self($accessCode);
        $this->assertSame($signerId, $profile['id'] ?? null);

        $sessionDocument = $publicClient->signerSession()->currentDocument($accessCode);
        $this->assertSame($documentId, $sessionDocument['id'] ?? null);

        $download = $publicClient->signerDocuments()->download(
            $signerId,
            $documentId,
            $accessCode,
            DocumentResource::ARTIFACT_ORIGINAL
        );
        $this->assertStringStartsWith('%PDF', $download);
    }

    /** createFromTemplate, skipped unless the sandbox has a ready template. */
    public function testCreateFromTemplateWhenAvailable(): void
    {
        [$email, $alternateEmail] = $this->notificationEmails();
        $page = $this->client->templates()->list(1, 1, ['status' => 'ready']);
        $items = $page['data'] ?? [];

        if ($items === []) {
            $this->markTestSkipped('No ready templates in sandbox — cannot exercise createFromTemplate');
        }

        $template = $this->client->templates()->get($items[0]['id']);
        $roleIds = array_column($template['roles'] ?? [], 'id');
        if ($roleIds === []) {
            $this->markTestSkipped('Template has no roles — cannot bind signers');
        }

        $emails = strcasecmp($email, $alternateEmail) === 0
            ? [$email]
            : [$email, $alternateEmail];
        if (count($roleIds) > count($emails)) {
            $this->markTestSkipped('Template needs more controlled signer emails than configured');
        }

        $signerEntries = [];
        foreach ($roleIds as $index => $roleId) {
            $signer = $this->controlledSigner($emails[$index], 'SDK createFromTemplate');
            $signerEntries[] = [
                'role_id' => $roleId,
                'id' => $signer['id'],
                'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
                'notification_methods' => [AssignmentResource::NOTIFICATION_EMAIL],
            ];
        }

        $newDoc = $this->client->documents()->createFromTemplate(
            $template['id'],
            $signerEntries,
            [
                'name' => 'SDK integration ' . uniqid(),
                'expires_at' => '2099-12-31T23:59:00Z',
            ]
        );
        $this->assertArrayHasKey('id', $newDoc);
        $this->createdDocuments[] = $newDoc['id'];
        $ready = $this->client->documents()->waitUntilReady((string) $newDoc['id'], 60, 2);
        $this->assertContains($ready['status'] ?? null, DocumentResource::READY_STATUSES);
    }

    /** Webhook register / get / deactivate / activate round-trip. */
    public function testWebhookFullRoundTrip(): void
    {
        if (getenv('ASSINAFY_STATEFUL_TESTS') !== '1') {
            $this->markTestSkipped('Set ASSINAFY_STATEFUL_TESTS=1 to modify shared webhook settings');
        }

        $webhooks = $this->client->webhooks();
        $existing = $webhooks->get();
        if (
            !is_array($existing)
            || !is_string($existing['url'] ?? null)
            || filter_var($existing['url'], FILTER_VALIDATE_URL) === false
            || filter_var($existing['email'] ?? null, FILTER_VALIDATE_EMAIL) === false
            || !is_array($existing['events'] ?? null)
            || $existing['events'] === []
            || !array_key_exists('is_active', $existing)
        ) {
            $this->markTestSkipped('No complete webhook configuration is available for exact restoration');
        }

        $existingUrl = $existing['url'];
        $existingEmail = (string) $existing['email'];
        $existingEvents = $existing['events'];
        $existingActive = (bool) $existing['is_active'];

        $testUrl = 'https://sdk-integration.invalid/webhooks/' . uniqid();

        try {
            // 1. register a new subscription
            $registered = $webhooks->register(
                $testUrl,
                $existingEmail,
                WebhookResource::DEFAULT_EVENTS
            );
            $this->assertSame($testUrl, $registered['url'] ?? null);
            $this->assertTrue($registered['is_active'] ?? null);

            $fetched = $webhooks->get();
            $this->assertNotNull($fetched);
            $this->assertSame($testUrl, $fetched['url'] ?? null);

            // 2. deactivate (the API has no DELETE — this is the unsubscribe path)
            $deactivated = $webhooks->deactivate();
            $this->assertFalse($deactivated['is_active'] ?? null);

            $afterDeactivate = $webhooks->get();
            $this->assertNotNull($afterDeactivate);
            $this->assertFalse($afterDeactivate['is_active'] ?? null);
            $this->assertSame(
                $testUrl,
                $afterDeactivate['url'] ?? null,
                'deactivate must preserve URL (it is a soft toggle, not a destroy)'
            );

            // 3. activate again
            $reactivated = $webhooks->activate();
            $this->assertTrue($reactivated['is_active'] ?? null);
        } finally {
            // Restoration is part of the test contract. Let a failure fail the test so
            // a shared sandbox is never silently left on the test endpoint.
            $restored = $webhooks->register(
                $existingUrl,
                $existingEmail,
                $existingEvents,
                $existingActive
            );
            $this->assertSame($existingUrl, $restored['url'] ?? null);
            $this->assertSame($existingEmail, $restored['email'] ?? null);
            $this->assertSame($existingEvents, $restored['events'] ?? null);
            $this->assertSame($existingActive, $restored['is_active'] ?? null);
        }
    }

    /** Workspace tag CRUD with no credit cost. */
    public function testTagLifecycle(): void
    {
        $tags = $this->client->tags();
        $name = 'SDK Tag ' . uniqid();
        $tagId = null;
        $deleted = false;

        try {
            $created = $tags->create($name, 'ff8800');
            $this->assertNotEmpty($created['id']);
            $tagId = (string) $created['id'];
            $this->assertSame($name, $created['name']);

            $listed = $tags->list($name);
            $this->assertContains($created['id'], array_column($listed, 'id'));

            $renamed = $tags->update($created['id'], ['name' => $name . ' renamed']);
            $this->assertSame($name . ' renamed', $renamed['name']);

            $result = $tags->delete($created['id']);
            $this->assertTrue($result['deleted'] ?? false);
            $deleted = true;
        } finally {
            if ($tagId !== null && !$deleted) {
                $tags->delete($tagId, true);
            }
        }
    }

    /** Field-definition CRUD plus the global type catalog, with no credit cost. */
    public function testFieldLifecycleAndTypes(): void
    {
        $fields = $this->client->fields();
        $fieldId = null;
        $deleted = false;

        $types = $fields->types();
        $this->assertNotEmpty($types);
        $this->assertContains('text', array_column($types, 'type'));

        try {
            $created = $fields->create('text', 'SDK Field ' . uniqid());
            $this->assertNotEmpty($created['id']);
            $fieldId = (string) $created['id'];

            $fetched = $fields->get($created['id']);
            $this->assertSame($created['id'], $fetched['id']);

            $updated = $fields->update($created['id'], ['name' => 'SDK Field renamed']);
            $this->assertSame('SDK Field renamed', $updated['name']);

            $this->assertContains($created['id'], array_column($fields->list(), 'id'));

            $singleValidation = $fields->validate($created['id'], 'a valid text value');
            $this->assertIsArray($singleValidation);

            $multipleValidation = $fields->validateMultiple([[
                'field_id' => $created['id'],
                'value' => 'another valid text value',
            ]]);
            $this->assertCount(1, $multipleValidation);

            $fields->delete($created['id']);
            $deleted = true;
        } finally {
            if ($fieldId !== null && !$deleted) {
                $fields->delete($fieldId);
            }
        }
    }

    /** Read-only webhook discovery endpoints. */
    public function testWebhookEventTypesAndDispatches(): void
    {
        $eventTypes = $this->client->webhooks()->eventTypes();
        $this->assertContains('document_ready', array_column($eventTypes, 'id'));

        $dispatches = $this->client->webhooks()->dispatches(['per-page' => 1]);
        $this->assertArrayHasKey('data', $dispatches);
    }

    /** Document tag attach/list/replace/detach round-trip, with no credit cost. */
    public function testDocumentTagRoundTrip(): void
    {
        $pdf = $this->makePdfFixture();
        $doc = $this->client->documents()->upload($pdf);
        $this->createdDocuments[] = $doc['id'];
        $this->client->documents()->waitUntilReady($doc['id'], 60, 2);

        $documents = $this->client->documents();
        $tagName = 'SDK DocTag ' . uniqid();
        $replaceName = 'SDK DocTag2 ' . uniqid();

        try {
            $afterAppend = $documents->appendTags($doc['id'], [$tagName]);
            $this->assertContains($tagName, array_column($afterAppend, 'name'));

            $listed = $documents->listTags($doc['id']);
            $this->assertContains($tagName, array_column($listed, 'name'));

            $documents->replaceTags($doc['id'], [$replaceName]);
            $afterReplace = $documents->listTags($doc['id']);
            $this->assertSame([$replaceName], array_column($afterReplace, 'name'));

            $tagId = $afterReplace[0]['id'];
            $detached = $documents->detachTag($doc['id'], $tagId);
            $this->assertTrue($detached['detached'] ?? false);
        } finally {
            // Cleanup is mandatory: document tag operations auto-create workspace tags.
            foreach ([$tagName, $replaceName] as $name) {
                foreach ($this->client->tags()->list($name) as $tag) {
                    if (($tag['name'] ?? null) === $name) {
                        $this->client->tags()->delete((string) $tag['id'], true);
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------------------------
    // Accounts — added in 2.0.0; the whole tag was previously unimplemented.
    // ---------------------------------------------------------------------------------

    public function testAccountsListReturnsTheConfiguredAccount(): void
    {
        $result = $this->client->accounts()->list();

        $this->assertArrayHasKey('data', $result);
        $this->assertNotEmpty($result['data']);

        $ids = array_column($result['data'], 'id');
        $this->assertContains(
            $this->client->getConfig()->getAccountId(),
            $ids,
            'The configured account should appear in accounts()->list()'
        );
    }

    public function testAccountsGetReturnsTheWorkspaceProfile(): void
    {
        $account = $this->client->accounts()->get();

        $this->assertSame($this->client->getConfig()->getAccountId(), $account['id']);
        $this->assertArrayHasKey('name', $account);
    }

    public function testAccountsThemeReturnsBranding(): void
    {
        $theme = $this->client->accounts()->theme();

        $this->assertArrayHasKey('account_name', $theme);
        $this->assertArrayHasKey('primary_color', $theme);
    }

    public function testAccountStatisticsWhenDeployedToSandbox(): void
    {
        try {
            $accountMonthly = $this->client->accounts()->stats();
            $this->assertIsArray($accountMonthly);

            $accountDaily = $this->client->accounts()->stats('daily', gmdate('Y-m'));
            $this->assertIsArray($accountDaily);
        } catch (ApiException $e) {
            if ($e->getStatusCode() === 404) {
                $this->markTestSkipped('Documented account stats route is not deployed to sandbox');
            }
            throw $e;
        }
    }

    public function testUserProfile(): void
    {
        $user = $this->client->users()->get();
        $this->assertArrayHasKey('id', $user);
        $this->assertArrayHasKey('email', $user);
    }

    public function testUserNotificationPreferencesRoundTrip(): void
    {
        if (getenv('ASSINAFY_STATEFUL_TESTS') !== '1') {
            $this->markTestSkipped('Set ASSINAFY_STATEFUL_TESTS=1 to modify shared user preferences');
        }

        $users = $this->client->users();
        try {
            $preferences = $users->notificationPreferences();
        } catch (ApiException $e) {
            if ($e->getStatusCode() === 404) {
                $this->markTestSkipped(
                    'Documented notification-preferences route is not deployed to sandbox'
                );
            }
            throw $e;
        }

        $this->assertCount(9, $preferences);
        foreach ($preferences as $enabled) {
            $this->assertIsBool($enabled);
        }

        $code = 'SignerWhatsappFailed';
        $original = $preferences[$code];

        try {
            $updated = $users->updateNotificationPreferences([$code => !$original]);
            $this->assertSame(!$original, $updated[$code]);
        } finally {
            $restored = $users->updateNotificationPreferences([$code => $original]);
            $this->assertSame($original, $restored[$code]);
        }
    }

    public function testUserStatisticsWhenDeployedToSandbox(): void
    {
        try {
            $userMonthly = $this->client->users()->stats();
            $this->assertIsArray($userMonthly);

            $userDaily = $this->client->users()->stats('daily', gmdate('Y-m'));
            $this->assertIsArray($userDaily);
        } catch (ApiException $e) {
            if ($e->getStatusCode() === 404) {
                $this->markTestSkipped('Documented user stats route is not deployed to sandbox');
            }
            throw $e;
        }
    }

    /**
     * The route exists even with no logo uploaded — it answers with an app-level 404
     * ("Arquivo de armazenamento não encontrado"), not a routing miss.
     */
    public function testAccountsDownloadLogoEitherReturnsBytesOr404s(): void
    {
        try {
            $bytes = $this->client->accounts()->downloadLogo();
            $this->assertNotSame('', $bytes);
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    /**
     * Proves create/update/logo/delete on a disposable sandbox workspace. It never
     * deletes the configured workspace and requires an explicit destructive opt-in.
     */
    public function testDisposableAccountLifecycle(): void
    {
        if (getenv('ASSINAFY_DESTRUCTIVE_TESTS') !== '1') {
            $this->markTestSkipped('Set ASSINAFY_DESTRUCTIVE_TESTS=1 for disposable account deletion');
        }

        $created = $this->client->accounts()->create('SDK disposable ' . uniqid());
        $accountId = (string) ($created['id'] ?? '');
        $this->assertNotSame('', $accountId, 'Account creation must return an ID');

        $apiKey = (string) getenv('ASSINAFY_API_KEY');
        $baseUrl = (string) getenv('ASSINAFY_BASE_URL');
        if ($baseUrl === '') {
            $baseUrl = Configuration::SANDBOX_BASE_URL;
        }
        $disposable = null;
        $deleted = false;

        try {
            $disposable = AssinafyClient::create($apiKey, $accountId, $baseUrl);
            $fetched = $disposable->accounts()->get();
            $this->assertSame($accountId, $fetched['id'] ?? null);

            $newName = 'SDK disposable updated ' . uniqid();
            $updated = $disposable->accounts()->update($newName);
            $this->assertSame($newName, $updated['name'] ?? null);

            $theme = $disposable->accounts()->theme();
            $this->assertSame($newName, $theme['account_name'] ?? null);

            $logo = $disposable->accounts()->uploadLogo($this->makePngFixture());
            $this->assertIsArray($logo);

            $downloadedLogo = $disposable->accounts()->downloadLogo();
            $this->assertNotSame('', $downloadedLogo);

            $logoDeletion = $disposable->accounts()->deleteLogo();
            $this->assertIsArray($logoDeletion);

            $deletion = $disposable->accounts()->delete();
            $this->assertIsArray($deletion);
            $deleted = true;
        } finally {
            if (!$deleted) {
                $disposable ??= AssinafyClient::create($apiKey, $accountId, $baseUrl);
                $disposable->accounts()->delete(true);
            }
        }
    }

    // ---------------------------------------------------------------------------------
    // Pagination — the API reports it only in X-Pagination-* headers, never in the body.
    // ---------------------------------------------------------------------------------

    public function testDocumentListSurfacesPaginationFromResponseHeaders(): void
    {
        $result = $this->client->documents()->list(1, 2);

        $this->assertArrayNotHasKey('meta', $result, 'The API has never sent a `meta` key');
        $this->assertArrayHasKey('pagination', $result, 'Pagination must be lifted from the headers');

        $pagination = $result['pagination'];
        $this->assertSame(1, $pagination['current_page']);
        $this->assertSame(2, $pagination['per_page']);
        $this->assertGreaterThanOrEqual(0, $pagination['total_count']);
        $this->assertLessThanOrEqual(2, count($result['data']));
    }

    public function testDocumentSearchReturnsMatches(): void
    {
        $result = $this->client->documents()->search('', 1, 2);

        $this->assertArrayHasKey('data', $result);
        $this->assertLessThanOrEqual(2, count($result['data']));
    }

    // ---------------------------------------------------------------------------------
    // Rename (PATCH) — only legal before the signature process starts.
    // ---------------------------------------------------------------------------------

    public function testRenameWorksWhileTheDocumentIsStillEditable(): void
    {
        $doc = $this->client->documents()->upload($this->makePdfFixture());
        $this->createdDocuments[] = $doc['id'];

        $ready = $this->client->documents()->waitUntilReady($doc['id']);
        $this->assertContains($ready['status'], DocumentResource::READY_STATUSES);

        $renamed = $this->client->documents()->rename($doc['id'], 'renamed-by-test.pdf');

        $this->assertSame('renamed-by-test.pdf', $renamed['name']);
    }

    /**
     * The API normalises names server-side: diacritics are stripped. Asserting this keeps
     * us honest about the fact that the stored name is not the name we sent.
     */
    public function testRenameNormalisesDiacritics(): void
    {
        $doc = $this->client->documents()->upload($this->makePdfFixture());
        $this->createdDocuments[] = $doc['id'];
        $this->client->documents()->waitUntilReady($doc['id']);

        $renamed = $this->client->documents()->rename($doc['id'], 'acentuação áç.pdf');

        $this->assertStringNotContainsString('ç', $renamed['name']);
        $this->assertStringNotContainsString('á', $renamed['name']);
    }

    // ---------------------------------------------------------------------------------
    // Assignments
    // ---------------------------------------------------------------------------------

    /**
     * `GET /assignments` needs an `accountId` query param that the OpenAPI spec does not
     * document. Without it the API answers 400 "Um contexto de conta é necessário".
     */
    public function testAssignmentListSendsTheUndocumentedAccountIdParam(): void
    {
        $result = $this->client->assignments()->list(1, 2);

        $this->assertArrayHasKey('data', $result);
        $this->assertLessThanOrEqual(2, count($result['data']));
    }

    /**
     * Cost is priced off the verification/notification methods alone, so signer IDs are not
     * required. Before 2.0.0 this threw client-side and never reached the API.
     */
    public function testEstimateCostAcceptsSignersWithoutIds(): void
    {
        $doc = $this->client->documents()->upload($this->makePdfFixture());
        $this->createdDocuments[] = $doc['id'];
        $this->client->documents()->waitUntilReady($doc['id']);

        $estimate = $this->client->assignments()->estimateCost($doc['id'], [
            ['verification_method' => 'Email', 'notification_methods' => ['Email']],
        ]);

        $this->assertArrayHasKey('documents', $estimate);
        $this->assertArrayHasKey('has_sufficient_resources', $estimate);
    }

    // ---------------------------------------------------------------------------------
    // Signer session — guards the published signer-access-code query contract.
    // ---------------------------------------------------------------------------------

    /**
     * A bogus query code must produce 401 "invalid credentials" (the server found and
     * rejected it), rather than a route miss or missing-parameter response.
     */
    public function testSignerAccessCodeQueryParameterIsRecognized(): void
    {
        try {
            $this->client->signerSession()->self('BOGUS-ACCESS-CODE');
            $this->fail('A bogus access code should not authenticate');
        } catch (ApiException $e) {
            $this->assertSame(
                401,
                $e->getCode(),
                'Expected 401: signer-access-code was present but invalid.'
            );
        }
    }

    private function makePdfFixture(): string
    {
        // Minimal valid 1-page PDF.
        $pdf = "%PDF-1.4\n"
            . "1 0 obj <</Type /Catalog /Pages 2 0 R>> endobj\n"
            . "2 0 obj <</Type /Pages /Kids [3 0 R] /Count 1>> endobj\n"
            . "3 0 obj <</Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources <<>>>> endobj\n"
            . "xref\n0 4\n0000000000 65535 f \n0000000010 00000 n \n0000000061 00000 n \n0000000111 00000 n \n"
            . "trailer <</Size 4 /Root 1 0 R>>\nstartxref\n190\n%%EOF\n";

        $temporaryPath = tempnam(sys_get_temp_dir(), 'asn-sdk-');
        if ($temporaryPath === false) {
            self::fail('Could not create a temporary PDF fixture');
        }

        $path = $temporaryPath . '.pdf';
        if (!rename($temporaryPath, $path) || file_put_contents($path, $pdf) === false) {
            self::fail('Could not write a temporary PDF fixture');
        }
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function trackDocumentByNameForCleanup(string $name): void
    {
        $deadline = time() + 30;
        do {
            $matches = $this->retryRateLimited(
                fn () => $this->client->documents()->search($name, 1, 100)
            );
            foreach ($matches['data'] ?? [] as $document) {
                $id = $document['id'] ?? null;
                if (($document['name'] ?? null) === $name && is_string($id)) {
                    if (!in_array($id, $this->createdDocuments, true)) {
                        $this->createdDocuments[] = $id;
                    }
                    return;
                }
            }

            sleep(2);
        } while (time() < $deadline);

        throw new \RuntimeException('Uploaded document did not appear in sandbox search before cleanup timeout');
    }

    private function makePngFixture(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        if ($bytes === false) {
            self::fail('Could not decode the PNG fixture');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'asn-sdk-');
        if ($temporaryPath === false) {
            self::fail('Could not create a temporary PNG fixture');
        }

        $path = $temporaryPath . '.png';
        if (!rename($temporaryPath, $path) || file_put_contents($path, $bytes) === false) {
            self::fail('Could not write a temporary PNG fixture');
        }
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
