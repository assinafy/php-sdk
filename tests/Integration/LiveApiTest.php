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
 * End-to-end integration tests against the live Assinafy API.
 *
 * Enabled when ASSINAFY_INTEGRATION=1 in the environment. Requires:
 *   ASSINAFY_API_KEY    – API key for the target environment
 *   ASSINAFY_ACCOUNT_ID – workspace account id
 *   ASSINAFY_BASE_URL   – optional, defaults to the production URL. Set to
 *                         Configuration::SANDBOX_BASE_URL (https://sandbox.assinafy.com.br/v1)
 *                         to exercise the sandbox instead.
 *
 * These tests perform real network calls and may incur credit costs.
 */
final class LiveApiTest extends TestCase
{
    private AssinafyClient $client;
    /** @var array<int, string> document ids we created and need to clean up */
    private array $createdDocuments = [];
    /** @var array<int, string> signer ids we created and need to clean up */
    private array $createdSigners = [];

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
            $baseUrl = Configuration::DEFAULT_BASE_URL;
        }

        $this->client = AssinafyClient::create($apiKey, $accountId, $baseUrl);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDocuments as $id) {
            try {
                $this->client->documents()->delete($id);
            } catch (\Throwable $e) {
                // best-effort cleanup
            }
        }
        foreach ($this->createdSigners as $id) {
            try {
                $this->client->signers()->delete($id);
            } catch (\Throwable $e) {
                // best-effort cleanup
            }
        }
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

        $fetched = $signers->get($created['id']);
        $this->assertSame($created['id'], $fetched['id']);

        $updated = $signers->update($created['id'], ['full_name' => 'SDK Updated']);
        $this->assertSame('SDK Updated', $updated['full_name']);

        $found = $signers->findByEmail((string) $created['email']);
        $this->assertNotNull($found);
        $this->assertSame($created['id'], $found['id']);

        $signers->delete($created['id']);
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

    public function testWebhookSubscriptionRoundTrip(): void
    {
        $webhooks = $this->client->webhooks();
        $sub = $webhooks->get();
        $this->assertTrue(is_array($sub) || $sub === null);
    }

    /** Tier 1 — read-only artifact downloads after metadata_ready. */
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

    /** Tier 1 — verify() on a bogus hash should be reachable and refused with a 4xx. */
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

    /** Tier 1 — TemplateResource::get (the endpoint flagged as undocumented in the audit). */
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

    /** Tier 1 — estimateCostFromTemplate is read-only, but needs a real template + roles. */
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

    /** Tier 1 + Tier 2 — full assignment lifecycle (estimateCost → create → estimateResendCost → resend → resetExpiration). */
    public function testAssignmentFullLifecycle(): void
    {
        $pdf = $this->makePdfFixture();
        $doc = $this->client->documents()->upload($pdf);
        $this->createdDocuments[] = $doc['id'];
        $this->client->documents()->waitUntilReady($doc['id'], 60, 2);

        $signer = $this->client->signers()->create(
            'SDK assignment ' . uniqid(),
            'sdk-integration+' . uniqid() . '@example.com'
        );
        $this->createdSigners[] = $signer['id'];

        $signerEntries = [[
            'id' => $signer['id'],
            'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
        ]];

        // 1. estimateCost (Tier 1, read-only)
        $estimate = $this->client->assignments()->estimateCost(
            $doc['id'],
            $signerEntries,
            AssignmentResource::METHOD_VIRTUAL
        );
        $this->assertIsArray($estimate);

        // 2. create (Tier 2 — real assignment, virtual method, signer email at example.com (RFC 2606, undeliverable))
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

        // 3. estimateResendCost (Tier 2 — needs an existing assignment)
        $resendEstimate = $this->client->assignments()->estimateResendCost(
            $doc['id'],
            $assignmentId,
            $signer['id']
        );
        $this->assertIsArray($resendEstimate);

        // 4. resend (Tier 2 — real notification, again to undeliverable example.com)
        $resend = $this->client->assignments()->resend($doc['id'], $assignmentId, $signer['id']);
        $this->assertIsArray($resend);

        // 5. resetExpiration (Tier 2 — extends the assignment deadline)
        $reset = $this->client->assignments()->resetExpiration(
            $doc['id'],
            $assignmentId,
            '2100-01-31T23:59:00Z'
        );
        $this->assertIsArray($reset);
    }

    /** Tier 2 — createFromTemplate. Skipped unless the sandbox has a ready template. */
    public function testCreateFromTemplateWhenAvailable(): void
    {
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

        $signerEntries = [];
        foreach ($roleIds as $roleId) {
            $signer = $this->client->signers()->create(
                'SDK createFromTemplate ' . uniqid(),
                'sdk-integration+' . uniqid() . '@example.com'
            );
            $this->createdSigners[] = $signer['id'];
            $signerEntries[] = ['role_id' => $roleId, 'id' => $signer['id']];
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
    }

    /** Tier 2 — webhook register / get / deactivate / activate round-trip. */
    public function testWebhookFullRoundTrip(): void
    {
        $webhooks = $this->client->webhooks();
        $existing = $webhooks->get();

        $hadConfig = is_array($existing) && !empty($existing['url']);
        $existingUrl = $hadConfig ? (string) $existing['url'] : '';
        $existingEmail = $hadConfig ? (string) ($existing['email'] ?? '') : '';
        $existingEvents = $hadConfig && !empty($existing['events']) ? $existing['events'] : WebhookResource::DEFAULT_EVENTS;
        $existingActive = $hadConfig ? (bool) ($existing['is_active'] ?? true) : true;

        $testUrl = 'https://example.com/webhooks/sdk-integration-' . uniqid();

        try {
            // 1. register a new subscription
            $registered = $webhooks->register(
                $testUrl,
                'sdk-integration@example.com',
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
            // Restore the prior subscription if there was one; otherwise leave delivery
            // disabled so we don't strand the account with an active bogus endpoint.
            // Best-effort either way.
            try {
                if ($hadConfig) {
                    $webhooks->register($existingUrl, $existingEmail, $existingEvents, $existingActive);
                } else {
                    $webhooks->deactivate();
                }
            } catch (\Throwable $e) {
                // best-effort — the test still reports the underlying failure
            }
        }
    }

    /** Tier 1 — workspace tag CRUD (no credit cost). */
    public function testTagLifecycle(): void
    {
        $tags = $this->client->tags();
        $name = 'SDK Tag ' . uniqid();

        $created = $tags->create($name, 'ff8800');
        $this->assertNotEmpty($created['id']);
        $this->assertSame($name, $created['name']);

        $listed = $tags->list($name);
        $this->assertContains($created['id'], array_column($listed, 'id'));

        $renamed = $tags->update($created['id'], ['name' => $name . ' renamed']);
        $this->assertSame($name . ' renamed', $renamed['name']);

        $deleted = $tags->delete($created['id']);
        $this->assertTrue($deleted['deleted'] ?? false);
    }

    /** Tier 1 — field-definition CRUD plus the global type catalog (no credit cost). */
    public function testFieldLifecycleAndTypes(): void
    {
        $fields = $this->client->fields();

        $types = $fields->types();
        $this->assertNotEmpty($types);
        $this->assertContains('text', array_column($types, 'type'));

        $created = $fields->create('text', 'SDK Field ' . uniqid());
        $this->assertNotEmpty($created['id']);

        $fetched = $fields->get($created['id']);
        $this->assertSame($created['id'], $fetched['id']);

        $updated = $fields->update($created['id'], ['name' => 'SDK Field renamed']);
        $this->assertSame('SDK Field renamed', $updated['name']);

        $this->assertContains($created['id'], array_column($fields->list(), 'id'));

        $fields->delete($created['id']);
    }

    /** Tier 1 — webhook discovery endpoints (read-only). */
    public function testWebhookEventTypesAndDispatches(): void
    {
        $eventTypes = $this->client->webhooks()->eventTypes();
        $this->assertContains('document_ready', array_column($eventTypes, 'id'));

        $dispatches = $this->client->webhooks()->dispatches(['per-page' => 1]);
        $this->assertArrayHasKey('data', $dispatches);
    }

    /** Tier 1 — document tag attach/list/replace/detach round-trip (no credit cost). */
    public function testDocumentTagRoundTrip(): void
    {
        $pdf = $this->makePdfFixture();
        $doc = $this->client->documents()->upload($pdf);
        $this->createdDocuments[] = $doc['id'];
        $this->client->documents()->waitUntilReady($doc['id'], 60, 2);

        $documents = $this->client->documents();
        $tagName = 'SDK DocTag ' . uniqid();

        $afterAppend = $documents->appendTags($doc['id'], [$tagName]);
        $this->assertContains($tagName, array_column($afterAppend, 'name'));

        $listed = $documents->listTags($doc['id']);
        $this->assertContains($tagName, array_column($listed, 'name'));

        $replaceName = 'SDK DocTag2 ' . uniqid();
        $documents->replaceTags($doc['id'], [$replaceName]);
        $afterReplace = $documents->listTags($doc['id']);
        $this->assertSame([$replaceName], array_column($afterReplace, 'name'));

        $tagId = $afterReplace[0]['id'];
        $detached = $documents->detachTag($doc['id'], $tagId);
        $this->assertTrue($detached['detached'] ?? false);

        // Clean up the workspace tags the document operations auto-created.
        foreach ([$tagName, $replaceName] as $name) {
            foreach ($this->client->tags()->list($name) as $tag) {
                if ($tag['name'] === $name) {
                    try {
                        $this->client->tags()->delete($tag['id'], true);
                    } catch (\Throwable $e) {
                        // best-effort
                    }
                }
            }
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

        $path = tempnam(sys_get_temp_dir(), 'asn-sdk-') . '.pdf';
        file_put_contents($path, $pdf);

        return $path;
    }
}
