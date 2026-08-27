<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Support\Iso8601;

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
    public const VERIFICATION_DIGITAL_CERTIFICATE = 'DigitalCertificate';

    public const VERIFICATION_METHODS = [
        self::VERIFICATION_EMAIL,
        self::VERIFICATION_WHATSAPP,
        self::VERIFICATION_DIGITAL_CERTIFICATE,
    ];

    public const NOTIFICATION_EMAIL = 'Email';
    public const NOTIFICATION_WHATSAPP = 'Whatsapp';

    public const NOTIFICATION_METHODS = [
        self::NOTIFICATION_EMAIL,
        self::NOTIFICATION_WHATSAPP,
    ];

    /**
     * Create an assignment (signature request).
     * `POST /documents/{document_id}/assignments`
     *
     * This is the call that actually notifies signers. The document must have finished
     * processing first — see {@see DocumentResource::waitUntilReady()}.
     *
     * `virtual` collects a click-to-sign consent from each signer. `collect` additionally
     * places named input fields on the pages, so it requires `entries`.
     *
     * Request body:
     * ```
     * [
     *   'method'  => 'virtual',                       // 'virtual' | 'collect'
     *   'signers' => [
     *     [
     *       'id'                   => '19e6b92e7895332ed9708535d8c',   // required
     *       'verification_method'  => 'Email',        // Email | Whatsapp | DigitalCertificate
     *       'notification_methods' => ['Email'],      // exactly one: Email | Whatsapp
     *       'step'                 => 1,              // 1-based signing order
     *     ],
     *   ],
     *   // 'collect' only — where each field is stamped on the page:
     *   'entries' => [
     *     [
     *       'page_id' => '1a0439be3231e685cee68093a12',
     *       'fields'  => [
     *         [
     *           'signer_id'        => '19e6b92e7895332ed9708535d8c',
     *           'field_id'         => '102d25a48bec03ebcf3b5f651998',
     *           'display_settings' => [
     *             'left' => 120.0, 'top' => 400.0, 'width' => 180.0, 'height' => 32.0,
     *             'fontSize' => 12.0, 'fontFamily' => 'Helvetica',
     *             'backgroundColor' => '#ffffff',
     *           ],
     *         ],
     *       ],
     *     ],
     *   ],
     *   'message'        => 'Please sign this contract',
     *   'expires_at'     => '2026-12-31T23:59:59Z',   // ISO 8601, must carry Z or ±HH:MM
     *   'copy_receivers' => ['103033c9cd9426bbbb78eccd2c79'],  // signer IDs CC'd on completion
     * ]
     * ```
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'id'           => '103033c9d2cec233bf65eea04999',
     *   'sender_email' => 'sender@example.com',
     *   'method'       => 'virtual',
     *   'expires_at'   => null,
     *   'message'      => 'Please sign this contract',
     *   'signers'      => [
     *     [
     *       'id' => '19e6b92e7895332ed9708535d8c', 'full_name' => 'Jane Doe',
     *       'email' => 'jane@example.com', 'whatsapp_phone_number' => null,
     *       'has_accepted_terms' => false, 'completed' => false, 'notified' => true,
     *       'verification_method' => 'Email', 'notification_methods' => ['Email'],
     *       'step' => 1, 'notification_history' => [],
     *     ],
     *   ],
     *   'copy_receivers' => [],
     *   'items'          => [
     *     [
     *       'id' => '103033c9d33326458deb74fc3052', 'page' => null, 'value' => null,
     *       'completed' => false, 'display_settings' => [],
     *       'signer' => ['id' => '19e6…', 'full_name' => 'Jane Doe', 'email' => 'jane@example.com'],
     *       'field'  => ['id' => '102d…', 'name' => 'Virtual', 'type' => 'virtual'],
     *     ],
     *   ],
     *   'summary'      => [],
     *   'signing_urls' => [
     *     ['signer_id' => '19e6b92e7895332ed9708535d8c', 'url' => 'https://app…/sign/…'],
     *   ],
     * ]
     * ```
     *
     * The `signing_urls` entries are what you hand to each signer. They do **not** contain
     * the signer access code — that is delivered separately by the notification channel.
     *
     * @param array<int, string|array<string, mixed>> $signers
     *     Either a list of signer IDs (strings) or a list of `{ id, verification_method?,
     *     notification_methods?, step? }` objects. String IDs are normalized to `{ id }`.
     * @param array<string, mixed> $options
     *     Optional keys: `entries` (required for collect), `message`, `expires_at`, `copy_receivers`.
     * @return array<string, mixed> the created assignment
     * @throws ValidationException on an unknown method, an empty signer list, a `collect`
     *     assignment without entries, a malformed `expires_at`, non-contiguous steps, or a
     *     digital-certificate signer sharing a step with another signer
     */
    public function create(
        string $documentId,
        #[\SensitiveParameter] array $signers,
        string $method = self::METHOD_VIRTUAL,
        #[\SensitiveParameter] array $options = []
    ): array {
        $this->assertMethod($method);
        $this->assertSigners($signers);
        $options = $this->normalizeListOptions($options);

        if ($method === self::METHOD_COLLECT && ($options['entries'] ?? []) === []) {
            throw new ValidationException('Collect assignments require field entries');
        }
        if (isset($options['expires_at'])) {
            if (!is_string($options['expires_at'])) {
                throw new ValidationException('Expiration must be an ISO 8601 date-time');
            }
            $this->assertDateTime($options['expires_at']);
        }

        unset($options['method'], $options['signers']);
        $payload = array_merge(
            [
                'method' => $method,
                'signers' => $this->normalizeSigners($signers),
            ],
            $options
        );

        $documentId = $this->pathSegment($documentId, 'document ID');
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
        $params = $this->paginationQuery($page, $perPage, $filters);
        $params = array_merge($params, [
            'accountId' => $this->requireAccountId(),
        ]);

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
        #[\SensitiveParameter] array $signers,
        string $method = self::METHOD_VIRTUAL,
        #[\SensitiveParameter] array $options = []
    ): array {
        $this->assertMethod($method);
        $options = $this->normalizeListOptions($options);
        if ($method === self::METHOD_VIRTUAL) {
            $this->assertSigners($signers);
        } elseif (($options['entries'] ?? []) === []) {
            throw new ValidationException('Collect estimates require field entries');
        }

        unset($options['method'], $options['signers']);
        $payload = array_merge(
            [
                'method' => $method,
                'signers' => $this->normalizeSigners($signers, false),
            ],
            $options
        );

        $documentId = $this->pathSegment($documentId, 'document ID');
        $response = $this->httpClient->post(
            "documents/{$documentId}/assignments/estimate-cost",
            $payload
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Resend the signing-notification to a single signer.
     * `PUT /documents/{document_id}/assignments/{assignment_id}/signers/{signer_id}/resend`
     *
     * Sends over the signer's configured `notification_methods` channel. A resend may
     * consume credits — price it first with {@see self::estimateResendCost()}.
     *
     * Request: no body; everything is addressed through the path.
     *
     * Response (unwrapped `data` — the refreshed signer entry, in the same shape the
     * assignment's `signers` array uses, with the delivery recorded in
     * `notification_history`):
     * ```
     * [
     *   'id'                    => '19e6b92e7895332ed9708535d8c',
     *   'full_name'             => 'Jane Doe',
     *   'email'                 => 'jane@example.com',
     *   'whatsapp_phone_number' => null,
     *   'has_accepted_terms'    => false,
     *   'completed'             => false,
     *   'notified'              => true,
     *   'verification_method'   => 'Email',
     *   'notification_methods'  => ['Email'],
     *   'step'                  => 1,
     *   'notification_history'  => [ … one entry per delivery attempt … ],
     * ]
     * ```
     *
     * @return array<string, mixed>
     * @throws ValidationException when any identifier is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException when the signer has already completed
     */
    public function resend(string $documentId, string $assignmentId, string $signerId): array
    {
        $documentId = $this->pathSegment($documentId, 'document ID');
        $assignmentId = $this->pathSegment($assignmentId, 'assignment ID');
        $signerId = $this->pathSegment($signerId, 'signer ID');
        $response = $this->httpClient->put(
            "documents/{$documentId}/assignments/{$assignmentId}/signers/{$signerId}/resend"
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Estimate the credit cost of resending a notification to one signer.
     * `POST /documents/{document_id}/assignments/{assignment_id}/signers/{signer_id}/estimate-resend-cost`
     *
     * Read-only — nothing is sent to the signer and no credits are spent. Check
     * `has_sufficient_resources` before calling {@see self::resend()}.
     *
     * Request: no body; everything is addressed through the path.
     *
     * Response (unwrapped `data`) — the same estimate shape as {@see self::estimateCost()}:
     * ```
     * [
     *   'documents'                => 0,
     *   'credits'                  => 1,
     *   'needs_extra_document'     => false,
     *   'extra_document_cost'      => 0,
     *   'total_credits'            => 1,
     *   'breakdown'                => [],
     *   'document_balance'         => 100,
     *   'credit_balance'           => 25,
     *   'has_sufficient_resources' => true,
     *   'blocking_reason'          => null,
     *   'message'                  => null,
     * ]
     * ```
     *
     * When the workspace cannot afford it, `has_sufficient_resources` is false and
     * `blocking_reason` carries a machine-readable code (e.g. `'InsufficientDocuments'`)
     * alongside a localised `message`.
     *
     * @return array<string, mixed>
     * @throws ValidationException when any identifier is empty
     */
    public function estimateResendCost(string $documentId, string $assignmentId, string $signerId): array
    {
        $documentId = $this->pathSegment($documentId, 'document ID');
        $assignmentId = $this->pathSegment($assignmentId, 'assignment ID');
        $signerId = $this->pathSegment($signerId, 'signer ID');
        $response = $this->httpClient->post(
            "documents/{$documentId}/assignments/{$assignmentId}/signers/{$signerId}/estimate-resend-cost"
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Reset the expiration date of an assignment.
     * `PUT /documents/{document_id}/assignments/{assignment_id}/reset-expiration`
     *
     * Use this to revive an assignment whose deadline has passed, or to extend one still
     * running. `$expiresAt` is validated locally before the request is sent, so a malformed
     * value never reaches the API.
     *
     * Request body:
     * ```
     * ['expires_at' => '2026-12-31T23:59:59Z']  // ISO 8601; a Z or ±HH:MM offset is mandatory
     * ```
     *
     * Response (unwrapped `data` — the assignment with its new deadline):
     * ```
     * [
     *   'id'         => '103033c9d2cec233bf65eea04999',
     *   'method'     => 'virtual',
     *   'expires_at' => '2026-12-31T23:59:59Z',
     *   'message'    => 'Please sign this contract',
     *   'signers'    => [ … ],
     * ]
     * ```
     *
     * @param string $expiresAt ISO 8601 date-time ending in `Z` or an explicit `±HH:MM` offset
     * @return array<string, mixed>
     * @throws ValidationException when `$expiresAt` is not a valid ISO 8601 date-time
     */
    public function resetExpiration(string $documentId, string $assignmentId, string $expiresAt): array
    {
        $this->assertDateTime($expiresAt);
        $documentId = $this->pathSegment($documentId, 'document ID');
        $assignmentId = $this->pathSegment($assignmentId, 'assignment ID');
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
     * Useful for supporting a signer who says the message never arrived: `status` shows how
     * far the delivery got. Returns an empty array for assignments notified only by email.
     *
     * Request: no parameters.
     *
     * Response (unwrapped `data`) — a list with one entry per message sent, each carrying the
     * delivery state plus the rendered `header`, `body`, and `buttons` as the signer sees
     * them. WhatsApp availability is plan-dependent: on a workspace without it, creating such
     * an assignment is refused with `403 "A notificação via WhatsApp não está disponível para
     * o seu plano atual."`, so this list stays empty.
     *
     * @return array<int, array<string, mixed>> empty when nothing was sent over WhatsApp
     * @throws ValidationException when any identifier is empty
     */
    public function whatsappNotifications(string $documentId, string $assignmentId): array
    {
        $documentId = $this->pathSegment($documentId, 'document ID');
        $assignmentId = $this->pathSegment($assignmentId, 'assignment ID');
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
    private function normalizeSigners(
        #[\SensitiveParameter] array $signers,
        bool $requireId = true
    ): array {
        $normalized = [];

        foreach ($signers as $signer) {
            if (is_string($signer)) {
                if (trim($signer) === '') {
                    throw new ValidationException('Signer ID cannot be empty');
                }
                $normalized[] = ['id' => $signer];
                continue;
            }

            if (is_array($signer)) {
                $id = $signer['id'] ?? $signer['signer_id'] ?? null;

                if ($id === null && $requireId) {
                    throw new ValidationException('Signer entry missing id', ['signer' => $signer]);
                }

                if ($id !== null && (!is_string($id) || trim($id) === '')) {
                    throw new ValidationException('Signer ID must be a non-empty string', [
                        'signer' => $signer,
                    ]);
                }

                $entry = $id === null ? [] : ['id' => $id];

                if (isset($signer['verification_method'])) {
                    if (!in_array($signer['verification_method'], self::VERIFICATION_METHODS, true)) {
                        throw new ValidationException('Unknown signer verification method', [
                            'verification_method' => $signer['verification_method'],
                        ]);
                    }
                    $entry['verification_method'] = $signer['verification_method'];
                }

                if (isset($signer['notification_methods'])) {
                    $methods = $signer['notification_methods'];
                    if (!is_array($methods)) {
                        throw new ValidationException('Signer notification methods must be an array');
                    }
                    if (count($methods) > 1) {
                        throw new ValidationException('Only one signer notification method is allowed');
                    }
                    foreach ($methods as $notificationMethod) {
                        if (!in_array($notificationMethod, self::NOTIFICATION_METHODS, true)) {
                            throw new ValidationException('Unknown signer notification method', [
                                'notification_method' => $notificationMethod,
                            ]);
                        }
                    }
                    $verification = $entry['verification_method'] ?? null;
                    $notification = reset($methods);
                    if (
                        is_string($verification)
                        && is_string($notification)
                        && $verification !== self::VERIFICATION_DIGITAL_CERTIFICATE
                        && $verification !== $notification
                    ) {
                        throw new ValidationException(
                            'Signer verification and notification methods must match'
                        );
                    }
                    $entry['notification_methods'] = array_values($methods);
                }

                if (isset($signer['step'])) {
                    if (!is_int($signer['step']) || $signer['step'] < 1) {
                        throw new ValidationException('Signer step must be a positive integer');
                    }
                    $entry['step'] = $signer['step'];
                }

                // Preserve the optional role reference accepted by existing integrations.
                if (isset($signer['role_id'])) {
                    $entry['role_id'] = $signer['role_id'];
                }

                $normalized[] = $entry;
                continue;
            }

            throw new ValidationException('Invalid signer entry', ['signer' => $signer]);
        }

        $this->assertSequentialSteps($normalized);
        if ($requireId) {
            $this->assertDigitalCertificateSteps($normalized);
        }

        return $normalized;
    }

    /**
     * Keep schema-defined list properties encoded as JSON arrays even when callers
     * supply non-contiguous PHP array keys.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function normalizeListOptions(#[\SensitiveParameter] array $options): array
    {
        if (array_key_exists('copy_receivers', $options)) {
            if (!is_array($options['copy_receivers'])) {
                throw new ValidationException('Copy receivers must be an array of signer IDs');
            }
            foreach ($options['copy_receivers'] as $signerId) {
                if (!is_string($signerId) || trim($signerId) === '') {
                    throw new ValidationException('Copy receiver IDs must be non-empty strings');
                }
            }
            $options['copy_receivers'] = array_values($options['copy_receivers']);
        }

        if (array_key_exists('entries', $options)) {
            if (!is_array($options['entries'])) {
                throw new ValidationException('Assignment entries must be an array');
            }

            $entries = [];
            foreach ($options['entries'] as $entry) {
                if (!is_array($entry)) {
                    throw new ValidationException('Each assignment entry must be an object');
                }
                if (array_key_exists('fields', $entry)) {
                    if (!is_array($entry['fields'])) {
                        throw new ValidationException('Assignment entry fields must be an array');
                    }
                    $entry['fields'] = array_values($entry['fields']);
                }
                $entries[] = $entry;
            }
            $options['entries'] = $entries;
        }

        return $options;
    }

    /**
     * @param array<int, array<string, mixed>> $signers
     */
    private function assertSequentialSteps(array $signers): void
    {
        $steps = array_column($signers, 'step');
        if ($steps === []) {
            return;
        }

        if (count($steps) !== count($signers)) {
            throw new ValidationException('Either every signer must define a step or none may');
        }

        $unique = array_values(array_unique($steps));
        sort($unique);
        foreach ($unique as $index => $step) {
            if ($step !== $index + 1) {
                throw new ValidationException('Signer steps must be contiguous and start at 1');
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $signers
     */
    private function assertDigitalCertificateSteps(array $signers): void
    {
        $stepCounts = [];
        foreach ($signers as $signer) {
            $step = (int) ($signer['step'] ?? 1);
            $stepCounts[$step] = ($stepCounts[$step] ?? 0) + 1;
        }

        foreach ($signers as $signer) {
            $step = (int) ($signer['step'] ?? 1);
            if (
                ($signer['verification_method'] ?? null) === self::VERIFICATION_DIGITAL_CERTIFICATE
                && $stepCounts[$step] > 1
            ) {
                throw new ValidationException(
                    'A digital-certificate signer must be alone in its signing step'
                );
            }
        }
    }

    private function assertDateTime(string $value): void
    {
        $reason = Iso8601::reasonInvalid($value);
        if ($reason !== null) {
            throw new ValidationException('Expiration ' . $reason, ['expires_at' => $value]);
        }
    }
}
