<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Signer Documents resource — the signer-facing document list/sign/decline/download
 * endpoints under `/signers/{signer_id}/...` and `/signers/documents/...`.
 *
 * Every call is authenticated by a `signer-access-code` (the code embedded in the
 * link a signer receives), not by the workspace API key — so these work on a
 * {@see \Assinafy\SDK\Configuration::forPublic()} client too.
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class SignerDocumentResource extends AbstractResource
{
    /**
     * Get the document tied to the signer's access code, without page content.
     * `GET /signers/{signer_id}/document?signer-access-code={code}`
     *
     * Useful right after the signer opens the link, to show which document is about
     * to be signed before asking them to verify their code. Does not require the
     * signer to have verified or confirmed their data yet.
     */
    public function current(string $signerId, string $accessCode): array
    {
        $response = $this->httpClient->get(
            "signers/{$signerId}/document",
            ['signer-access-code' => $accessCode]
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List the signer's documents.
     * `GET /signers/{signer_id}/documents?signer-access-code={code}`
     *
     * @param array<string, scalar> $filters optional `status`, `method`, `search`, `sort`
     * @return array<int, array<string, mixed>>
     */
    public function list(string $signerId, string $accessCode, array $filters = []): array
    {
        $params = array_merge(['signer-access-code' => $accessCode], $filters);

        $response = $this->httpClient->get("signers/{$signerId}/documents", $params);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Sign several virtual-method documents in one call.
     * `PUT /signers/documents/sign-multiple?signer-access-code={code}`
     *
     * @param array<int, string> $documentIds
     *
     * @throws ValidationException when no document IDs are provided
     */
    public function signMultiple(string $accessCode, array $documentIds): array
    {
        $this->assertDocumentIds($documentIds);

        $response = $this->httpClient->put(
            'signers/documents/sign-multiple',
            ['document_ids' => array_values($documentIds)],
            [],
            ['signer-access-code' => $accessCode]
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Decline several documents in one call.
     * `PUT /signers/documents/decline-multiple?signer-access-code={code}`
     *
     * @param array<int, string> $documentIds
     *
     * @throws ValidationException when no document IDs or no reason is provided
     */
    public function declineMultiple(string $accessCode, array $documentIds, string $reason): array
    {
        $this->assertDocumentIds($documentIds);

        if ($reason === '') {
            throw new ValidationException('A decline reason is required');
        }

        $response = $this->httpClient->put(
            'signers/documents/decline-multiple',
            ['document_ids' => array_values($documentIds), 'decline_reason' => $reason],
            [],
            ['signer-access-code' => $accessCode]
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Download one of the signer's document artifacts (raw binary body).
     * `GET /signers/{signer_id}/documents/{document_id}/download/{artifact_name}?signer-access-code={code}`
     *
     * @param string $artifact one of the {@see DocumentResource} `ARTIFACT_*` constants
     */
    public function download(
        string $signerId,
        string $documentId,
        string $accessCode,
        string $artifact = DocumentResource::ARTIFACT_ORIGINAL
    ): string {
        DocumentResource::assertArtifact($artifact);

        $response = $this->httpClient->get(
            "signers/{$signerId}/documents/{$documentId}/download/{$artifact}",
            ['signer-access-code' => $accessCode]
        );

        return $response->getBody();
    }

    /**
     * @param array<int, string> $documentIds
     */
    private function assertDocumentIds(array $documentIds): void
    {
        if ($documentIds === []) {
            throw new ValidationException('At least one document ID is required');
        }
    }
}
