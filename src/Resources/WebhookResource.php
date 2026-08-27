<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Webhooks resource — the workspace's single webhook subscription plus the
 * delivery-history (dispatch) endpoints.
 *
 * The subscription is an upsert: {@see register()} (`PUT`) creates or replaces it,
 * {@see get()} returns the current configuration, and {@see deactivate()} pauses
 * delivery via the dedicated `inactivate` route. There is no `DELETE` route — the
 * way to stop receiving events is to inactivate the subscription.
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class WebhookResource extends AbstractResource
{
    public const EVENT_DOCUMENT_UPLOADED = 'document_uploaded';
    public const EVENT_DOCUMENT_METADATA_READY = 'document_metadata_ready';
    public const EVENT_DOCUMENT_PREPARED = 'document_prepared';
    public const EVENT_ASSIGNMENT_CREATED = 'assignment_created';
    public const EVENT_SIGNATURE_REQUESTED = 'signature_requested';
    public const EVENT_DOCUMENT_READY = 'document_ready';
    public const EVENT_SIGNER_CREATED = 'signer_created';
    public const EVENT_SIGNER_EMAIL_VERIFIED = 'signer_email_verified';
    public const EVENT_SIGNER_WHATSAPP_VERIFIED = 'signer_whatsapp_verified';
    public const EVENT_SIGNER_DATA_CONFIRMED = 'signer_data_confirmed';
    public const EVENT_SIGNER_SIGNED = 'signer_signed_document';
    public const EVENT_SIGNER_VIEWED = 'signer_viewed_document';
    public const EVENT_SIGNER_REJECTED = 'signer_rejected_document';
    public const EVENT_USER_REJECTED = 'user_rejected_document';
    public const EVENT_DOCUMENT_PROCESSING_FAILED = 'document_processing_failed';
    public const EVENT_TEMPLATE_CREATED = 'template_created';
    public const EVENT_TEMPLATE_PROCESSED = 'template_processed';
    public const EVENT_TEMPLATE_PROCESSING_FAILED = 'template_processing_failed';

    /** Sensible default subscription covering the common document lifecycle events. */
    public const DEFAULT_EVENTS = [
        self::EVENT_DOCUMENT_READY,
        self::EVENT_SIGNER_SIGNED,
        self::EVENT_SIGNER_REJECTED,
        self::EVENT_DOCUMENT_PROCESSING_FAILED,
    ];

    /**
     * Register or replace the workspace webhook subscription.
     * `PUT /accounts/{account_id}/webhooks/subscriptions`
     *
     * A workspace has exactly one subscription, and this call is an upsert — it creates the
     * subscription or replaces it wholesale. All four body fields are mandatory, so a
     * partial update is not possible; read the current values with {@see self::get()} first
     * if you only mean to change one.
     *
     * `email` is the address the platform notifies when deliveries start failing; it is not
     * a delivery target.
     *
     * Request body:
     * ```
     * [
     *   'url'       => 'https://example.com/hooks/assinafy',  // required
     *   'email'     => 'ops@example.com',                     // required
     *   'events'    => ['document_ready', 'signer_signed_document'],  // required
     *   'is_active' => true,                                  // required
     * ]
     * ```
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'events'     => ['document_ready', 'signer_signed_document'],
     *   'is_active'  => true,
     *   'url'        => 'https://example.com/hooks/assinafy',
     *   'email'      => 'ops@example.com',
     *   'updated_at' => '2026-08-27T17:55:12Z',
     * ]
     * ```
     *
     * Deliveries are **unsigned** — there is no secret to register and no signature header.
     * Secure the endpoint as described by {@see \Assinafy\SDK\Support\WebhookEventParser}.
     *
     * @param string            $url    absolute HTTP(S) endpoint to POST deliveries to
     * @param string            $email  address alerted when delivery fails
     * @param array<int, mixed> $events event type IDs; when empty, {@see DEFAULT_EVENTS} is sent
     * @param bool              $isActive whether to start delivering immediately
     * @return array<string, mixed> the stored subscription
     * @throws ValidationException on a non-absolute URL, a malformed email, or a
     *     non-string event
     */
    public function register(
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] string $email,
        array $events = [],
        bool $isActive = true
    ): array {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array($scheme, ['http', 'https'], true)
            || parse_url($url, PHP_URL_HOST) === null
        ) {
            throw new ValidationException(
                'Webhook URL must be an absolute HTTP or HTTPS URL'
            );
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Webhook email must be valid', ['email' => $email]);
        }

        foreach ($events as $event) {
            if (!is_string($event) || trim($event) === '') {
                throw new ValidationException('Webhook events must be non-empty strings', [
                    'event' => $event,
                ]);
            }
        }

        $payload = [
            'url' => $url,
            'email' => $email,
            'events' => $events !== [] ? array_values($events) : self::DEFAULT_EVENTS,
            'is_active' => $isActive,
        ];

        $response = $this->httpClient->put(
            $this->accountPath('webhooks/subscriptions'),
            $payload
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Get the current webhook subscription (or null if none has ever been configured).
     * `GET /accounts/{account_id}/webhooks/subscriptions`
     *
     * Request: no parameters.
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'events'     => ['document_ready', 'signer_signed_document', 'signer_rejected_document'],
     *   'is_active'  => false,
     *   'url'        => 'https://example.com/hooks/assinafy',
     *   'email'      => 'ops@example.com',
     *   'updated_at' => '2026-08-27T17:55:12Z',
     * ]
     * ```
     *
     * Returns `null` — not an empty array — when the workspace has never configured one, so
     * `if ($client->webhooks()->get() === null)` is the way to test for absence.
     *
     * @return array<string, mixed>|null the subscription, or null when none exists
     */
    public function get(): ?array
    {
        $response = $this->httpClient->get($this->accountPath('webhooks/subscriptions'));

        $data = $this->extractData($response->getData() ?? []);

        return $data === [] ? null : $data;
    }

    /**
     * Disable delivery without losing the subscription configuration.
     * `PUT /accounts/{account_id}/webhooks/inactivate`
     *
     * The URL / email / events stay on file so the subscription can be re-enabled
     * later with {@see activate()} without re-supplying them. This is the only way to stop
     * deliveries — the API has no `DELETE` route for subscriptions.
     *
     * Request: no body.
     *
     * Response (unwrapped `data`) — the subscription with `is_active` flipped:
     * ```
     * [
     *   'events'     => ['document_ready', 'signer_signed_document'],
     *   'is_active'  => false,
     *   'url'        => 'https://example.com/hooks/assinafy',
     *   'email'      => 'ops@example.com',
     *   'updated_at' => '2026-08-27T17:55:12Z',
     * ]
     * ```
     *
     * @return array<string, mixed>
     */
    public function deactivate(): array
    {
        $response = $this->httpClient->put($this->accountPath('webhooks/inactivate'));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Re-enable delivery on the existing subscription.
     *
     * Client-side helper, not a single endpoint. There is no dedicated "activate" route, so
     * this reads the stored subscription with {@see self::get()} and re-sends its URL /
     * email / events through {@see self::register()} with `is_active = true` — two requests.
     *
     * Request/Response: as {@see self::get()} then {@see self::register()}.
     *
     * Response (unwrapped `data` from the register call):
     * ```
     * [
     *   'events'     => ['document_ready', 'signer_signed_document'],
     *   'is_active'  => true,
     *   'url'        => 'https://example.com/hooks/assinafy',
     *   'email'      => 'ops@example.com',
     *   'updated_at' => '2026-08-27T18:02:44Z',
     * ]
     * ```
     *
     * @return array<string, mixed> the reactivated subscription
     * @throws \RuntimeException when no subscription has been configured yet
     */
    public function activate(): array
    {
        $current = $this->get();

        if ($current === null || ($current['url'] ?? null) === null) {
            throw new \RuntimeException('No webhook subscription is configured — call register() first.');
        }

        return $this->register(
            (string) $current['url'],
            (string) ($current['email'] ?? ''),
            is_array($current['events'] ?? null) && $current['events'] !== []
                ? $current['events']
                : self::DEFAULT_EVENTS,
            true
        );
    }

    /**
     * List the available webhook event types and their descriptions.
     * `GET /webhooks/event-types` (not account-scoped).
     *
     * The authoritative vocabulary for the `events` array of {@see self::register()}. The
     * `EVENT_*` constants mirror these IDs; prefer this call over hard-coding if you render
     * a picker, since the platform can add types.
     *
     * Request: no parameters.
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   ['id' => 'document_uploaded',
     *    'description' => 'Triggered when the User has uploaded a Document'],
     *   ['id' => 'document_metadata_ready',
     *    'description' => 'Triggered when the document is ready to be prepared…'],
     *   ['id' => 'assignment_created',
     *    'description' => 'Triggered when the User created an assignment for a Document…'],
     *   ['id' => 'document_ready',
     *    'description' => 'Triggered when the last Signer of the assignment signs…'],
     *   // …one entry per EVENT_* constant on this class
     * ]
     * ```
     *
     * @return array<int, array{id: string, description: string}>
     */
    public function eventTypes(): array
    {
        $response = $this->httpClient->get('webhooks/event-types');

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List the webhook delivery history (dispatches) for the workspace.
     * `GET /accounts/{account_id}/webhooks`
     *
     * The delivery log — what was sent, where, and whether it landed. Each entry embeds the
     * exact `payload` that was POSTed, so a failed delivery can be replayed or inspected
     * without reproducing the original event.
     *
     * Request (query string): `event`, `delivered` (`true`/`false`), `from` and `to` (Unix
     * timestamps), `page`, `per-page`.
     *
     * Response (full envelope — pagination lifted from the `X-Pagination-*` headers):
     * ```
     * [
     *   'status'  => 200,
     *   'message' => '',
     *   'data'    => [
     *     [
     *       'id'          => '10413df9999b0bbd9e53220370c0',
     *       'event'       => 'signature_requested',
     *       'activity_id' => 22970,
     *       'endpoint'    => 'https://example.com/hooks/assinafy',
     *       'payload'     => [
     *         'id' => 22970, 'event' => 'signature_requested',
     *         'account_id' => '64f000000000000000000001',
     *         'object'  => ['id' => '1041…', 'type' => 'Document',
     *                       'status' => 'pending_signature', 'assignment' => [ … ]],
     *         'payload' => [ … ],
     *       ],
     *       'delivered'     => false,
     *       'http_status'   => 404,
     *       'response_body' => '{"success":false,"error":{"message":"…"}}',
     *       'error'         => 'Client error: `POST https://example.com/hooks/assinafy` …',
     *       'created_at'    => '2026-08-20T15:31:08Z',
     *       'updated_at'    => '2026-08-20T15:31:08Z',
     *     ],
     *   ],
     *   'pagination' => ['current_page' => 1, 'page_count' => 4, 'per_page' => 20, 'total_count' => 76],
     * ]
     * ```
     *
     * `http_status`, `response_body`, and `error` are the diagnostics for a failed delivery —
     * they record what your endpoint actually answered. On a successful delivery `delivered`
     * is true and `error` is empty.
     *
     * @param array<string, scalar> $filters optional `event`, `delivered`, `from`, `to`,
     *     `page`, `per-page`
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     *     full envelope with pagination lifted from response headers
     * @throws ValidationException when `page`/`per-page` are not integers, or `per-page` is
     *     outside 1–100
     */
    public function dispatches(array $filters = []): array
    {
        $page = $filters['page'] ?? 1;
        $perPage = $filters['per-page'] ?? 20;
        if (!is_int($page) || !is_int($perPage)) {
            throw new ValidationException('Webhook page and per-page filters must be integers');
        }
        unset($filters['page'], $filters['per-page']);

        return $this->withPagination($this->httpClient->get(
            $this->accountPath('webhooks'),
            $this->paginationQuery($page, $perPage, $filters)
        ));
    }

    /**
     * Manually retry a single webhook dispatch.
     * `POST /accounts/{account_id}/webhooks/{dispatch_id}/retry`
     *
     * Re-sends the original payload byte-for-byte to the currently configured endpoint — use
     * it after fixing an outage. `$dispatchId` is the `id` of an entry from
     * {@see self::dispatches()}.
     *
     * A retry creates a **new** dispatch record rather than mutating the old one, so the
     * history keeps both attempts.
     *
     * Request: no body.
     *
     * Response (unwrapped `data`) — the newly created dispatch entry, in the same shape
     * {@see self::dispatches()} returns:
     * ```
     * [
     *   'id'            => '10413dfa1187c2ad0f7745e19b32',
     *   'event'         => 'signature_requested',
     *   'activity_id'   => 22970,
     *   'endpoint'      => 'https://example.com/hooks/assinafy',
     *   'payload'       => [ … the original payload, unchanged … ],
     *   'delivered'     => true,
     *   'http_status'   => 200,
     *   'response_body' => '',
     *   'error'         => '',
     *   'created_at'    => '2026-08-27T18:10:31Z',
     *   'updated_at'    => '2026-08-27T18:10:31Z',
     * ]
     * ```
     *
     * @return array<string, mixed> the new dispatch record
     * @throws ValidationException when `$dispatchId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the dispatch does not exist
     */
    public function retryDispatch(string $dispatchId): array
    {
        $dispatchId = $this->pathSegment($dispatchId, 'dispatch ID');
        $response = $this->httpClient->post($this->accountPath("webhooks/{$dispatchId}/retry"));

        return $this->extractData($response->getData() ?? []);
    }
}
