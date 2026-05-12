<?php

declare(strict_types=1);

namespace Assinafy\SDK;

use Assinafy\SDK\Http\GuzzleHttpClient;
use Assinafy\SDK\Http\HttpClientInterface;
use Assinafy\SDK\Resources\AssignmentResource;
use Assinafy\SDK\Resources\AuthResource;
use Assinafy\SDK\Resources\DocumentResource;
use Assinafy\SDK\Resources\SignerResource;
use Assinafy\SDK\Resources\SignerSessionResource;
use Assinafy\SDK\Resources\TemplateResource;
use Assinafy\SDK\Resources\WebhookResource;
use Assinafy\SDK\Support\WebhookVerifier;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AssinafyClient
{
    private Configuration $config;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    private ?DocumentResource $documents = null;
    private ?SignerResource $signers = null;
    private ?AssignmentResource $assignments = null;
    private ?TemplateResource $templates = null;
    private ?WebhookResource $webhooks = null;
    private ?AuthResource $auth = null;
    private ?SignerSessionResource $signerSession = null;
    private ?WebhookVerifier $webhookVerifier = null;

    public function __construct(
        Configuration $config,
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->httpClient = $httpClient ?? new GuzzleHttpClient($config, $this->logger);
    }

    public static function create(
        string $apiKey,
        string $accountId,
        string $baseUrl = Configuration::DEFAULT_BASE_URL,
        ?string $webhookSecret = null
    ): self {
        $config = new Configuration($apiKey, $accountId, $baseUrl, $webhookSecret);
        return new self($config);
    }

    public static function fromArray(array $config): self
    {
        return new self(Configuration::fromArray($config));
    }

    /**
     * Build a client for the unauthenticated surface of the API — the place where
     * you don't yet have an API key.
     *
     * Lets you call `$client->auth()->login(...)`, `requestPasswordReset(...)`,
     * `resetPassword(...)`, `socialLogin(...)`, and the public document endpoints
     * (`verify`, `publicInfo`, `sendToken`) without having to fabricate credentials
     * just to satisfy the Configuration constructor.
     *
     * Calling an account-scoped resource on a public client (e.g. `$client->signers()->list()`)
     * raises a `\RuntimeException` with a clear message — see {@see Configuration::forPublic()}.
     */
    public static function forAuth(string $baseUrl = Configuration::DEFAULT_BASE_URL): self
    {
        return new self(Configuration::forPublic($baseUrl));
    }

    public function documents(): DocumentResource
    {
        if ($this->documents === null) {
            $this->documents = new DocumentResource($this->httpClient, $this->config, $this->logger);
        }

        return $this->documents;
    }

    public function signers(): SignerResource
    {
        if ($this->signers === null) {
            $this->signers = new SignerResource($this->httpClient, $this->config, $this->logger);
        }

        return $this->signers;
    }

    public function assignments(): AssignmentResource
    {
        if ($this->assignments === null) {
            $this->assignments = new AssignmentResource($this->httpClient, $this->config, $this->logger);
        }

        return $this->assignments;
    }

    public function templates(): TemplateResource
    {
        if ($this->templates === null) {
            $this->templates = new TemplateResource($this->httpClient, $this->config, $this->logger);
        }

        return $this->templates;
    }

    public function webhooks(): WebhookResource
    {
        if ($this->webhooks === null) {
            $this->webhooks = new WebhookResource($this->httpClient, $this->config, $this->logger);
        }

        return $this->webhooks;
    }

    public function auth(): AuthResource
    {
        if ($this->auth === null) {
            $this->auth = new AuthResource($this->httpClient, $this->config, $this->logger);
        }

        return $this->auth;
    }

    public function signerSession(): SignerSessionResource
    {
        if ($this->signerSession === null) {
            $this->signerSession = new SignerSessionResource($this->httpClient, $this->config, $this->logger);
        }

        return $this->signerSession;
    }

    public function webhookVerifier(): WebhookVerifier
    {
        if ($this->webhookVerifier === null) {
            $this->webhookVerifier = new WebhookVerifier($this->config);
        }

        return $this->webhookVerifier;
    }

    /**
     * High-level helper: upload a PDF, create signers if needed, then dispatch a virtual
     * assignment to all of them.
     *
     * Each entry in `$signers` may be either:
     *   - an existing signer ID (string), or
     *   - an associative array `{ full_name (or name), email?, whatsapp_phone_number? (or phone)?,
     *     verification_method?, notification_methods? }`
     *
     * Signers without an `id` are created via the API; signers found by email (when an email
     * is supplied) are reused. Returns the created document, the assignment, and the resolved
     * signer IDs.
     *
     * @param array<int, string|array<string, mixed>> $signers
     * @return array{document: array<string, mixed>, assignment: array<string, mixed>, signer_ids: array<int, string>}
     */
    public function uploadAndRequestSignatures(
        string $filePath,
        array $signers,
        ?string $message = null,
        ?string $expiresAt = null,
        bool $waitForReady = true
    ): array {
        $this->logger->info('Upload + signature workflow starting', [
            'file' => $filePath,
            'signers_count' => count($signers),
        ]);

        $document = $this->documents()->upload($filePath);
        $documentId = $document['id'] ?? null;

        if (!is_string($documentId) || $documentId === '') {
            throw new \RuntimeException('Upload succeeded but no document id returned');
        }

        if ($waitForReady) {
            $this->documents()->waitUntilReady($documentId);
        }

        $signerIds = [];
        foreach ($signers as $signer) {
            $signerIds[] = $this->resolveSignerId($signer);
        }

        $options = [];
        if ($message !== null) {
            $options['message'] = $message;
        }
        if ($expiresAt !== null) {
            $options['expires_at'] = $expiresAt;
        }

        $assignment = $this->assignments()->create(
            $documentId,
            $signerIds,
            \Assinafy\SDK\Resources\AssignmentResource::METHOD_VIRTUAL,
            $options
        );

        return [
            'document' => $document,
            'assignment' => $assignment,
            'signer_ids' => $signerIds,
        ];
    }

    /**
     * Resolve a signer description to an ID, creating/finding the signer as needed.
     *
     * @param string|array<string, mixed> $signer
     */
    private function resolveSignerId($signer): string
    {
        if (is_string($signer)) {
            return $signer;
        }

        if (isset($signer['id']) && is_string($signer['id']) && $signer['id'] !== '') {
            return $signer['id'];
        }

        $fullName = (string) ($signer['full_name'] ?? $signer['name'] ?? '');
        $email = $signer['email'] ?? null;
        $phone = $signer['whatsapp_phone_number'] ?? $signer['phone'] ?? null;

        if ($email !== null) {
            $existing = $this->signers()->findByEmail((string) $email);
            if ($existing !== null && isset($existing['id'])) {
                return (string) $existing['id'];
            }
        }

        $created = $this->signers()->create(
            $fullName,
            $email !== null ? (string) $email : null,
            $phone !== null ? (string) $phone : null
        );

        if (!isset($created['id']) || !is_string($created['id'])) {
            throw new \RuntimeException('Signer creation returned no id');
        }

        return $created['id'];
    }

    public function getConfig(): Configuration
    {
        return $this->config;
    }

    public function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }
}
