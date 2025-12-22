<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

class AssignmentResource extends AbstractResource
{
    public function create(
        string $documentId,
        array $signers,
        string $method = 'virtual',
        ?string $message = null,
        ?string $expiresAt = null
    ): array {
        $this->validateSigners($signers);

        $signerIds = $this->extractSignerIds($signers);

        $payload = [
            'method' => $method,
            'signer_ids' => $signerIds,
        ];

        if ($message) {
            $payload['message'] = $message;
        }

        if ($expiresAt) {
            $payload['expires_at'] = $expiresAt;
        }

        $this->logger->info("Creating assignment for document", [
            'document_id' => $documentId,
            'signers_count' => count($signerIds),
        ]);

        $response = $this->httpClient->post(
            "documents/{$documentId}/assignments",
            $payload
        );

        $this->logger->info("Assignment created successfully", [
            'document_id' => $documentId,
        ]);

        return $response->getData() ?? [];
    }

    public function cancel(string $documentId, string $reason): array
    {
        $this->logger->info("Cancelling signature request", [
            'document_id' => $documentId,
            'reason' => $reason,
        ]);

        $response = $this->httpClient->post(
            "accounts/{$this->config->getAccountId()}/signature-requests/{$documentId}/cancel",
            [
                'document_id' => $documentId,
                'reason' => $reason,
            ]
        );

        return $response->getData() ?? [];
    }

    public function resendNotification(string $documentId, string $signerId): array
    {
        $this->logger->info("Resending notification", [
            'document_id' => $documentId,
            'signer_id' => $signerId,
        ]);

        $response = $this->httpClient->post(
            "accounts/{$this->config->getAccountId()}/signature-requests/resend",
            [
                'document_id' => $documentId,
                'signer_id' => $signerId,
            ]
        );

        return $response->getData() ?? [];
    }

    private function validateSigners(array $signers): void
    {
        if (empty($signers)) {
            throw new ValidationException("At least one signer is required", ['signers' => $signers]);
        }

        foreach ($signers as $signer) {
            if (!is_array($signer) && !is_string($signer)) {
                throw new ValidationException("Invalid signer format", ['signer' => $signer]);
            }
        }
    }

    private function extractSignerIds(array $signers): array
    {
        $signerIds = [];

        foreach ($signers as $signer) {
            if (is_string($signer)) {
                $signerIds[] = $signer;
            } elseif (is_array($signer)) {
                $id = $signer['signer_id'] ?? $signer['id'] ?? null;
                if ($id) {
                    $signerIds[] = $id;
                }
            }
        }

        return $signerIds;
    }
}
