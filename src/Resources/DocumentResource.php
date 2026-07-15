<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Documents resource — covers every documented endpoint under `/documents`
 * and `/accounts/{account_id}/documents`.
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class DocumentResource extends AbstractResource
{
    public const ARTIFACT_ORIGINAL = 'original';
    public const ARTIFACT_CERTIFICATED = 'certificated';
    public const ARTIFACT_CERTIFICATE_PAGE = 'certificate-page';
    public const ARTIFACT_BUNDLE = 'bundle';

    public const SEND_TOKEN_CHANNEL_EMAIL = 'email';

    /** Channels accepted by `PUT /public/documents/{id}/send-token`. */
    private const SEND_TOKEN_CHANNELS = [self::SEND_TOKEN_CHANNEL_EMAIL];

    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_METADATA_PROCESSING = 'metadata_processing';
    public const STATUS_METADATA_READY = 'metadata_ready';
    public const STATUS_PENDING_SIGNATURE = 'pending_signature';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CERTIFICATING = 'certificating';
    public const STATUS_CERTIFICATED = 'certificated';
    public const STATUS_REJECTED_BY_SIGNER = 'rejected_by_signer';
    public const STATUS_REJECTED_BY_USER = 'rejected_by_user';
    public const STATUS_FAILED = 'failed';

    /** Statuses that indicate the upload pipeline is finished and the document is usable. */
    public const READY_STATUSES = [
        self::STATUS_METADATA_READY,
        self::STATUS_PENDING_SIGNATURE,
        self::STATUS_CERTIFICATED,
    ];

    /** Terminal statuses that indicate the document will never become ready. */
    public const FAILURE_STATUSES = [
        self::STATUS_FAILED,
        self::STATUS_EXPIRED,
        self::STATUS_REJECTED_BY_SIGNER,
        self::STATUS_REJECTED_BY_USER,
    ];

    /** Max upload size accepted by the API (25 MB). */
    private const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

    /** Max document name length accepted by `PATCH /documents/{id}`. */
    private const MAX_NAME_LENGTH = 255;

    /**
     * Upload a PDF and create a new document.
     * `POST /accounts/{account_id}/documents`
     */
    public function upload(string $filePath): array
    {
        self::assertUploadable($filePath);

        $this->logger->info('Uploading document', [
            'file' => $filePath,
            'size' => filesize($filePath),
        ]);

        $response = $this->httpClient->uploadFile(
            $this->accountPath('documents'),
            $filePath
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Retrieve a document.
     * `GET /documents/{document_id}`
     */
    public function get(string $documentId): array
    {
        $response = $this->httpClient->get("documents/{$documentId}");

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List documents in the workspace.
     * `GET /accounts/{account_id}/documents`
     *
     * Response (full envelope — items under `data`, pagination lifted from the
     * `X-Pagination-*` response headers):
     * ```
     * [
     *   'status'  => 200,
     *   'message' => '',
     *   'data'    => [
     *     [
     *       'id'         => '1032c5537d351349a9a94ad01cbe',
     *       'account_id' => '102d25a489f34a275d31a16045fd',
     *       'name'       => 'contract.pdf',
     *       'status'     => 'pending_signature',   // see the STATUS_* constants
     *       'artifacts'  => ['original' => 'https://…', 'thumbnail' => 'https://…'],
     *       'tags'       => [],
     *       'created_at' => '2026-06-09T17:08:49Z',
     *     ],
     *   ],
     *   'pagination' => ['current_page' => 1, 'page_count' => 9, 'per_page' => 2, 'total_count' => 17],
     * ]
     * ```
     *
     * @param array<string, scalar> $filters optional `status`, `method`, `search`, `sort`
     *     (`sort` is documented on this endpoint only, e.g. `-created_at`)
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     */
    public function list(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $params = array_merge([
            'page' => $page,
            'per-page' => $perPage,
        ], $filters);

        return $this->withPagination($this->httpClient->get($this->accountPath('documents'), $params));
    }

    /**
     * Search documents, returning a lighter representation than {@see self::list()}.
     * `GET /accounts/{account_id}/documents/search`
     *
     * @param array<string, scalar> $filters additional optional filters
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     */
    public function search(string $term, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $params = array_merge([
            'search' => $term,
            'page' => $page,
            'per-page' => $perPage,
        ], $filters);

        return $this->withPagination($this->httpClient->get($this->accountPath('documents/search'), $params));
    }

    /**
     * Rename a document.
     * `PATCH /documents/{document_id}`
     *
     * Only allowed before the signature process starts — the document must be in `uploaded`
     * or `metadata_ready` status with no signers yet. Once an assignment exists (or the
     * document is certificated) the API rejects the call with
     * `400 "Document cannot be renamed after the signature process has started."`
     *
     * The API normalises the name server-side: diacritics are stripped and unsupported
     * characters become dashes, so `"renamed áç.pdf"` is stored as `"renamed ac.pdf"`.
     * Max 255 characters.
     *
     * Request body: `['name' => 'Service agreement.pdf']`
     *
     * @return array<string, mixed> the updated document
     * @throws ValidationException when `$name` is empty or exceeds 255 characters
     */
    public function rename(string $documentId, string $name): array
    {
        if ($name === '') {
            throw new ValidationException('Document name cannot be empty');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ValidationException(sprintf(
                'Document name cannot exceed %d characters, got %d',
                self::MAX_NAME_LENGTH,
                mb_strlen($name)
            ));
        }

        $this->logger->info('Renaming document', ['document_id' => $documentId, 'name' => $name]);

        $response = $this->httpClient->patch("documents/{$documentId}", ['name' => $name]);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete a document.
     * `DELETE /documents/{document_id}`
     */
    public function delete(string $documentId): array
    {
        $this->logger->info('Deleting document', ['document_id' => $documentId]);

        $response = $this->httpClient->delete("documents/{$documentId}");

        return $response->getData() ?? [];
    }

    /**
     * Download an artifact for a document (original, certificated, certificate-page, bundle).
     * `GET /documents/{document_id}/download/{artifact_name}`
     *
     * Returns the raw binary body.
     */
    public function download(string $documentId, string $artifact = self::ARTIFACT_CERTIFICATED): string
    {
        self::assertArtifact($artifact);

        $response = $this->httpClient->get("documents/{$documentId}/download/{$artifact}");

        return $response->getBody();
    }

    /**
     * Download the JPEG thumbnail for a document.
     * `GET /documents/{document_id}/thumbnail`
     */
    public function downloadThumbnail(string $documentId): string
    {
        $response = $this->httpClient->get("documents/{$documentId}/thumbnail");

        return $response->getBody();
    }

    /**
     * Download a rendered page as JPEG.
     * `GET /documents/{document_id}/pages/{page_id}/download`
     */
    public function downloadPage(string $documentId, string $pageId): string
    {
        $response = $this->httpClient->get("documents/{$documentId}/pages/{$pageId}/download");

        return $response->getBody();
    }

    /**
     * List activity events for a document.
     * `GET /documents/{document_id}/activities`
     */
    public function activities(string $documentId): array
    {
        $response = $this->httpClient->get("documents/{$documentId}/activities");

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List all possible document statuses, with their `deletable` flag.
     * `GET /documents/statuses`
     */
    public function statuses(): array
    {
        $response = $this->httpClient->get('documents/statuses');

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Verify a certificated document by its signature hash. Public endpoint, no auth.
     * `GET /documents/{signature_hash}/verify`
     */
    public function verify(string $signatureHash): array
    {
        $response = $this->httpClient->get("documents/{$signatureHash}/verify");

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Public document info (no auth).
     * `GET /public/documents/{document_id}`
     */
    public function publicInfo(string $documentId): array
    {
        $response = $this->httpClient->get("public/documents/{$documentId}");

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Request an access token to be sent to a signer through email.
     * `PUT /public/documents/{document_id}/send-token` (no auth).
     *
     * Only the `email` channel is documented today. Pass {@see SEND_TOKEN_CHANNEL_EMAIL}
     * or one of the constants exposed here — arbitrary strings are rejected up front
     * so a typo doesn't get silently forwarded to the API.
     */
    public function sendToken(
        string $documentId,
        string $recipient,
        string $channel = self::SEND_TOKEN_CHANNEL_EMAIL
    ): array {
        if (!in_array($channel, self::SEND_TOKEN_CHANNELS, true)) {
            throw new ValidationException(
                "Unsupported send-token channel '{$channel}'",
                ['allowed' => self::SEND_TOKEN_CHANNELS]
            );
        }

        $response = $this->httpClient->put(
            "public/documents/{$documentId}/send-token",
            ['recipient' => $recipient, 'channel' => $channel]
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List the tags currently attached to a document.
     * `GET /accounts/{account_id}/documents/{document_id}/tags`
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTags(string $documentId): array
    {
        $response = $this->httpClient->get($this->accountPath("documents/{$documentId}/tags"));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Replace the document's entire tag set with the given names.
     * `PUT /accounts/{account_id}/documents/{document_id}/tags`
     *
     * Names that don't yet exist in the workspace are created automatically
     * (case-insensitive). An empty array detaches all tags.
     *
     * @param array<int, string> $tagNames
     * @return array<int, array<string, mixed>> the document's resulting tag set
     */
    public function replaceTags(string $documentId, array $tagNames): array
    {
        $response = $this->httpClient->put(
            $this->accountPath("documents/{$documentId}/tags"),
            ['tags' => array_values($tagNames)]
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Attach tags to a document without removing existing ones (idempotent).
     * `POST /accounts/{account_id}/documents/{document_id}/tags`
     *
     * Unknown names are auto-created.
     *
     * @param array<int, string> $tagNames
     * @return array<int, array<string, mixed>> the document's resulting tag set
     *
     * @throws ValidationException when no tag names are provided
     */
    public function appendTags(string $documentId, array $tagNames): array
    {
        if ($tagNames === []) {
            throw new ValidationException('At least one tag name is required');
        }

        $response = $this->httpClient->post(
            $this->accountPath("documents/{$documentId}/tags"),
            ['tags' => array_values($tagNames)]
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Detach a single tag from a document (the tag itself is not deleted).
     * `DELETE /accounts/{account_id}/documents/{document_id}/tags/{tag_id}`
     */
    public function detachTag(string $documentId, string $tagId): array
    {
        $response = $this->httpClient->delete(
            $this->accountPath("documents/{$documentId}/tags/{$tagId}")
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Create a document from a template.
     * `POST /accounts/{account_id}/templates/{template_id}/documents`
     *
     * @param array<int, array<string, mixed>> $signers each entry: { role_id, id, verification_method?, notification_methods? }
     * @param array<string, mixed>             $options optional `name`, `message`, `editor_fields`, `expires_at`
     */
    public function createFromTemplate(string $templateId, array $signers, array $options = []): array
    {
        $payload = array_merge(['signers' => $signers], $options);

        $response = $this->httpClient->post(
            $this->accountPath("templates/{$templateId}/documents"),
            $payload
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Estimate cost of creating a document from a template.
     * `POST /accounts/{account_id}/templates/{template_id}/documents/estimate-cost`
     */
    public function estimateCostFromTemplate(string $templateId, array $signers): array
    {
        $response = $this->httpClient->post(
            $this->accountPath("templates/{$templateId}/documents/estimate-cost"),
            ['signers' => $signers]
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Poll `GET /documents/{id}` until the document reaches a usable status.
     *
     * @throws \RuntimeException on terminal failure or timeout
     */
    public function waitUntilReady(string $documentId, int $maxWaitSeconds = 60, int $pollIntervalSeconds = 2): array
    {
        $start = time();

        while ((time() - $start) < $maxWaitSeconds) {
            $document = $this->get($documentId);
            $status = $document['status'] ?? 'unknown';

            if (in_array($status, self::READY_STATUSES, true)) {
                return $document;
            }

            if (in_array($status, self::FAILURE_STATUSES, true)) {
                throw new \RuntimeException("Document processing failed with status: {$status}");
            }

            sleep($pollIntervalSeconds);
        }

        throw new \RuntimeException("Timed out after {$maxWaitSeconds}s waiting for document to become ready");
    }

    /**
     * `true` if the document is fully signed and certificated.
     */
    public function isFullySigned(string $documentId): bool
    {
        return ($this->get($documentId)['status'] ?? '') === self::STATUS_CERTIFICATED;
    }

    /**
     * Return a signed/total/percentage summary derived from the document's assignment.
     *
     * @return array{signed:int,total:int,pending:int,percentage:float}
     */
    public function getSigningProgress(string $documentId): array
    {
        $document = $this->get($documentId);
        $assignment = $document['assignment'] ?? null;

        if ($document['status'] === self::STATUS_CERTIFICATED) {
            $signers = is_array($assignment['signers'] ?? null) ? $assignment['signers'] : [];
            $total = count($signers) ?: 1;

            return [
                'signed' => $total,
                'total' => $total,
                'pending' => 0,
                'percentage' => 100.0,
            ];
        }

        $items = is_array($assignment['items'] ?? null) ? $assignment['items'] : [];
        $signers = is_array($assignment['signers'] ?? null) ? $assignment['signers'] : [];
        $total = count($signers);

        $completedBySigner = [];
        foreach ($items as $item) {
            $signerId = $item['signer']['id'] ?? null;
            if ($signerId === null) {
                continue;
            }
            if (($item['completed'] ?? false) === true) {
                $completedBySigner[$signerId] = ($completedBySigner[$signerId] ?? 0) + 1;
            }
        }

        $signed = 0;
        foreach ($signers as $signer) {
            $id = $signer['id'] ?? null;
            if ($id !== null && ($completedBySigner[$id] ?? 0) > 0) {
                $signed++;
            }
        }

        $percentage = $total > 0 ? round(($signed / $total) * 100, 2) : 0.0;

        return [
            'signed' => $signed,
            'total' => $total,
            'pending' => max(0, $total - $signed),
            'percentage' => $percentage,
        ];
    }

    /**
     * Assert a file can be uploaded as a document or template: it must exist, be a
     * PDF, and not exceed the 25 MB API limit. Shared with {@see TemplateResource::create()}
     * so both upload paths enforce identical constraints.
     *
     * @throws ValidationException when the file is missing, not a PDF, or too large
     */
    public static function assertUploadable(string $filePath): void
    {
        if (!is_file($filePath)) {
            throw new ValidationException('File not found', ['file_path' => $filePath]);
        }

        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new ValidationException('Only PDF files are supported', ['file_path' => $filePath]);
        }

        $size = filesize($filePath);
        if ($size !== false && $size > self::MAX_UPLOAD_BYTES) {
            throw new ValidationException('File size exceeds the 25 MB API limit', [
                'file_size' => $size,
                'max_size' => self::MAX_UPLOAD_BYTES,
            ]);
        }
    }

    /**
     * Assert that `$artifact` is one of the documented artifact names. Shared with
     * {@see SignerDocumentResource::download()} so both download paths validate identically.
     *
     * @throws ValidationException on an unknown artifact name
     */
    public static function assertArtifact(string $artifact): void
    {
        $allowed = [
            self::ARTIFACT_ORIGINAL,
            self::ARTIFACT_CERTIFICATED,
            self::ARTIFACT_CERTIFICATE_PAGE,
            self::ARTIFACT_BUNDLE,
        ];

        if (!in_array($artifact, $allowed, true)) {
            throw new ValidationException("Unknown artifact '{$artifact}'", ['allowed' => $allowed]);
        }
    }
}
