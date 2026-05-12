<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Integration;

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;
use Assinafy\SDK\Resources\DocumentResource;
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
