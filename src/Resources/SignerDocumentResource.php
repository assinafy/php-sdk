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
     * signer to have verified or confirmed their data yet — that is what distinguishes it
     * from {@see SignerSessionResource::currentDocument()}, which needs a verified session
     * and returns the assignment items too.
     *
     * Request (query string): `signer-access-code`.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'resource' => 'document',
     *     'id' => 'document-id',
     *     'account_id' => 'account-id',
     *     'template_id' => null,
     *     'name' => 'agreement.pdf',
     *     'status' => 'pending_signature',
     *     'artifacts' => [
     *         'original' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/download/original',
     *         'thumbnail' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/thumbnail',
     *     ],
     *     'is_closed' => false,
     *     'signing_url' => 'https://app-sandbox.assinafy.com.br/sign/signing-token',
     *     'decline_reason' => null,
     *     'declined_by' => null,
     *     'tags' => [],
     *     'created_at' => '2026-09-01T12:00:00Z',
     *     'updated_at' => '2026-09-01T12:00:00Z',
     *     'assignment' => [
     *         'id' => 'assignment-id',
     *         'sender_email' => 'person@example.com',
     *         'method' => 'collect',
     *         'expires_at' => null,
     *         'message' => null,
     *         'signers' => [
     *             [
     *                 'id' => 'signer-id',
     *                 'full_name' => 'Example Signer',
     *                 'email' => 'person@example.com',
     *                 'whatsapp_phone_number' => null,
     *                 'government_id' => null,
     *                 'has_accepted_terms' => false,
     *                 'completed' => false,
     *                 'notification_history' => [
     *                     [
     *                         'event' => 'signature_request',
     *                         'status' => 'sent',
     *                         'error_code' => null,
     *                         'error_message' => null,
     *                         'sent_at' => '2026-09-01T12:00:00Z',
     *                         'failed_at' => null,
     *                     ],
     *                 ],
     *                 'verification_method' => 'Email',
     *                 'notification_methods' => ['Email'],
     *                 'step' => 1,
     *                 'notified' => true,
     *             ],
     *         ],
     *         'copy_receivers' => [],
     *         'items' => [
     *             [
     *                 'id' => 'assignment-item-id',
     *                 'page' => [
     *                     'id' => 'page-id',
     *                     'number' => 1,
     *                     'height' => 1651,
     *                     'width' => 1275,
     *                     'download_url' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/pages/page-id/download',
     *                 ],
     *                 'signer' => [
     *                     'id' => 'signer-id',
     *                     'full_name' => 'Example Signer',
     *                     'email' => 'person@example.com',
     *                     'whatsapp_phone_number' => null,
     *                     'government_id' => null,
     *                     'has_accepted_terms' => false,
     *                 ],
     *                 'field' => [
     *                     'id' => 'field-id',
     *                     'name' => 'Assinatura',
     *                     'type' => 'signature',
     *                     'regex' => null,
     *                     'is_pre_defined' => true,
     *                     'is_active' => true,
     *                     'is_required' => true,
     *                     'is_standard' => true,
     *                     'is_read_only' => false,
     *                     'is_visible' => true,
     *                 ],
     *                 'display_settings' => [
     *                     'top' => 10,
     *                     'left' => 10,
     *                     'width' => 240,
     *                     'height' => 60,
     *                     'fontSize' => 18,
     *                 ],
     *                 'value' => null,
     *                 'completed' => false,
     *             ],
     *         ],
     *         'summary' => [
     *             'signer_count' => 1,
     *             'completed_count' => 0,
     *             'signers' => [
     *                 [
     *                     'id' => 'signer-id',
     *                     'full_name' => 'Example Signer',
     *                     'email' => 'person@example.com',
     *                     'whatsapp_phone_number' => null,
     *                     'government_id' => null,
     *                     'has_accepted_terms' => false,
     *                     'completed' => false,
     *                 ],
     *             ],
     *         ],
     *         'signing_urls' => [
     *             [
     *                 'signer_id' => 'signer-id',
     *                 'url' => 'https://app-sandbox.assinafy.com.br/sign/signing-token?email=signer%40example.com',
     *             ],
     *         ],
     *     ],
     *     'pages' => [
     *         [
     *             'id' => 'page-id',
     *             'number' => 1,
     *             'height' => 1651,
     *             'width' => 1275,
     *             'download_url' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/pages/page-id/download',
     *         ],
     *     ],
     * ]
     * ```
     *
     * @return array<string, mixed>
     * @throws ValidationException when the signer ID or access code is blank
     * @throws \Assinafy\SDK\Exceptions\ApiException 401 when the access code is wrong
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
     * Everything this signer has been asked to sign across the workspace — the backing call
     * for a "my documents" inbox. Combine with {@see self::signMultiple()} to let a signer
     * clear several at once.
     *
     * Request (query string): `page`, `per-page`, `signer-access-code`.
     *
     * Example query (no request body):
     * ```php
     * ['page' => 1, 'per-page' => 20, 'signer-access-code' => '<access-code>']
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'status' => 200,
     *     'message' => '',
     *     'data' => [
     *         [
     *             'id' => 'document-id',
     *             'account_id' => 'account-id',
     *             'template_id' => null,
     *             'name' => 'agreement.pdf',
     *             'status' => 'metadata_ready',
     *             'artifacts' => [
     *                 'original' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/download/original',
     *                 'thumbnail' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/thumbnail',
     *             ],
     *             'is_closed' => false,
     *             'signing_url' => 'https://app-sandbox.assinafy.com.br/sign/signing-token',
     *             'decline_reason' => null,
     *             'declined_by' => null,
     *             'tags' => [],
     *             'created_at' => '2026-09-01T12:00:00Z',
     *             'updated_at' => '2026-09-01T12:00:00Z',
     *             'assignment' => null,
     *             'pages' => [
     *                 [
     *                     'id' => 'page-id',
     *                     'number' => 1,
     *                     'height' => 1651,
     *                     'width' => 1275,
     *                     'download_url' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/pages/page-id/download',
     *                 ],
     *             ],
     *         ],
     *     ],
     *     'pagination' => [
     *         'current_page' => 1,
     *         'page_count' => 1,
     *         'per_page' => 20,
     *         'total_count' => 1,
     *     ],
     * ]
     * ```
     *
     * @param array<string, scalar> $filters optional `page` and `per-page`
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     * @throws ValidationException when `page`/`per-page` are not integers, or the access
     *     code is blank
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
     * Matches `$term` against the document name. Unlike {@see self::list()} this route is
     * not paginated and returns a flat array rather than the paginated envelope.
     *
     * Request (query string): `search`, `signer-access-code`.
     *
     * Example query (no request body):
     * ```php
     * ['search' => 'agreement', 'signer-access-code' => '<access-code>']
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     [
     *         'resource' => 'document',
     *         'id' => 'document-id',
     *         'account_id' => 'account-id',
     *         'template_id' => null,
     *         'name' => 'document.pdf',
     *         'status' => 'metadata_ready',
     *         'artifacts' => [
     *             'original' => 'https://api.assinafy.com.br/v1/documents/doc1/download/original',
     *         ],
     *         'is_closed' => false,
     *         'signing_url' => 'https://api.assinafy.com.br/v1/sign/document-id',
     *         'decline_reason' => null,
     *         'declined_by' => null,
     *         'tags' => [
     *             [
     *                 'id' => 'document-id',
     *                 'name' => 'Example',
     *             ],
     *         ],
     *         'assignment' => null,
     *         'pages' => [
     *             [
     *                 'id' => 'document-page-id',
     *                 'number' => 1,
     *                 'height' => 2100,
     *                 'width' => 1275,
     *                 'download_url' => 'https://api.assinafy.com.br/v1/documents/document-id/pages/1a/download',
     *             ],
     *         ],
     *         'created_at' => '2026-06-03T03:54:16Z',
     *         'updated_at' => '2026-06-03T03:54:16Z',
     *     ],
     * ]
     * ```
     *
     * @param string $term substring to match against the document name
     * @return array<int, array<string, mixed>>
     * @throws ValidationException when the signer ID or access code is blank
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
     * The bulk "sign all" action for an inbox built on {@see self::list()}. Applies only to
     * `virtual` assignments — `collect` documents carry per-field input and must go through
     * {@see SignerSessionResource::sign()} one at a time.
     *
     * Request:
     * ```
     * PUT /signers/documents/sign-multiple?signer-access-code=<access code>
     * ['document_ids' => ['first-document-id', 'second-document-id']]
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * []
     * ```
     *
     * @param array<int, string> $documentIds documents to sign; re-indexed so a filtered
     *     PHP array still encodes as a JSON list
     * @return array<array-key, mixed>
     *
     * @throws ValidationException when no document IDs are provided, or the access code is blank
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
     * The bulk counterpart to {@see self::signMultiple()}. One reason covers every document
     * in the batch, and each declined document becomes `rejected_by_signer` — terminal for
     * its remaining signers too.
     *
     * Request:
     * ```
     * PUT /signers/documents/decline-multiple?signer-access-code=<access code>
     * [
     *   'document_ids'   => ['document-id'],
     *   'decline_reason' => 'The payment terms are wrong',
     * ]
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * []
     * ```
     *
     * @param array<int, string> $documentIds documents to decline; re-indexed so a filtered
     *     PHP array still encodes as a JSON list
     * @param string             $reason      applied to every document in the batch
     * @return array<array-key, mixed>
     *
     * @throws ValidationException when no document IDs or no reason is provided, or the
     *     access code is blank
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
     * The signer-authenticated counterpart to {@see DocumentResource::download()}: same
     * artifacts, but reachable with a `signer-access-code` instead of a workspace API key.
     * This is how a signer keeps a copy of what they signed.
     *
     * Defaults to `original`, unlike {@see DocumentResource::download()} which defaults to
     * `certificated` — a signer usually wants the copy before certification completes.
     *
     * Request (query string): `signer-access-code`. The artifact is a path segment.
     *
     * Response: the raw bytes (`application/pdf`, or `application/zip` for `bundle`) —
     * **not** the JSON envelope:
     * ```php
     * file_put_contents('my-copy.pdf', $client->signerDocuments()->download(
     *     $signerId, $documentId, $accessCode, DocumentResource::ARTIFACT_CERTIFICATED,
     * ));
     * ```
     *
     * @param string $artifact one of the {@see DocumentResource} `ARTIFACT_*` constants
     * @return string raw file bytes
     * @throws ValidationException on an unknown artifact, a blank identifier, or a blank
     *     access code
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the artifact is not ready yet
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
