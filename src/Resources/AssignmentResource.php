<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Assignments resource — every endpoint under `/documents/{document_id}/assignments`.
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class AssignmentResource extends AbstractResource
{
    public const METHOD_VIRTUAL = 'virtual';
    public const METHOD_COLLECT = 'collect';

    public const VERIFICATION_EMAIL = 'Email';
    public const VERIFICATION_WHATSAPP = 'Whatsapp';

    public const NOTIFICATION_EMAIL = 'Email';
    public const NOTIFICATION_WHATSAPP = 'Whatsapp';

    /**
     * Create an assignment (signature request).
     * `POST /documents/{document_id}/assignments`
     *
     * @param array<int, string|array<string, mixed>> $signers
     *     Either a list of signer IDs (strings) or a list of `{ id, verification_method?, notification_methods? }`
     *     objects. String IDs are normalized to `{ id }` objects before being sent.
     * @param array<string, mixed> $options
     *     Optional keys: `entries` (required for collect), `message`, `expires_at`, `copy_receivers`.
     */
    public function create(
        string $documentId,
        array $signers,
        string $method = self::METHOD_VIRTUAL,
        array $options = []
    ): array {
        $this->assertMethod($method);
        $this->assertSigners($signers);

        $payload = array_merge(
            [
                'method' => $method,
                'signers' => $this->normalizeSigners($signers),
            ],
            $options
        );

        $response = $this->httpClient->post("documents/{$documentId}/assignments", $payload);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List the assignments belonging to the configured account.
     * `GET /assignments`
     *
     * Despite living outside `/accounts/{account_id}`, this endpoint still needs an account
     * context, which it takes as an `accountId` query parameter. That parameter is NOT in the
     * published OpenAPI spec (which documents only `page` and `per-page`), but the API answers
     * `400 "Um contexto de conta é necessário e não foi fornecido."` without it. The SDK sends
     * it from {@see \Assinafy\SDK\Configuration}. Note the camelCase spelling — `account-id`
     * and `account_id` are both rejected.
     *
     * Response (full envelope + `pagination` lifted from the `X-Pagination-*` headers):
     * ```
     * [
     *   'status'  => 200,
     *   'message' => '',
     *   'data'    => [
     *     [
     *       'id'           => '103033c9d2cec233bf65eea04999',
     *       'sender_email' => 'sender@example.com',
     *       'method'       => 'virtual',
     *       'expires_at'   => null,
     *       'message'      => 'Please sign this contract',
     *       'signers'      => [
     *         [
     *           'id' => '19e6b92e7895332ed9708535d8c', 'full_name' => 'Jane Doe',
     *           'email' => 'jane@example.com', 'whatsapp_phone_number' => null,
     *           'has_accepted_terms' => false, 'completed' => false,
     *           'verification_method' => 'Email', 'notification_methods' => ['Email'],
     *           'step' => 1, 'notified' => true, 'notification_history' => [],
     *         ],
     *       ],
     *       'copy_receivers' => [],
     *       'items'          => [],
     *       'summary'        => [],
     *       'signing_urls'   => [['signer_id' => '19e6…', 'url' => 'https://app…/sign/…?email=…']],
     *     ],
     *   ],
     *   'pagination' => ['current_page' => 1, 'page_count' => 3, 'per_page' => 20, 'total_count' => 47],
     * ]
     * ```
     *
     * @param array<string, scalar> $filters extra query parameters merged over the defaults
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     */
    public function list(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $params = array_merge([
            'accountId' => $this->requireAccountId(),
            'page' => $page,
            'per-page' => $perPage,
        ], $filters);

        return $this->withPagination($this->httpClient->get('assignments', $params));
    }

    /**
     * Estimate the credit cost of creating an assignment, without creating it.
     * `POST /documents/{document_id}/assignments/estimate-cost`
     *
     * Signer IDs are NOT required here — cost depends only on the verification and
     * notification methods, so you can price a request before the signers exist:
     *
     * ```php
     * $client->assignments()->estimateCost($documentId, [
     *     ['verification_method' => 'Email', 'notification_methods' => ['Email']],
     * ]);
     * ```
     *
     * The document must not have started signing yet; otherwise the API answers
     * `400 "A atribuição não pode ser criada para um documento com status '…'"`.
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'documents'                => 1,
     *   'credits'                  => 0,
     *   'needs_extra_document'     => false,
     *   'extra_document_cost'      => 0,
     *   'total_credits'            => 0,
     *   'breakdown'                => [],
     *   'document_balance'         => 100,
     *   'credit_balance'           => 0,
     *   'has_sufficient_resources' => true,
     *   'blocking_reason'          => null,
     *   'message'                  => null,
     * ]
     * ```
     *
     * @param array<int, string|array<string, mixed>> $signers signer IDs or objects; entries
     *     may omit `id` and carry only `verification_method` / `notification_methods`
     * @param array<string, mixed> $options extra body fields forwarded verbatim
     * @return array<string, mixed>
     */
    public function estimateCost(
        string $documentId,
        array $signers,
        string $method = self::METHOD_VIRTUAL,
        array $options = []
    ): array {
        $this->assertMethod($method);

        $payload = array_merge(
            [
                'method' => $method,
                'signers' => $this->normalizeSigners($signers, false),
            ],
            $options
        );

        $response = $this->httpClient->post(
            "documents/{$documentId}/assignments/estimate-cost",
            $payload
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Resend the signing-notification to a single signer.
     * `PUT /documents/{document_id}/assignments/{assignment_id}/signers/{signer_id}/resend`
     */
    public function resend(string $documentId, string $assignmentId, string $signerId): array
    {
        $response = $this->httpClient->put(
            "documents/{$documentId}/assignments/{$assignmentId}/signers/{$signerId}/resend"
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Estimate the credit cost of resending a notification to one signer.
     * `POST /documents/{document_id}/assignments/{assignment_id}/signers/{signer_id}/estimate-resend-cost`
     */
    public function estimateResendCost(string $documentId, string $assignmentId, string $signerId): array
    {
        $response = $this->httpClient->post(
            "documents/{$documentId}/assignments/{$assignmentId}/signers/{$signerId}/estimate-resend-cost"
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Reset the expiration date of an assignment.
     * `PUT /documents/{document_id}/assignments/{assignment_id}/reset-expiration`
     */
    public function resetExpiration(string $documentId, string $assignmentId, string $expiresAt): array
    {
        $response = $this->httpClient->put(
            "documents/{$documentId}/assignments/{$assignmentId}/reset-expiration",
            ['expires_at' => $expiresAt]
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List the WhatsApp notification messages sent for an assignment, with the rendered
     * header/body/buttons exactly as the signer sees them.
     * `GET /documents/{document_id}/assignments/{assignment_id}/whatsapp-notifications`
     *
     * @return array<int, array<string, mixed>>
     */
    public function whatsappNotifications(string $documentId, string $assignmentId): array
    {
        $response = $this->httpClient->get(
            "documents/{$documentId}/assignments/{$assignmentId}/whatsapp-notifications"
        );

        return $this->extractData($response->getData() ?? []);
    }

    private function assertMethod(string $method): void
    {
        if (!in_array($method, [self::METHOD_VIRTUAL, self::METHOD_COLLECT], true)) {
            throw new ValidationException(
                "Invalid assignment method '{$method}'",
                ['allowed' => [self::METHOD_VIRTUAL, self::METHOD_COLLECT]]
            );
        }
    }

    /**
     * @param array<int, mixed> $signers
     */
    private function assertSigners(array $signers): void
    {
        if (empty($signers)) {
            throw new ValidationException('At least one signer is required', ['signers' => $signers]);
        }
    }

    /**
     * Accept either string signer IDs or full signer objects and produce the
     * `signers: [{ id, verification_method?, notification_methods?, step? }]` shape
     * documented by the API.
     *
     * @param array<int, mixed> $signers
     * @param bool $requireId whether every entry must resolve to a signer ID. True when
     *     creating an assignment (the API needs to know who signs). False for cost
     *     estimation, which is priced purely off the verification/notification methods —
     *     see {@see self::estimateCost()}.
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSigners(array $signers, bool $requireId = true): array
    {
        $normalized = [];

        foreach ($signers as $signer) {
            if (is_string($signer)) {
                $normalized[] = ['id' => $signer];
                continue;
            }

            if (is_array($signer)) {
                $id = $signer['id'] ?? $signer['signer_id'] ?? null;

                if ($id === null && $requireId) {
                    throw new ValidationException('Signer entry missing id', ['signer' => $signer]);
                }

                $entry = $id === null ? [] : ['id' => (string) $id];

                if (isset($signer['verification_method'])) {
                    $entry['verification_method'] = $signer['verification_method'];
                }

                if (isset($signer['notification_methods'])) {
                    $entry['notification_methods'] = $signer['notification_methods'];
                }

                if (isset($signer['step'])) {
                    $entry['step'] = $signer['step'];
                }

                // `role_id` is documented on the template→document endpoints rather than on
                // create-assignment, so the API most likely ignores it here. Forwarded anyway:
                // it is harmless if ignored, and dropping it would silently change behaviour
                // for anyone already passing it.
                if (isset($signer['role_id'])) {
                    $entry['role_id'] = $signer['role_id'];
                }

                $normalized[] = $entry;
                continue;
            }

            throw new ValidationException('Invalid signer entry', ['signer' => $signer]);
        }

        return $normalized;
    }
}
