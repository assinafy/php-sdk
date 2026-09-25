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
    public const ARTIFACT_PADES = 'pades';
    public const ARTIFACT_BUNDLE = 'bundle';

    public const SEND_TOKEN_CHANNEL_EMAIL = 'email';

    /** Channels accepted by `PUT /public/documents/{id}/send-token`. */
    private const SEND_TOKEN_CHANNELS = [self::SEND_TOKEN_CHANNEL_EMAIL];

    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_METADATA_PROCESSING = 'metadata_processing';
    public const STATUS_METADATA_READY = 'metadata_ready';
    public const STATUS_PENDING_SIGNATURE = 'pending_signature';
    public const STATUS_READY = 'ready';
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
        self::STATUS_READY,
        self::STATUS_CERTIFICATING,
        self::STATUS_CERTIFICATED,
    ];

    /** Terminal statuses that indicate the document will never become ready. */
    public const FAILURE_STATUSES = [
        self::STATUS_FAILED,
        self::STATUS_EXPIRED,
        self::STATUS_REJECTED_BY_SIGNER,
        self::STATUS_REJECTED_BY_USER,
    ];

    /** Statuses that mean every signer has completed the assignment. */
    private const FULLY_SIGNED_STATUSES = [
        self::STATUS_READY,
        self::STATUS_CERTIFICATING,
        self::STATUS_CERTIFICATED,
    ];

    /** Max upload size accepted by the API (25 MB). */
    private const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

    /** Max document name length accepted by `PATCH /documents/{id}`. */
    private const MAX_NAME_LENGTH = 255;

    /**
     * Upload a PDF and create a new document.
     * `POST /accounts/{account_id}/documents`
     *
     * The first step of every signature workflow. The file is checked locally (exists,
     * readable, PDF, ≤ 25 MB) before any bytes go over the wire.
     *
     * The upload returns immediately with `status: uploaded`; the API then renders pages
     * asynchronously. Wait for {@see self::waitUntilReady()} before creating an assignment.
     *
     * Request: `multipart/form-data` with the PDF under the field name `file`.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'resource' => 'document',
     *     'id' => 'document-id',
     *     'account_id' => 'account-id',
     *     'template_id' => null,
     *     'name' => 'agreement.pdf',
     *     'status' => 'uploaded',
     *     'artifacts' => [
     *         'original' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/download/original',
     *     ],
     *     'is_closed' => false,
     *     'signing_url' => 'https://app-sandbox.assinafy.com.br/sign/signing-token',
     *     'decline_reason' => null,
     *     'declined_by' => null,
     *     'tags' => [],
     *     'created_at' => '2026-09-01T12:00:00Z',
     *     'updated_at' => '2026-09-01T12:00:00Z',
     *     'pages' => [],
     * ]
     * ```
     *
     * @return array<string, mixed> the created document
     * @throws ValidationException when the file is missing, not a PDF, or over 25 MB
     */
    public function upload(#[\SensitiveParameter] string $filePath): array
    {
        self::assertUploadable($filePath);

        $this->logger->info('Uploading document', [
            'size' => filesize($filePath),
        ]);

        $response = $this->httpClient->uploadFile(
            $this->accountPath('documents'),
            $filePath
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Retrieve a document, including its pages and current assignment.
     * `GET /documents/{document_id}`
     *
     * Richer than the {@see self::list()} entries: only this route returns `pages` (with the
     * page IDs {@see self::downloadPage()} and `collect` assignments need) and the fully
     * expanded `assignment`.
     *
     * Request: no parameters.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'resource' => 'document',
     *     'id' => 'document-id',
     *     'account_id' => 'account-id',
     *     'template_id' => null,
     *     'name' => 'agreement.pdf',
     *     'status' => 'metadata_ready',
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
     *     'assignment' => null,
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
     * @throws ValidationException when `$documentId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the document does not exist
     */
    public function get(string $documentId): array
    {
        $documentId = $this->pathSegment($documentId, 'document ID');
        $response = $this->httpClient->get("documents/{$documentId}");

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List documents in the workspace.
     * `GET /accounts/{account_id}/documents`
     *
     * Example query (no request body):
     * ```php
     * ['page' => 1, 'per-page' => 20]
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
     * @param array<string, scalar> $filters optional `status`, `method`, `search`, `tags`,
     *     and `sort` (`sort` accepts `name` or `updated_at`)
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     */
    public function list(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $params = $this->paginationQuery($page, $perPage, $filters);

        return $this->withPagination($this->httpClient->get($this->accountPath('documents'), $params));
    }

    /**
     * Search documents, returning a lighter representation than {@see self::list()}.
     * `GET /accounts/{account_id}/documents/search`
     *
     * Matches `$term` against the document name. Entries omit `assignment` and `pages`,
     * which makes this the cheaper choice for type-ahead and pickers; fetch the full record
     * with {@see self::get()} once the user picks one.
     *
     * Request (query string): `search`, `page`, `per-page`, plus an optional `status` filter.
     *
     * Example query (no request body):
     * ```php
     * ['search' => 'agreement', 'page' => 1, 'per-page' => 20]
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
     * @param array<string, scalar> $filters additional optional filters, e.g. `status`
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     * @throws ValidationException when `$page` < 1 or `$perPage` is outside 1–100
     */
    public function search(string $term, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $params = $this->paginationQuery($page, $perPage, $filters);
        $params['search'] = $term;

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
     * Request body:
     * ```
     * ['name' => 'Service agreement.pdf']
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'resource' => 'document',
     *     'id' => 'document-id',
     *     'account_id' => 'account-id',
     *     'template_id' => null,
     *     'name' => 'Service agreement.pdf',
     *     'status' => 'metadata_ready',
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
     *     'assignment' => null,
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
     * @return array<string, mixed> the updated document
     * @throws ValidationException when `$name` is empty or exceeds 255 characters
     * @throws \Assinafy\SDK\Exceptions\ApiException 400 once signing has started
     */
    public function rename(string $documentId, #[\SensitiveParameter] string $name): array
    {
        if (trim($name) === '') {
            throw new ValidationException('Document name cannot be empty');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ValidationException(sprintf(
                'Document name cannot exceed %d characters, got %d',
                self::MAX_NAME_LENGTH,
                mb_strlen($name)
            ));
        }

        $this->logger->info('Renaming document', ['document_id' => $documentId]);

        $documentId = $this->pathSegment($documentId, 'document ID');
        $response = $this->httpClient->patch("documents/{$documentId}", ['name' => $name]);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete a document.
     * `DELETE /documents/{document_id}`
     *
     * Only allowed from a status whose `deletable` flag is true — call
     * {@see self::statuses()} for the authoritative list. A document mid-certification
     * cannot be removed.
     *
     * Request: no body.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'status' => 200,
     *     'message' => '',
     *     'data' => [],
     * ]
     * ```
     *
     * @return array<array-key, mixed> the raw envelope
     * @throws ValidationException when `$documentId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 400 from a non-deletable status
     */
    public function delete(string $documentId): array
    {
        $this->logger->info('Deleting document', ['document_id' => $documentId]);

        $documentId = $this->pathSegment($documentId, 'document ID');
        $response = $this->httpClient->delete("documents/{$documentId}");

        return $response->getData() ?? [];
    }

    /**
     * Download an artifact for a document.
     * `GET /documents/{document_id}/download/{artifact_name}`
     *
     * Artifacts, via the `ARTIFACT_*` constants:
     *
     * | Constant                     | Value              | What you get                              |
     * |------------------------------|--------------------|-------------------------------------------|
     * | `ARTIFACT_ORIGINAL`          | `original`         | The PDF exactly as uploaded               |
     * | `ARTIFACT_CERTIFICATED`      | `certificated`     | Signed PDF with the certificate page      |
     * | `ARTIFACT_CERTIFICATE_PAGE`  | `certificate-page` | The certificate page on its own     |
     * | `ARTIFACT_PADES`            | `pades`            | PAdES-conformant signed PDF               |
     * | `ARTIFACT_BUNDLE`           | `bundle`           | ZIP of every artifact above               |
     *
     * Everything except `original` exists only once the document reaches `certificated`.
     *
     * Request: no parameters — the artifact is a path segment.
     *
     * Response: the raw bytes (`application/pdf`, or `application/zip` for `bundle`) —
     * **not** the JSON envelope:
     * ```php
     * file_put_contents('signed.pdf', $client->documents()->download($id));
     * ```
     *
     * @return string raw file bytes
     * @throws ValidationException on an unknown artifact name or an empty document ID
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the artifact is not ready yet
     */
    public function download(string $documentId, string $artifact = self::ARTIFACT_CERTIFICATED): string
    {
        self::assertArtifact($artifact);
        $documentId = $this->pathSegment($documentId, 'document ID');
        $artifact = $this->pathSegment($artifact, 'artifact');

        $response = $this->httpClient->get("documents/{$documentId}/download/{$artifact}");

        return $response->getBody();
    }

    /**
     * Download the JPEG thumbnail for a document.
     * `GET /documents/{document_id}/thumbnail`
     *
     * A preview of the first page, available once the document reaches `metadata_ready`.
     *
     * Request: no parameters.
     *
     * Response: raw `image/jpeg` bytes — not the JSON envelope.
     *
     * @return string raw JPEG bytes
     * @throws ValidationException when `$documentId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 before page rendering finishes
     */
    public function downloadThumbnail(string $documentId): string
    {
        $documentId = $this->pathSegment($documentId, 'document ID');
        $response = $this->httpClient->get("documents/{$documentId}/thumbnail");

        return $response->getBody();
    }

    /**
     * Download a rendered page as JPEG.
     * `GET /documents/{document_id}/pages/{page_id}/download`
     *
     * Page IDs come from the `pages` array on {@see self::get()}. Use these renders to
     * position fields when building a `collect` assignment — the `width`/`height` reported
     * alongside each page are the coordinate space `display_settings` is expressed in.
     *
     * Request: no parameters.
     *
     * Response: raw `image/jpeg` bytes — not the JSON envelope.
     *
     * @return string raw JPEG bytes
     * @throws ValidationException when either identifier is empty
     */
    public function downloadPage(string $documentId, string $pageId): string
    {
        $documentId = $this->pathSegment($documentId, 'document ID');
        $pageId = $this->pathSegment($pageId, 'page ID');
        $response = $this->httpClient->get("documents/{$documentId}/pages/{$pageId}/download");

        return $response->getBody();
    }

    /**
     * List activity events for a document.
     * `GET /documents/{document_id}/activities`
     *
     * The activity history: upload, preparation, each notification, each view, each signature,
     * and certification — **newest first**. This is the record behind the certificate page.
     *
     * `event` uses the same vocabulary as the webhook event types
     * ({@see \Assinafy\SDK\Resources\WebhookResource} `EVENT_*`), so one switch can handle
     * both. `origin` is null for events the platform raised itself rather than a request.
     *
     * Request: no parameters.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     [
     *         'id' => 31451,
     *         'event' => 'document_metadata_ready',
     *         'message' => 'Documento processado.',
     *         'payload' => [],
     *         'origin' => null,
     *         'created_at' => '2026-09-01T12:00:00Z',
     *     ],
     *     [
     *         'id' => 31450,
     *         'event' => 'document_uploaded',
     *         'message' => 'Documento criado.',
     *         'payload' => [],
     *         'origin' => [
     *             'ip' => '<ip>',
     *             'user-agent' => 'Assinafy-PHP-SDK/v2.2.0',
     *         ],
     *         'created_at' => '2026-09-01T12:00:00Z',
     *     ],
     * ]
     * ```
     *
     * @return array<int, array<string, mixed>>
     * @throws ValidationException when `$documentId` is empty
     */
    public function activities(string $documentId): array
    {
        $documentId = $this->pathSegment($documentId, 'document ID');
        $response = $this->httpClient->get("documents/{$documentId}/activities");

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List all possible document statuses, with their `deletable` flag.
     * `GET /documents/statuses`
     *
     * The authoritative source for which statuses {@see self::delete()} accepts — prefer it
     * over hard-coding, since the platform can add statuses. The `STATUS_*` constants mirror
     * the codes below.
     *
     * Request: no parameters. Not account-scoped.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     [
     *         'code' => 'uploading',
     *         'deletable' => false,
     *     ],
     *     [
     *         'code' => 'uploaded',
     *         'deletable' => false,
     *     ],
     *     [
     *         'code' => 'metadata_processing',
     *         'deletable' => false,
     *     ],
     *     [
     *         'code' => 'metadata_ready',
     *         'deletable' => true,
     *     ],
     *     [
     *         'code' => 'expired',
     *         'deletable' => true,
     *     ],
     *     [
     *         'code' => 'certificating',
     *         'deletable' => false,
     *     ],
     *     [
     *         'code' => 'certificated',
     *         'deletable' => false,
     *     ],
     *     [
     *         'code' => 'rejected_by_signer',
     *         'deletable' => true,
     *     ],
     *     [
     *         'code' => 'pending_signature',
     *         'deletable' => true,
     *     ],
     *     [
     *         'code' => 'rejected_by_user',
     *         'deletable' => true,
     *     ],
     *     [
     *         'code' => 'failed',
     *         'deletable' => true,
     *     ],
     * ]
     * ```
     *
     * @return array<int, array{code: string, deletable: bool}>
     */
    public function statuses(): array
    {
        $response = $this->httpClient->get('documents/statuses');

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Verify a certificated document by its signature hash. Public endpoint, no auth.
     * `GET /documents/{signature_hash}/verify`
     *
     * The hash is printed on the certificate page, so any recipient can confirm a PDF is
     * genuine and unaltered without holding credentials. The SDK strips workspace
     * credentials from this request even on an authenticated client.
     *
     * Request: no parameters — the hash is the path segment.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'hash' => 'FE32EDDADE7CBDDCBB934E7402047450B0E59C02',
     *     'id' => 'document-verification-id',
     *     'agreement_code' => '550E8400-E29B-41D4-A716-446655440000',
     *     'status' => 'certificated',
     *     'page_count' => '1',
     *     'signer_count' => '1',
     *     'completed_count' => 1,
     *     'completed_at' => '2023-01-27T19:27:44Z',
     *     'verified_at' => '2023-01-27T19:27:46Z',
     *     'is_valid' => true,
     *     'message' => '',
     * ]
     * ```
     *
     * `agreement_code` is the agreement code printed on the document certificate. It may be null
     * or absent (the sandbox omits it), so read it with `$result['agreement_code'] ?? null`.
     *
     * An unknown or unsigned hash answers **200, not 404** — always branch on `is_valid`:
     * ```
     * [
     *   'hash' => 'resource-id', 'id' => null, 'agreement_code' => null, 'status' => null,
     *   'page_count' => null, 'signer_count' => null, 'completed_count' => null,
     *   'completed_at' => null, 'verified_at' => '2026-08-27T22:20:03Z',
     *   'is_valid' => false, 'message' => 'Documento não assinado ou não encontrado.',
     * ]
     * ```
     *
     * @return array<string, mixed>
     * @throws ValidationException when `$signatureHash` is empty
     */
    public function verify(string $signatureHash): array
    {
        $signatureHash = $this->pathSegment($signatureHash, 'signature hash');
        $response = $this->httpClient->get("documents/{$signatureHash}/verify");

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Public document info (no auth).
     * `GET /public/documents/{document_id}`
     *
     * The deliberately thin projection behind a public signing link — enough to render
     * "Acme Inc. sent you contract.pdf" before the recipient has proved who they are. No
     * signer list, no artifacts, no content. The SDK strips workspace credentials from this
     * request even on an authenticated client.
     *
     * Request: no parameters.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'resource' => 'document',
     *     'id' => 'document-id',
     *     'name' => 'agreement.pdf',
     *     'page_count' => '1',
     *     'created_by' => 'Example Signer',
     * ]
     * ```
     *
     * @return array<string, mixed>
     * @throws ValidationException when `$documentId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the document does not exist
     */
    public function publicInfo(string $documentId): array
    {
        $documentId = $this->pathSegment($documentId, 'document ID');
        $response = $this->httpClient->get("public/documents/{$documentId}");

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Request an access token to be sent to a signer through email.
     * `PUT /public/documents/{document_id}/send-token` (no auth).
     *
     * Only the `email` channel is supported. Pass {@see SEND_TOKEN_CHANNEL_EMAIL} — arbitrary
     * strings are rejected up front so a typo never reaches the API. The recipient is validated
     * as an email address locally for the same reason.
     *
     * The published description claims "email/WhatsApp", but the schema declares no `channel`
     * property at all and the running API answers `400 "Canal inválido"` for `whatsapp` — the
     * same error a nonsense channel gets. A lowercase `sms` does pass the API's channel check,
     * yet always fails with `404 "Solicitação de entrada do signatário não encontrada."`: it
     * needs an SMS signer entry, and none can be created, because `verification_method` accepts
     * only Email/Whatsapp/DigitalCertificate and `notification_methods` only Email/Whatsapp.
     * The single-channel list is deliberate and live-verified; do not widen it.
     *
     * Request body:
     * ```
     * ['recipient' => 'jane@example.com', 'channel' => 'email']
     * ```
     *
     * Both keys are mandatory. The published OpenAPI schema for this operation shows a
     * single optional `email` property instead; that body is rejected by the running API
     * with `400 "O atributo \"channel\" é obrigatório."`, so the SDK sends the pair above,
     * which the server accepts. Verified against the live API — do not "correct" this
     * toward the published schema without re-testing it.
     *
     * The document must be in `pending_signature`; otherwise the API answers
     * `400 "O documento não está com status de assinatura pendente."`
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'document' => [
     *         'resource' => 'document',
     *         'id' => 'document-id',
     *         'name' => 'agreement.pdf',
     *         'page_count' => '1',
     *         'created_by' => 'Example Signer',
     *     ],
     *     'channel' => 'email',
     *     'recipient' => 'jane@example.com',
     * ]
     * ```
     *
     * @param string $recipient email address to receive the one-time token
     * @param string $channel   delivery channel; only {@see SEND_TOKEN_CHANNEL_EMAIL}
     * @return array<array-key, mixed>
     * @throws ValidationException on an unsupported channel or a malformed recipient
     */
    public function sendToken(
        string $documentId,
        #[\SensitiveParameter] string $recipient,
        string $channel = self::SEND_TOKEN_CHANNEL_EMAIL
    ): array {
        if (!in_array($channel, self::SEND_TOKEN_CHANNELS, true)) {
            throw new ValidationException(
                "Unsupported send-token channel '{$channel}'",
                ['allowed' => self::SEND_TOKEN_CHANNELS]
            );
        }

        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Send-token recipient must be a valid email address');
        }

        $documentId = $this->pathSegment($documentId, 'document ID');

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
     * Request: no parameters.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     [
     *         'id' => 'resource-id',
     *         'name' => 'Example',
     *         'color' => null,
     *         'created_at' => '2026-09-01T12:00:00Z',
     *         'updated_at' => '2026-09-01T12:00:00Z',
     *     ],
     * ]
     * ```
     *
     * @return array<int, array<string, mixed>>
     * @throws ValidationException when `$documentId` is empty
     */
    public function listTags(string $documentId): array
    {
        $documentId = $this->pathSegment($documentId, 'document ID');
        $response = $this->httpClient->get($this->accountPath("documents/{$documentId}/tags"));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Replace the document's entire tag set with the given names.
     * `PUT /accounts/{account_id}/documents/{document_id}/tags`
     *
     * Names that don't yet exist in the workspace are created automatically
     * (case-insensitive). An empty array detaches all tags — that is the difference from
     * {@see self::appendTags()}, which never removes anything.
     *
     * The request addresses tags by **name**; the response contains the resulting tag objects.
     *
     * Request body:
     * ```
     * ['tags' => ['contracts']]   // [] clears every tag
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     [
     *         'id' => 'tag-id',
     *         'name' => 'contracts',
     *         'color' => null,
     *         'created_at' => '2026-09-01T12:00:00Z',
     *         'updated_at' => '2026-09-01T12:00:00Z',
     *     ],
     * ]
     * ```
     *
     * @param array<int, string> $tagNames tag names; `[]` detaches all
     * @return array<int, array<string, mixed>> the document's resulting tag set
     * @throws ValidationException when a name is not a non-empty string
     */
    public function replaceTags(string $documentId, array $tagNames): array
    {
        $this->assertTagNames($tagNames, true);
        $documentId = $this->pathSegment($documentId, 'document ID');
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
     * Unknown names are auto-created. Re-attaching a tag the document already carries is a
     * no-op rather than an error, which makes this safe to retry.
     *
     * Request body (at least one name required):
     * ```
     * ['tags' => ['urgent']]
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     [
     *         'id' => 'tag-id',
     *         'name' => 'urgent',
     *         'color' => null,
     *         'created_at' => '2026-09-01T12:00:00Z',
     *         'updated_at' => '2026-09-01T12:00:00Z',
     *     ],
     * ]
     * ```
     *
     * @param array<int, string> $tagNames tag names to attach
     * @return array<int, array<string, mixed>> the document's resulting tag set
     *
     * @throws ValidationException when no tag names are provided, or one is not a
     *     non-empty string
     */
    public function appendTags(string $documentId, array $tagNames): array
    {
        $this->assertTagNames($tagNames, false);
        $documentId = $this->pathSegment($documentId, 'document ID');

        $response = $this->httpClient->post(
            $this->accountPath("documents/{$documentId}/tags"),
            ['tags' => array_values($tagNames)]
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Detach a single tag from a document (the tag itself is not deleted).
     * `DELETE /accounts/{account_id}/documents/{document_id}/tags/{tag_id}`
     *
     * Note the asymmetry with {@see self::appendTags()}: attaching takes tag **names**,
     * detaching takes a tag **ID** — read it from {@see self::listTags()}. To remove the
     * tag from the workspace entirely, use {@see TagResource::delete()} instead.
     *
     * Request: no body.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'detached' => true,
     * ]
     * ```
     *
     * @return array<string, mixed>
     * @throws ValidationException when either identifier is empty
     */
    public function detachTag(string $documentId, string $tagId): array
    {
        $documentId = $this->pathSegment($documentId, 'document ID');
        $tagId = $this->pathSegment($tagId, 'tag ID');
        $response = $this->httpClient->delete(
            $this->accountPath("documents/{$documentId}/tags/{$tagId}")
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Create a document from a template.
     * `POST /accounts/{account_id}/templates/{template_id}/documents`
     *
     * Instantiates a prepared template — its field placements are reused, so no `entries`
     * are needed here. Each signer binds to one of the template's `roles`; read the role IDs
     * from {@see TemplateResource::get()}. Unlike {@see self::upload()} this creates the
     * document *and* dispatches its assignment in one call.
     *
     * Signer roles and field placements are configured in the Assinafy web app; a template
     * created through the API carries only the default `Editor` role.
     *
     * Request body:
     * ```
     * [
     *   'signers' => [
     *     [
     *       'role_id'              => 'role-id',  // required
     *       'id'                   => 'signer-id',   // required
     *       'verification_method'  => 'Email',
     *       'notification_methods' => ['Email'],
     *       'step'                 => 1,
     *     ],
     *   ],
     *   'editor_fields' => [
     *     ['field_id' => 'field-id', 'value' => 'Acme Inc.'],
     *   ],
     *   'name'       => 'agreement.pdf',
     *   'message'    => 'Please sign this contract',
     *   'expires_at' => '2026-12-31T23:59:59Z',
     *   'tags'       => [],
     * ]
     * ```
     *
     * Example response shape (SDK return; relationship expansion depends on document state):
     * ```php
     * [
     *     'resource' => 'document',
     *     'id' => 'document-id',
     *     'account_id' => 'account-id',
     *     'template_id' => 'template-id',
     *     'name' => 'agreement.pdf',
     *     'status' => 'metadata_ready',
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
     *     'assignment' => null,
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
     * @param array<int, array<string, mixed>> $signers each entry: `{ role_id, id,
     *     verification_method?, notification_methods?, step? }`
     * @param array<string, mixed>             $options optional `name`, `message`, `editor_fields`,
     *     `expires_at`, and `tags`
     * @return array<string, mixed> the created document
     * @throws ValidationException when `$signers` is empty or an entry is malformed
     */
    public function createFromTemplate(
        string $templateId,
        #[\SensitiveParameter] array $signers,
        #[\SensitiveParameter] array $options = []
    ): array {
        if ($signers === []) {
            throw new ValidationException('At least one template signer is required');
        }

        unset($options['signers']);
        $options = $this->normalizeTemplateOptions($options);
        $payload = array_merge(
            ['signers' => $this->normalizeTemplateSigners($signers, true)],
            $options
        );
        $templateId = $this->pathSegment($templateId, 'template ID');

        $response = $this->httpClient->post(
            $this->accountPath("templates/{$templateId}/documents"),
            $payload
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Estimate cost of creating a document from a template.
     * `POST /accounts/{account_id}/templates/{template_id}/documents/estimate-cost`
     *
     * Read-only: nothing is created and no credits are spent. Signer IDs are not needed —
     * price depends only on `role_id` plus the verification/notification channels, so you
     * can quote a workflow before the signers exist.
     *
     * Request body:
     * ```
     * [
     *   'signers' => [
     *     [
     *       'role_id'              => 'role-id',  // required
     *       'verification_method'  => 'Email',
     *       'notification_methods' => ['Email'],
     *     ],
     *   ],
     * ]
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'documents' => 1,
     *     'credits' => 0,
     *     'needs_extra_document' => false,
     *     'extra_document_cost' => 0,
     *     'total_credits' => 0,
     *     'breakdown' => [],
     *     'document_balance' => 66,
     *     'credit_balance' => 0,
     *     'has_sufficient_resources' => true,
     *     'blocking_reason' => null,
     *     'message' => null,
     * ]
     * ```
     *
     * Check `has_sufficient_resources` before creating; `blocking_reason` is a
     * machine-readable code and `message` is localised for display.
     *
     * @param array<int, array<string, mixed>> $signers each entry: `{ role_id,
     *     verification_method?, notification_methods? }`
     * @return array<string, mixed>
     * @throws ValidationException when `$signers` is empty or an entry is malformed
     */
    public function estimateCostFromTemplate(
        string $templateId,
        #[\SensitiveParameter] array $signers
    ): array {
        if ($signers === []) {
            throw new ValidationException('At least one template signer is required');
        }

        $templateId = $this->pathSegment($templateId, 'template ID');
        $response = $this->httpClient->post(
            $this->accountPath("templates/{$templateId}/documents/estimate-cost"),
            ['signers' => $this->normalizeTemplateSigners($signers, false)]
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Poll `GET /documents/{id}` until the document reaches a usable status.
     *
     * Client-side helper, not an API endpoint. Page rendering after {@see self::upload()}
     * is asynchronous, and an assignment cannot be created until it finishes — this bridges
     * that gap:
     * ```php
     * $document = $client->documents()->upload('/path/contract.pdf');
     * $ready    = $client->documents()->waitUntilReady($document['id']);
     * ```
     *
     * Returns as soon as `status` is one of {@see self::READY_STATUSES}, throws on any of
     * {@see self::FAILURE_STATUSES}, and otherwise sleeps `$pollIntervalSeconds` and retries.
     * The deadline is checked between calls; an in-flight request is bounded separately by
     * the transport timeout on {@see \Assinafy\SDK\Configuration}, so the worst-case wall
     * time is `$maxWaitSeconds` plus one request timeout.
     *
     * Response: the same payload as {@see self::get()}, once it is ready.
     *
     * Request: path parameters shown above; no request body.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'resource' => 'document',
     *     'id' => 'document-id',
     *     'account_id' => 'account-id',
     *     'template_id' => null,
     *     'name' => 'agreement.pdf',
     *     'status' => 'metadata_ready',
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
     *     'assignment' => null,
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
     * @param int $maxWaitSeconds      total budget before giving up
     * @param int $pollIntervalSeconds delay between polls; the last sleep is trimmed so the
     *     helper never overshoots the deadline
     * @return array<string, mixed> the ready document
     * @throws ValidationException when either interval is not a positive integer
     * @throws \RuntimeException on a terminal failure status or on timeout
     */
    public function waitUntilReady(string $documentId, int $maxWaitSeconds = 60, int $pollIntervalSeconds = 2): array
    {
        if ($maxWaitSeconds < 1 || $pollIntervalSeconds < 1) {
            throw new ValidationException('Wait and poll intervals must be positive integers');
        }

        $deadline = hrtime(true) + ($maxWaitSeconds * 1_000_000_000);

        while (hrtime(true) < $deadline) {
            $document = $this->get($documentId);
            if (hrtime(true) >= $deadline) {
                break;
            }
            $status = $document['status'] ?? 'unknown';

            if (in_array($status, self::READY_STATUSES, true)) {
                return $document;
            }

            if (in_array($status, self::FAILURE_STATUSES, true)) {
                throw new \RuntimeException("Document processing failed with status: {$status}");
            }

            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds > 0) {
                usleep(min(
                    $pollIntervalSeconds * 1_000_000,
                    (int) ceil($remainingNanoseconds / 1_000)
                ));
            }
        }

        throw new \RuntimeException("Timed out after {$maxWaitSeconds}s waiting for document to become ready");
    }

    /**
     * `true` once every signer has completed, including while certification is in progress.
     *
     * Client-side helper over {@see self::get()} — costs one API call. True for `ready`,
     * `certificating` and `certificated`, so it answers "is everyone done signing?" rather
     * than "is the certified PDF downloadable?". For the latter, compare `status` against
     * {@see self::STATUS_CERTIFICATED} directly.
     *
     * Request/Response: as {@see self::get()}; only `status` is read.
     *
     * @throws ValidationException when `$documentId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the document does not exist
     */
    public function isFullySigned(string $documentId): bool
    {
        return in_array(
            $this->get($documentId)['status'] ?? '',
            self::FULLY_SIGNED_STATUSES,
            true
        );
    }

    /**
     * Return a signed/total/percentage summary derived from the document's assignment.
     *
     * Client-side helper over {@see self::get()} — costs one API call, and derives the
     * counts from the assignment's `items`/`signers` rather than a dedicated endpoint.
     *
     * Request: as {@see self::get()}; only `status` and `assignment` are read.
     *
     * Response (computed locally, ready for a progress bar):
     * ```
     * ['signed' => 1, 'total' => 3, 'pending' => 2, 'percentage' => 33.33]
     * ```
     *
     * Once the document reaches a fully-signed status the counts are forced to 100% —
     * the API prunes assignment items after certification, so counting them would
     * otherwise regress to 0%.
     *
     * A document with no assignment yields `total => 0` and `percentage => 0.0`.
     *
     * @return array{signed:int,total:int,pending:int,percentage:float}
     * @throws ValidationException when `$documentId` is empty
     */
    public function getSigningProgress(string $documentId): array
    {
        $document = $this->get($documentId);
        $assignment = $document['assignment'] ?? null;

        if (in_array($document['status'] ?? null, self::FULLY_SIGNED_STATUSES, true)) {
            $signers = is_array($assignment['signers'] ?? null) ? $assignment['signers'] : [];
            $total = count($signers);

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

        $itemsBySigner = [];
        foreach ($items as $item) {
            $signerId = $item['signer']['id'] ?? null;
            if ($signerId === null) {
                continue;
            }
            $itemsBySigner[$signerId]['total'] = ($itemsBySigner[$signerId]['total'] ?? 0) + 1;
            $itemsBySigner[$signerId]['completed'] = ($itemsBySigner[$signerId]['completed'] ?? 0)
                + ((bool) ($item['completed'] ?? false) ? 1 : 0);
        }

        $signed = 0;
        foreach ($signers as $signer) {
            $id = $signer['id'] ?? null;
            $summary = $id !== null ? ($itemsBySigner[$id] ?? null) : null;
            if (
                ($signer['completed'] ?? false) === true
                || (is_array($summary) && $summary['total'] === $summary['completed'])
            ) {
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
     * Assert a file can be uploaded as a document or template: it must be a readable
     * PDF with a header and end marker, and not exceed the 25 MB API limit. Shared with
     * {@see TemplateResource::create()} so both upload paths enforce identical constraints.
     *
     * @throws ValidationException when the file is missing, unreadable, invalid, or too large
     */
    public static function assertUploadable(#[\SensitiveParameter] string $filePath): void
    {
        if (!is_file($filePath)) {
            throw new ValidationException('File not found', ['file_path' => $filePath]);
        }

        if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new ValidationException('Only PDF files are supported', ['file_path' => $filePath]);
        }

        if (!is_readable($filePath)) {
            throw new ValidationException('File is not readable', ['file_path' => $filePath]);
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new ValidationException('File is not readable', ['file_path' => $filePath]);
        }

        try {
            $metadata = fstat($handle);
            if ($metadata === false || $metadata['size'] <= 0) {
                throw new ValidationException('File is not a valid PDF', ['file_path' => $filePath]);
            }

            $size = $metadata['size'];
            if ($size > self::MAX_UPLOAD_BYTES) {
                throw new ValidationException('File size exceeds the 25 MB API limit', [
                    'file_size' => $size,
                    'max_size' => self::MAX_UPLOAD_BYTES,
                ]);
            }

            $header = fread($handle, min(1024, $size));
            if ($header === false || preg_match('/%PDF-\d\.\d/', $header) !== 1) {
                throw new ValidationException('File is not a valid PDF', ['file_path' => $filePath]);
            }

            if (fseek($handle, max(0, $size - 1024)) !== 0) {
                throw new ValidationException('File is not readable', ['file_path' => $filePath]);
            }

            $trailer = stream_get_contents($handle);
            if ($trailer === false || !str_contains($trailer, '%%EOF')) {
                throw new ValidationException('File is not a valid PDF', ['file_path' => $filePath]);
            }
        } finally {
            fclose($handle);
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
            self::ARTIFACT_PADES,
            self::ARTIFACT_BUNDLE,
        ];

        if (!in_array($artifact, $allowed, true)) {
            throw new ValidationException("Unknown artifact '{$artifact}'", ['allowed' => $allowed]);
        }
    }

    /**
     * @param array<int, mixed> $signers
     * @return array<int, array<string, mixed>>
     */
    private function normalizeTemplateSigners(
        #[\SensitiveParameter] array $signers,
        bool $requireSignerId
    ): array {
        $normalized = [];
        foreach ($signers as $signer) {
            if (!is_array($signer)) {
                throw new ValidationException('Each template signer must be an object');
            }

            $roleId = $signer['role_id'] ?? null;
            if (!is_string($roleId) || trim($roleId) === '') {
                throw new ValidationException('Each template signer requires a role_id');
            }

            $signerId = $signer['id'] ?? null;
            if ($requireSignerId && (!is_string($signerId) || trim($signerId) === '')) {
                throw new ValidationException('Each template signer requires an id');
            }
            if ($signerId !== null && (!is_string($signerId) || trim($signerId) === '')) {
                throw new ValidationException('Template signer id must be a non-empty string');
            }

            if (array_key_exists('notification_methods', $signer)) {
                if (!is_array($signer['notification_methods'])) {
                    throw new ValidationException('Template signer notification methods must be an array');
                }
                $signer['notification_methods'] = array_values($signer['notification_methods']);
            }

            $normalized[] = $signer;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function normalizeTemplateOptions(#[\SensitiveParameter] array $options): array
    {
        foreach (['editor_fields', 'tags'] as $listKey) {
            if (!array_key_exists($listKey, $options)) {
                continue;
            }
            if (!is_array($options[$listKey])) {
                throw new ValidationException("Template document {$listKey} must be an array");
            }
            $options[$listKey] = array_values($options[$listKey]);
        }

        return $options;
    }

    /**
     * @param array<int, mixed> $tagNames
     */
    private function assertTagNames(array $tagNames, bool $allowEmpty): void
    {
        if (!$allowEmpty && $tagNames === []) {
            throw new ValidationException('At least one tag name is required');
        }

        foreach ($tagNames as $name) {
            if (!is_string($name) || trim($name) === '') {
                throw new ValidationException('Tag names must be non-empty strings');
            }
        }
    }
}
