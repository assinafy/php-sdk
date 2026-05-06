<?php

declare(strict_types=1);

namespace Assinafy\SDK;

use Assinafy\SDK\Http\GuzzleHttpClient;
use Assinafy\SDK\Http\HttpClientInterface;
use Assinafy\SDK\Resources\AssignmentResource;
use Assinafy\SDK\Resources\DocumentResource;
use Assinafy\SDK\Resources\SignerResource;
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
        string $baseUrl = 'https://api.assinafy.com.br/v1',
        ?string $webhookSecret = null
    ): self {
        $config = new Configuration($apiKey, $accountId, $baseUrl, $webhookSecret);
        return new self($config);
    }

    public static function fromArray(array $config): self
    {
        return new self(Configuration::fromArray($config));
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

    public function webhookVerifier(): WebhookVerifier
    {
        if ($this->webhookVerifier === null) {
            $this->webhookVerifier = new WebhookVerifier($this->config);
        }

        return $this->webhookVerifier;
    }

    public function uploadAndRequestSignatures(
        string $filePath,
        string $fileName,
        array $signers,
        string $message = '',
        array $metadata = [],
        bool $waitForReady = true
    ): array {
        $this->logger->info("Starting document upload and signature request workflow", [
            'file_name' => $fileName,
            'signers_count' => count($signers),
        ]);

        $document = $this->documents()->upload($filePath, $fileName, $metadata);
        $documentId = $document['document_id'];

        if ($waitForReady) {
            $this->documents()->waitUntilReady($documentId);
        }

        $signerIds = [];
        foreach ($signers as $signer) {
            $result = $this->signers()->create(
                $signer['name'],
                $signer['email'],
                $signer['cpf'] ?? null,
                $signer['phone'] ?? null,
                $signer['metadata'] ?? []
            );

            $signerIds[] = $result['data']['id'];
        }

        $assignment = $this->assignments()->create(
            $documentId,
            $signerIds,
            'virtual',
            $message
        );

        $this->logger->info("Document upload and signature request completed successfully", [
            'document_id' => $documentId,
        ]);

        return [
            'document' => $document,
            'assignment' => $assignment,
            'signer_ids' => $signerIds,
        ];
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
