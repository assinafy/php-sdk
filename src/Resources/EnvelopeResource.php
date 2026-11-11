<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Workspace document envelopes. API key or workspace Bearer authentication; reads require
 * documents:read and writes documents:write. Creation, estimation and addition require the
 * Envelopes plan feature. See docs/ENVELOPES.md for complete request and response examples.
 */
class EnvelopeResource extends AbstractResource
{
    /**
     * GET /accounts/{accountId}/envelopes. No body; filters: search, status (open/closed).
     * Request: ['page' => 1, 'per-page' => 20, 'status' => 'open'].
     * Response: ['status' => 200, 'data' => [], 'pagination' => ['current_page' => 1,
     * 'page_count' => 0, 'per_page' => 20, 'total_count' => 0]].
     * @param array<string, scalar> $filters
     * @return array<string, mixed> paginated envelope response
     */
    public function list(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        if (isset($filters['status']) && !in_array($filters['status'], ['open', 'closed'], true)) {
            throw new ValidationException('Envelope status must be open or closed');
        }
        return $this->withPagination($this->httpClient->get(
            $this->accountPath('envelopes'),
            $this->paginationQuery($page, $perPage, $filters)
        ));
    }

    /**
     * GET /accounts/{accountId}/envelopes/{envelopeId}. No body or query.
     * Response: the DocumentEnvelope array illustrated in docs/ENVELOPES.md.
     * @param string $envelopeId existing envelope ID
     * @return array<string, mixed> envelope with documents, counts and signing_urls
     */
    public function get(string $envelopeId): array
    {
        return $this->extractData($this->httpClient->get($this->envelopePath($envelopeId))->getData() ?? []);
    }

    /**
     * POST /accounts/{accountId}/envelopes. Documents must be metadata_ready and unassigned.
     * Request: ['name' => 'Agreement', 'signers' => [['id' => 'signer-id']],
     * 'documents' => [['id' => 'document-id', 'signer_ids' => ['signer-id']]]].
     * Response: the DocumentEnvelope array illustrated in docs/ENVELOPES.md.
     * An optional Idempotency-Key replays the original result without charging again.
     * A 200 means creation succeeded; inspect notification_history for delivery failures.
     * @param array<string, mixed> $data complete EnvelopeCreateRequest, including optional max_total_credits
     * @param string|null $idempotencyKey unique per intended envelope; preserve on ambiguous failures
     * @return array<string, mixed> created envelope
     */
    public function create(#[\SensitiveParameter] array $data, ?string $idempotencyKey = null): array
    {
        $data = $this->creationPayload($data);
        return $this->extractData($this->httpClient->post(
            $this->accountPath('envelopes'),
            $data,
            $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey]
        )->getData() ?? []);
    }

    /**
     * POST /accounts/{accountId}/envelopes/estimate-cost. Same request as create(); no mutation.
     * Response: ['documents' => 1, 'credits' => 0, 'needs_extra_document' => false,
     * 'extra_document_cost' => 0, 'total_credits' => 0, 'breakdown' => [],
     * 'document_balance' => 10, 'credit_balance' => 20, 'has_sufficient_resources' => true,
     * 'blocking_reason' => null, 'message' => null]. max_total_credits is ignored by estimates.
     * @param array<string, mixed> $data EnvelopeCreateRequest; documents must be ready and unassigned
     * @return array<string, mixed> cost and balances; does not prove notification delivery
     */
    public function estimateCost(#[\SensitiveParameter] array $data): array
    {
        return $this->extractData($this->httpClient->post(
            $this->accountPath('envelopes/estimate-cost'),
            $this->creationPayload($data)
        )->getData() ?? []);
    }

    /**
     * PATCH /accounts/{accountId}/envelopes/{envelopeId}. Open or closed envelope; sends no notice.
     * Request: ['name' => 'Agreement', 'message' => null, 'document_ids' => ['document-id']].
     * Send only changes; document_ids must list every current document once in its new order.
     * Response: DocumentEnvelope as in docs/ENVELOPES.md, with updated values.
     * @param string $envelopeId existing envelope ID
     * @param array<string, mixed> $data name, message and/or document_ids
     * @return array<string, mixed> updated envelope
     */
    public function update(string $envelopeId, #[\SensitiveParameter] array $data): array
    {
        if ($data === []) {
            throw new ValidationException('Provide at least one envelope property to update');
        }
        $this->assertNameAndMessage($data);
        if (array_key_exists('document_ids', $data)) {
            $data['document_ids'] = $this->idList($data['document_ids'], 'document_ids');
        }
        return $this->extractData($this->httpClient->patch(
            $this->envelopePath($envelopeId),
            $data
        )->getData() ?? []);
    }

    /**
     * DELETE /accounts/{accountId}/envelopes/{envelopeId}. No body or query; response: [].
     * All documents must be closed (otherwise 409 EnvelopeOpen); documents remain available.
     * @param string $envelopeId closed envelope ID
     * @return array<string, mixed> empty unwrapped data
     */
    public function delete(string $envelopeId): array
    {
        return $this->extractData($this->httpClient->delete($this->envelopePath($envelopeId))->getData() ?? []);
    }

    /**
     * GET /accounts/{accountId}/envelopes/{envelopeId}/activities. Query: page, per-page, sort.
     * Response: ['status' => 200, 'data' => [['id' => 1, 'document_id' => 'document-id',
     * 'document_name' => 'Agreement.pdf', 'event' => 'document_uploaded', 'message' => 'Uploaded',
     * 'payload' => [], 'origin' => null, 'created_at' => '2026-11-01T12:00:00Z']],
     * 'pagination' => ['current_page' => 1, 'page_count' => 1, 'per_page' => 20, 'total_count' => 1]].
     * Includes removed documents, excludes deleted documents. Default newest first.
     * @param string $envelopeId existing envelope ID
     * @param int $page positive page number
     * @param int $perPage positive page size
     * @param string $sort -id (newest first) or id (oldest first)
     * @return array<string, mixed> paginated activities
     */
    public function activities(string $envelopeId, int $page = 1, int $perPage = 20, string $sort = '-id'): array
    {
        if (!in_array($sort, ['id', '-id'], true)) {
            throw new ValidationException('Envelope activity sort must be id or -id');
        }
        return $this->withPagination($this->httpClient->get(
            $this->envelopePath($envelopeId) . '/activities',
            $this->paginationQuery($page, $perPage, ['sort' => $sort])
        ));
    }

    /**
     * GET /accounts/{accountId}/envelopes/{envelopeId}/download/{artifactName}. No body or query.
     * Response: application/zip bytes, ordered by document position. 404 if none has the artifact.
     * @param string $envelopeId existing envelope ID
     * @param string $artifact original, certificated, certificate-page, pades or bundle
     * @return string ZIP bytes; non-original artifacts include only certified documents
     */
    public function download(string $envelopeId, string $artifact = DocumentResource::ARTIFACT_CERTIFICATED): string
    {
        DocumentResource::assertArtifact($artifact);
        return $this->httpClient->get(
            $this->envelopePath($envelopeId) . '/download/' . $this->pathSegment($artifact)
        )->getBody();
    }

    /**
     * PUT /accounts/{accountId}/envelopes/{envelopeId}/cancel. No body or query.
     * Response: [['id' => 'document-id', 'status' => 'rejected_by_user', 'cancelled' => true]].
     * Cancels cancellable documents and notifies participants; safe to repeat. Closed documents,
     * documents signed by everyone and documents undergoing certification stay unchanged.
     * @param string $envelopeId existing envelope ID
     * @return array<int, array<string, mixed>> per-document cancellation results
     */
    public function cancel(string $envelopeId): array
    {
        return $this->extractData($this->httpClient->put($this->envelopePath($envelopeId) . '/cancel')->getData() ?? []);
    }

    /**
     * PUT /accounts/{accountId}/envelopes/{envelopeId}/signers/{signerId}/resend. No body or query.
     * Response: ['is_sent' => true, 'envelope_id' => 'envelope-id', 'signer_id' => 'signer-id'].
     * Requires a signer with pending documents in an open envelope; paid notifications cost credits.
     * @param string $envelopeId open envelope ID
     * @param string $signerId signer in this envelope
     * @return array<string, mixed> notification result; is_sent reports delivery submission
     */
    public function resend(string $envelopeId, string $signerId): array
    {
        return $this->extractData($this->httpClient->put(
            $this->envelopePath($envelopeId) . '/signers/' . $this->pathSegment($signerId, 'signer ID') . '/resend'
        )->getData() ?? []);
    }

    /**
     * POST /accounts/{accountId}/envelopes/{envelopeId}/documents. Open envelope, ready unassigned document.
     * Request: ['id' => 'document-id', 'signer_ids' => ['signer-id'], 'method' => 'virtual',
     * 'expires_at' => null, 'copy_receiver_ids' => [], 'max_total_credits' => 0].
     * Response: DocumentEnvelope from docs/ENVELOPES.md, with the new document last.
     * Only existing envelope signers may participate. Sends invitations; certificate signatures cost credits.
     * @param string $envelopeId open envelope ID
     * @param array<string, mixed> $document EnvelopeDocumentRequest and optional max_total_credits
     * @return array<string, mixed> updated envelope
     */
    public function addDocument(string $envelopeId, #[\SensitiveParameter] array $document): array
    {
        return $this->extractData($this->httpClient->post(
            $this->envelopePath($envelopeId) . '/documents',
            $this->documentPayload($document)
        )->getData() ?? []);
    }

    /**
     * DELETE /accounts/{accountId}/envelopes/{envelopeId}/documents/{documentId}. No body or query.
     * Response: DocumentEnvelope from docs/ENVELOPES.md without that document.
     * Cancels the removed document and notifies its participants; 409 DocumentClosed if not cancellable.
     * @param string $envelopeId existing envelope ID
     * @param string $documentId cancellable document currently in the envelope
     * @return array<string, mixed> updated envelope
     */
    public function removeDocument(string $envelopeId, string $documentId): array
    {
        return $this->extractData($this->httpClient->delete(
            $this->envelopePath($envelopeId) . '/documents/' . $this->pathSegment($documentId, 'document ID')
        )->getData() ?? []);
    }

    private function envelopePath(string $envelopeId): string
    {
        return $this->accountPath('envelopes/' . $this->pathSegment($envelopeId, 'envelope ID'));
    }

    /** @param array<string, mixed> $data */
    private function assertNameAndMessage(array $data): void
    {
        if (array_key_exists('name', $data)) {
            $name = $data['name'];
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 255 || str_contains($name, '<')) {
                throw new ValidationException('Envelope name must contain 1 to 255 characters and no <');
            }
        }
        if (isset($data['message']) && !is_string($data['message'])) {
            throw new ValidationException('Envelope message must be a string or null');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function creationPayload(array $data): array
    {
        if (!array_key_exists('name', $data)) {
            throw new ValidationException('Envelope name is required');
        }
        $this->assertNameAndMessage($data);
        foreach (['signers', 'documents'] as $key) {
            if (!is_array($data[$key] ?? null) || count($data[$key]) < 1 || count($data[$key]) > 20) {
                throw new ValidationException('Envelope ' . $key . ' must contain 1 to 20 entries');
            }
            $data[$key] = array_values($data[$key]);
        }
        foreach ($data['signers'] as &$signer) {
            if (!is_array($signer) || !is_string($signer['id'] ?? null) || trim($signer['id']) === '') {
                throw new ValidationException('Every envelope signer requires an id');
            }
            if (array_key_exists('step', $signer)) {
                throw new ValidationException('Envelope signers cannot define signing steps');
            }
            if (array_key_exists('notification_methods', $signer)) {
                if (!is_array($signer['notification_methods']) || count($signer['notification_methods']) > 1) {
                    throw new ValidationException('Envelope notification methods must be an array with at most one entry');
                }
                $signer['notification_methods'] = array_values($signer['notification_methods']);
            }
        }
        unset($signer);
        foreach ($data['documents'] as &$document) {
            if (!is_array($document)) {
                throw new ValidationException('Each envelope document must be an object');
            }
            $document = $this->documentPayload($document);
        }
        unset($document);
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function documentPayload(array $data): array
    {
        if (!is_string($data['id'] ?? null) || trim($data['id']) === '') {
            throw new ValidationException('Envelope document id is required');
        }
        $data['signer_ids'] = $this->idList($data['signer_ids'] ?? null, 'signer_ids');
        if (array_key_exists('copy_receiver_ids', $data)) {
            $data['copy_receiver_ids'] = $this->idList($data['copy_receiver_ids'], 'copy_receiver_ids', true);
        }
        if (!in_array($data['method'] ?? 'virtual', ['virtual', 'collect'], true)) {
            throw new ValidationException('Envelope document method must be virtual or collect');
        }
        if (($data['method'] ?? 'virtual') === 'collect' && empty($data['entries'])) {
            throw new ValidationException('Collect documents require entries');
        }
        if (array_key_exists('entries', $data)) {
            if (!is_array($data['entries'])) {
                throw new ValidationException('Envelope document entries must be an array');
            }
            $data['entries'] = array_values($data['entries']);
            foreach ($data['entries'] as &$entry) {
                if (!is_array($entry) || !is_array($entry['fields'] ?? null)) {
                    throw new ValidationException('Envelope document entries require fields arrays');
                }
                $entry['fields'] = array_values($entry['fields']);
            }
            unset($entry);
        }
        return $data;
    }

    /** @return list<string> */
    private function idList(mixed $ids, string $name, bool $allowEmpty = false): array
    {
        if (!is_array($ids) || (!$allowEmpty && $ids === [])) {
            throw new ValidationException($name . ' must be a non-empty array of IDs');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || trim($id) === '') {
                throw new ValidationException($name . ' must contain non-empty string IDs');
            }
        }
        return array_values($ids);
    }
}
