<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

class DocumentResource extends AbstractResource
{
    public function upload(string $filePath, string $fileName, array $metadata = []): array
    {
        $this->validateUpload($filePath);

        $this->logger->info("Uploading document to Assinafy", [
            'file_name' => $fileName,
            'file_size' => filesize($filePath),
        ]);

        $response = $this->httpClient->uploadFile(
            "accounts/{$this->config->getAccountId()}/documents",
            $filePath,
            [
                'name' => $fileName,
                'metadata' => json_encode($metadata),
            ]
        );

        $data = $response->getData() ?? [];
        $documentData = $this->extractData($data);
        $documentData = $this->normalizeId($documentData);

        if (!isset($documentData['document_id'])) {
            throw new \RuntimeException('Upload succeeded but no document_id returned');
        }

        $this->logger->info("Document uploaded successfully", [
            'document_id' => $documentData['document_id'],
        ]);

        return $documentData;
    }

    public function get(string $documentId): array
    {
        $this->logger->debug("Fetching document details", ['document_id' => $documentId]);

        $response = $this->httpClient->get("documents/{$documentId}");

        return $this->extractData($response->getData() ?? []);
    }

    public function list(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $params = array_merge([
            'page' => $page,
            'per_page' => $perPage,
        ], $filters);

        $response = $this->httpClient->get(
            "accounts/{$this->config->getAccountId()}/documents",
            $params
        );

        return $response->getData() ?? [];
    }

    public function waitUntilReady(string $documentId, int $maxWaitSeconds = 30, int $pollIntervalSeconds = 2): array
    {
        $this->logger->info("Waiting for document to be ready", [
            'document_id' => $documentId,
            'max_wait' => $maxWaitSeconds,
        ]);

        $startTime = time();
        $attempts = 0;

        while ((time() - $startTime) < $maxWaitSeconds) {
            $attempts++;

            try {
                $details = $this->get($documentId);
                $status = $details['status'] ?? 'unknown';

                $this->logger->debug("Document status check", [
                    'attempt' => $attempts,
                    'status' => $status,
                ]);

                if (in_array($status, ['prepared', 'metadata_ready', 'document_prepared'])) {
                    $this->logger->info("Document is ready", [
                        'document_id' => $documentId,
                        'attempts' => $attempts,
                    ]);
                    return $details;
                }

                if (in_array($status, ['failed', 'error', 'processing_failed'])) {
                    throw new \RuntimeException("Document processing failed with status: {$status}");
                }

                if (in_array($status, ['uploaded', 'processing'])) {
                    sleep($pollIntervalSeconds);
                    continue;
                }

                sleep($pollIntervalSeconds);
            } catch (\Exception $e) {
                $this->logger->warning("Error checking document status", [
                    'exception' => $e->getMessage(),
                ]);
                sleep($pollIntervalSeconds);
            }
        }

        throw new \RuntimeException('Timeout waiting for document to be ready');
    }

    public function download(string $documentId): string
    {
        $response = $this->httpClient->get(
            "accounts/{$this->config->getAccountId()}/documents/{$documentId}/download"
        );

        return $response->getBody();
    }

    public function isFullySigned(string $documentId): bool
    {
        $document = $this->get($documentId);

        return ($document['status'] ?? '') === 'signed' || ($document['all_signed'] ?? false) === true;
    }

    public function getSigningProgress(string $documentId): array
    {
        $document = $this->get($documentId);
        $signers = $document['signers'] ?? [];

        $total = count($signers);
        $signed = 0;

        foreach ($signers as $signer) {
            if (($signer['status'] ?? '') === 'signed') {
                $signed++;
            }
        }

        $percentage = $total > 0 ? round(($signed / $total) * 100, 2) : 0;

        return [
            'signed' => $signed,
            'total' => $total,
            'percentage' => $percentage,
            'pending' => $total - $signed,
        ];
    }

    public function createFromTemplate(
        string $templateId,
        array $signers,
        array $options = []
    ): array {
        $this->logger->info("Creating document from template", [
            'template_id' => $templateId,
            'signers_count' => count($signers),
        ]);

        $payload = array_merge(['signers' => $signers], $options);

        $response = $this->httpClient->post(
            "accounts/{$this->config->getAccountId()}/templates/{$templateId}/documents",
            $payload
        );

        return $response->getData() ?? [];
    }

    public function estimateCostFromTemplate(
        string $templateId,
        array $signers
    ): array {
        $this->logger->debug("Estimating cost for document from template", [
            'template_id' => $templateId,
            'signers_count' => count($signers),
        ]);

        $response = $this->httpClient->post(
            "accounts/{$this->config->getAccountId()}/templates/{$templateId}/documents/estimate-cost",
            ['signers' => $signers]
        );

        return $response->getData() ?? [];
    }

    public function verify(string $hash): array
    {
        $this->logger->debug("Verifying document by hash", ['hash' => $hash]);

        $response = $this->httpClient->get("documents/{$hash}/verify");

        return $response->getData() ?? [];
    }

    private function validateUpload(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new ValidationException("File not found", ['file_path' => $filePath]);
        }

        $extension = strtolower(substr($filePath, -4));
        if ($extension !== '.pdf') {
            throw new ValidationException("Only PDF files are supported", ['file_path' => $filePath]);
        }

        $fileSize = filesize($filePath);
        $maxSize = 50 * 1024 * 1024;

        if ($fileSize > $maxSize) {
            throw new ValidationException("File size exceeds maximum allowed (50MB)", [
                'file_size' => $fileSize,
                'max_size' => $maxSize,
            ]);
        }
    }
}
