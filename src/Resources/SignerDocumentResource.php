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
     *
     * @return array<string, mixed>
     */
    public function current(string $signerId, #[\SensitiveParameter] string $accessCode): array
    {
        $signerId = $this->pathSegment($signerId, 'signer ID');
        $response = $this->httpClient->get(
            "signers/{$signerId}/document",
            $this->accessCodeQuery($accessCode)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List the signer's documents.
     * `GET /signers/{signer_id}/documents?signer-access-code={code}`
     *
     * @param array<string, scalar> $filters optional `page` and `per-page`
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     */
    public function list(
        string $signerId,
        #[\SensitiveParameter] string $accessCode,
        array $filters = []
    ): array {
        $signerId = $this->pathSegment($signerId, 'signer ID');
        unset($filters['signer-access-code']);
        $page = $filters['page'] ?? 1;
        $perPage = $filters['per-page'] ?? 20;
        if (!is_int($page) || !is_int($perPage)) {
            throw new ValidationException('Signer document page and per-page filters must be integers');
        }
        unset($filters['page'], $filters['per-page']);
        $params = array_merge(
            $this->paginationQuery($page, $perPage, $filters),
            $this->accessCodeQuery($accessCode)
        );

        return $this->withPagination($this->httpClient->get("signers/{$signerId}/documents", $params));
    }

    /**
     * Search the signer's documents using the API's compact representation.
     * `GET /signers/{signer_id}/documents/search`
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(
        string $signerId,
        #[\SensitiveParameter] string $accessCode,
        string $term
    ): array {
        $signerId = $this->pathSegment($signerId, 'signer ID');
        $response = $this->httpClient->get(
            "signers/{$signerId}/documents/search",
            array_merge(['search' => $term], $this->accessCodeQuery($accessCode))
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Sign several virtual-method documents in one call.
     * `PUT /signers/documents/sign-multiple?signer-access-code={code}`
     *
     * @param array<int, string> $documentIds
     * @return array<array-key, mixed>
     *
     * @throws ValidationException when no document IDs are provided
     */
    public function signMultiple(#[\SensitiveParameter] string $accessCode, array $documentIds): array
    {
        $this->assertDocumentIds($documentIds);

        $response = $this->httpClient->put(
            'signers/documents/sign-multiple',
            ['document_ids' => array_values($documentIds)],
            [],
            $this->accessCodeQuery($accessCode)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Decline several documents in one call.
     * `PUT /signers/documents/decline-multiple?signer-access-code={code}`
     *
     * @param array<int, string> $documentIds
     * @return array<array-key, mixed>
     *
     * @throws ValidationException when no document IDs or no reason is provided
     */
    public function declineMultiple(
        #[\SensitiveParameter] string $accessCode,
        array $documentIds,
        #[\SensitiveParameter] string $reason
    ): array {
        $this->assertDocumentIds($documentIds);

        if (trim($reason) === '') {
            throw new ValidationException('A decline reason is required');
        }

        $response = $this->httpClient->put(
            'signers/documents/decline-multiple',
            ['document_ids' => array_values($documentIds), 'decline_reason' => $reason],
            [],
            $this->accessCodeQuery($accessCode)
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
        #[\SensitiveParameter] string $accessCode,
        string $artifact = DocumentResource::ARTIFACT_ORIGINAL
    ): string {
        DocumentResource::assertArtifact($artifact);
        $signerId = $this->pathSegment($signerId, 'signer ID');
        $documentId = $this->pathSegment($documentId, 'document ID');
        $artifact = $this->pathSegment($artifact, 'artifact');

        $response = $this->httpClient->get(
            "signers/{$signerId}/documents/{$documentId}/download/{$artifact}",
            $this->accessCodeQuery($accessCode)
        );

        return $response->getBody();
    }

    /**
     * @param array<int, mixed> $documentIds
     */
    private function assertDocumentIds(array $documentIds): void
    {
        if ($documentIds === []) {
            throw new ValidationException('At least one document ID is required');
        }

        foreach ($documentIds as $documentId) {
            if (!is_string($documentId) || trim($documentId) === '') {
                throw new ValidationException('Document IDs must be non-empty strings');
            }
        }
    }
}
