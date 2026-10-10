<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\NetworkException;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Http\Response;

/**
 * Webhooks resource — the workspace's webhook endpoints, the legacy single-subscription
 * routes, the event catalog and the delivery history (dispatches).
 *
 * An account can register 1 endpoint, or up to 3 on paid plans. Each endpoint has its own
 * URL, events, active flag and optional Standard Webhooks signing; every active endpoint
 * subscribed to an event receives it independently. Manage them with
 * {@see listEndpoints()}, {@see createEndpoint()}, {@see getEndpoint()},
 * {@see updateEndpoint()} and {@see deleteEndpoint()}; read or rotate the signing secret with
 * {@see endpointSecret()} and {@see rotateEndpointSecret()}, and check deliveries with
 * {@see \Assinafy\SDK\Support\WebhookEventParser::verifySignature()}.
 *
 * The subscription methods ({@see register()}, {@see get()}, {@see deactivate()},
 * {@see activate()}) keep working and act on the account's oldest endpoint.
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
     * Register or replace the workspace webhook subscription (the oldest endpoint).
     * `PUT /accounts/{account_id}/webhooks/subscriptions`
     *
     * This call is an upsert on the account's oldest endpoint — it creates it or replaces it
     * wholesale. Use {@see self::createEndpoint()} to add further endpoints or to enable
     * signing. All four body fields are mandatory, so a
     * partial update is not possible; read the current values with {@see self::get()} first
     * if you only mean to change one.
     *
     * `email` is the address the platform notifies when deliveries start failing; it is not
     * a delivery target. OAuth scope: `webhooks:write`.
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
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'events' => ['document_ready', 'signer_signed_document'],
     *     'is_active' => true,
     *     'url' => 'https://example.com/hooks/assinafy',
     *     'email' => 'ops@example.com',
     *     'updated_at' => '2023-05-10T14:58:24Z',
     * ]
     * ```
     *
     * The subscription body has no signing field; enable signing on the endpoint with
     * {@see self::updateEndpoint()}.
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
        $this->assertUrl($url);
        $this->assertEmail($email);
        $this->assertEvents($events);

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
     * Get the current webhook subscription — the oldest endpoint (or null if none exists).
     * `GET /accounts/{account_id}/webhooks/subscriptions`
     *
     * Request: no parameters. OAuth scope: `account:read`.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'events' => ['document_ready', 'document_prepared'],
     *     'is_active' => true,
     *     'url' => 'https://example.com/hooks/assinafy',
     *     'email' => 'person@example.com',
     *     'updated_at' => '2023-05-10T14:58:24Z',
     * ]
     * ```
     *
     * Returns `null` — not an empty array — when the workspace has never configured one,
     * although a never-configured workspace may instead answer with an inactive subscription
     * carrying an empty `url`; {@see self::activate()} rejects both shapes.
     *
     * @return array<string, mixed>|null the subscription, or null when none exists; a new
     *     account can instead return an inactive subscription with an empty `url`
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
     * Request: no body. OAuth scope: `webhooks:write`.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'events' => ['document_ready', 'document_prepared'],
     *     'is_active' => false,
     *     'url' => 'https://example.com/hooks/assinafy',
     *     'email' => 'person@example.com',
     *     'updated_at' => '2023-05-10T14:58:24Z',
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
     * List the account's webhook endpoints, oldest first.
     * `GET /accounts/{account_id}/webhooks/endpoints`
     *
     * Request: no parameters. OAuth scope: `account:read`.
     *
     * Example response (SDK return; a direct list, no pagination):
     * ```php
     * [
     *     [
     *         'id' => '65f1c2a9b3e4d5f60718293a4b5c6d7e',
     *         'name' => 'ERP',
     *         'url' => 'https://example.com/webhooks/assinafy',
     *         'email' => 'ops@example.com',
     *         'events' => ['document_ready', 'signer_signed_document'],
     *         'is_active' => true,
     *         'signing_enabled' => true,
     *         'created_at' => '2026-10-01T12:00:00Z',
     *         'updated_at' => '2026-10-01T12:00:00Z',
     *     ],
     * ]
     * ```
     *
     * @return array<int, array<string, mixed>>
     */
    public function listEndpoints(): array
    {
        $response = $this->httpClient->get($this->accountPath('webhooks/endpoints'));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Register an additional webhook endpoint.
     * `POST /accounts/{account_id}/webhooks/endpoints`
     *
     * An account holds 1 endpoint, or up to 3 on paid plans; one past the limit returns
     * `403`. Each endpoint needs a distinct `url` (`400` otherwise). With `$signingEnabled`,
     * the API generates a `whsec_` secret — read it with {@see self::endpointSecret()}.
     * OAuth scope: `webhooks:write`.
     *
     * Request body:
     * ```php
     * [
     *     'url' => 'https://example.com/webhooks/assinafy',   // required
     *     'email' => 'ops@example.com',                      // required, failure notices
     *     'events' => ['document_ready', 'signer_signed_document'],  // required
     *     'name' => 'ERP',                                   // sent when not null
     *     'is_active' => true,
     *     'signing_enabled' => true,
     * ]
     * ```
     *
     * Example response (SDK return):
     * ```php
     * [
     *     'id' => '65f1c2a9b3e4d5f60718293a4b5c6d7e',
     *     'name' => 'ERP',
     *     'url' => 'https://example.com/webhooks/assinafy',
     *     'email' => 'ops@example.com',
     *     'events' => ['document_ready', 'signer_signed_document'],
     *     'is_active' => true,
     *     'signing_enabled' => true,
     *     'created_at' => '2026-10-01T12:00:00Z',
     *     'updated_at' => '2026-10-01T12:00:00Z',
     * ]
     * ```
     *
     * @param string            $url    absolute HTTP(S) URL that receives deliveries
     * @param string            $email  address alerted when deliveries fail
     * @param array<int, mixed> $events event type IDs; when empty, {@see DEFAULT_EVENTS} is sent
     * @param string|null       $name   label telling endpoints apart
     * @return array<string, mixed> the created endpoint
     * @throws ValidationException on a non-absolute URL, a malformed email or a non-string event
     * @throws \Assinafy\SDK\Exceptions\ApiException 403 past the plan's endpoint limit,
     *     400 on a duplicate URL
     */
    public function createEndpoint(
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] string $email,
        array $events = [],
        ?string $name = null,
        bool $isActive = true,
        bool $signingEnabled = false
    ): array {
        $this->assertUrl($url);
        $this->assertEmail($email);
        $this->assertEvents($events);

        $payload = [
            'url' => $url,
            'email' => $email,
            'events' => $events !== [] ? array_values($events) : self::DEFAULT_EVENTS,
            'is_active' => $isActive,
            'signing_enabled' => $signingEnabled,
        ];
        if ($name !== null) {
            $payload['name'] = $name;
        }

        $response = $this->httpClient->post($this->accountPath('webhooks/endpoints'), $payload);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Retrieve one webhook endpoint.
     * `GET /accounts/{account_id}/webhooks/endpoints/{endpoint_id}`
     *
     * Request: no parameters. OAuth scope: `account:read`.
     *
     * Example response (SDK return):
     * ```php
     * [
     *     'id' => '65f1c2a9b3e4d5f60718293a4b5c6d7e',
     *     'name' => 'ERP',
     *     'url' => 'https://example.com/webhooks/assinafy',
     *     'email' => 'ops@example.com',
     *     'events' => ['document_ready', 'signer_signed_document'],
     *     'is_active' => true,
     *     'signing_enabled' => true,
     *     'created_at' => '2026-10-01T12:00:00Z',
     *     'updated_at' => '2026-10-01T12:00:00Z',
     * ]
     * ```
     *
     * @return array<string, mixed>
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the endpoint does not exist
     */
    public function getEndpoint(string $endpointId): array
    {
        $response = $this->httpClient->get($this->endpointPath($endpointId));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Change selected fields of a webhook endpoint.
     * `PUT /accounts/{account_id}/webhooks/endpoints/{endpoint_id}`
     *
     * A partial update: only the keys sent change. `url` cannot repeat another endpoint's
     * (`400`). Turning `signing_enabled` on generates a secret when the endpoint has none and
     * keeps the existing one otherwise; turning it off discards the secret.
     * OAuth scope: `webhooks:write`.
     *
     * Request body (any subset, at least one key):
     * ```php
     * [
     *     'url' => 'https://example.com/webhooks/assinafy',
     *     'email' => 'ops@example.com',
     *     'events' => ['document_ready'],
     *     'name' => 'ERP',
     *     'is_active' => false,
     *     'signing_enabled' => true,
     * ]
     * ```
     *
     * Example response (SDK return, the updated endpoint):
     * ```php
     * [
     *     'id' => '65f1c2a9b3e4d5f60718293a4b5c6d7e',
     *     'name' => 'ERP',
     *     'url' => 'https://example.com/webhooks/assinafy',
     *     'email' => 'ops@example.com',
     *     'events' => ['document_ready', 'signer_signed_document'],
     *     'is_active' => true,
     *     'signing_enabled' => true,
     *     'created_at' => '2026-10-01T12:00:00Z',
     *     'updated_at' => '2026-10-01T12:00:00Z',
     * ]
     * ```
     *
     * @param array<string, mixed> $changes subset of `url`, `email`, `events`, `name`,
     *     `is_active`, `signing_enabled`
     * @return array<string, mixed> the updated endpoint
     * @throws ValidationException when `$changes` is empty, has an unknown key, or a value of
     *     the wrong type
     */
    public function updateEndpoint(string $endpointId, #[\SensitiveParameter] array $changes): array
    {
        if ($changes === []) {
            throw new ValidationException('At least one webhook endpoint field is required');
        }
        foreach ($changes as $field => $value) {
            $valid = match ($field) {
                'url', 'email', 'name' => is_string($value),
                'events' => is_array($value) && $value !== [],
                'is_active', 'signing_enabled' => is_bool($value),
                default => throw new ValidationException("Unknown webhook endpoint field: {$field}"),
            };
            if (!$valid) {
                throw new ValidationException("Webhook endpoint {$field} has an invalid value");
            }
        }
        if (isset($changes['url'])) {
            $this->assertUrl($changes['url']);
        }
        if (isset($changes['email'])) {
            $this->assertEmail($changes['email']);
        }
        if (isset($changes['events'])) {
            $this->assertEvents($changes['events']);
        }

        $response = $this->httpClient->put($this->endpointPath($endpointId), $changes);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete a webhook endpoint, stopping its deliveries and freeing its slot.
     * `DELETE /accounts/{account_id}/webhooks/endpoints/{endpoint_id}`
     *
     * Request: no body. OAuth scope: `webhooks:write`.
     *
     * Example response (SDK return, the unwrapped empty `data`):
     * ```php
     * []
     * ```
     *
     * @return array<array-key, mixed>
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the endpoint does not exist
     */
    public function deleteEndpoint(string $endpointId): array
    {
        $response = $this->httpClient->delete($this->endpointPath($endpointId));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Read the secret that signs deliveries to an endpoint.
     * `GET /accounts/{account_id}/webhooks/endpoints/{endpoint_id}/secret`
     *
     * Pass it to {@see \Assinafy\SDK\Support\WebhookEventParser::verifySignature()}. Store
     * it like any credential. Not available to OAuth applications; requires an API key or a
     * user access token.
     *
     * Request: no parameters.
     *
     * Wire response `data`: `['secret' => 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw']`.
     *
     * Example response (SDK return, the secret itself):
     * ```php
     * 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'
     * ```
     *
     * @return string `whsec_` followed by the base64-encoded key
     * @throws \Assinafy\SDK\Exceptions\ApiException 400 when signing is disabled, 404 when
     *     the endpoint does not exist
     * @throws NetworkException when a successful response carries no secret
     */
    public function endpointSecret(string $endpointId): string
    {
        return $this->secretFrom($this->httpClient->get($this->endpointPath($endpointId, '/secret')));
    }

    /**
     * Replace an endpoint's signing secret and return the new one.
     * `POST /accounts/{account_id}/webhooks/endpoints/{endpoint_id}/secret/rotate`
     *
     * The old secret stops working immediately: deliveries sent afterwards are signed only
     * with the new one, so update the receiver's stored secret right away. Not available to
     * OAuth applications.
     *
     * Request: no body.
     *
     * Example response (SDK return, the new secret):
     * ```php
     * 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'
     * ```
     *
     * @return string the new `whsec_` secret
     * @throws \Assinafy\SDK\Exceptions\ApiException 400 when signing is disabled, 404 when
     *     the endpoint does not exist
     * @throws NetworkException when a successful response carries no secret
     */
    public function rotateEndpointSecret(string $endpointId): string
    {
        return $this->secretFrom($this->httpClient->post($this->endpointPath($endpointId, '/secret/rotate')));
    }

    /**
     * List the available webhook event types and their descriptions.
     * `GET /webhooks/event-types` (not account-scoped).
     *
     * The authoritative vocabulary for the `events` array of {@see self::register()}. The
     * `EVENT_*` constants mirror these IDs; prefer this call over hard-coding if you render
     * a picker, since the platform can add types. OAuth scope: `documents:read`.
     *
     * Request: no parameters.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     [
     *         'id' => 'document_uploaded',
     *         'description' => 'Triggered when the User has uploaded a Document',
     *     ],
     *     [
     *         'id' => 'document_metadata_ready',
     *         'description' => 'Triggered when the document is ready to be prepared. The the document has been'
     *             . ' normalized to PDF and its pages are available.',
     *     ],
     *     [
     *         'id' => 'document_prepared',
     *         'description' => 'Triggered when the User as subject prepares a Document.',
     *     ],
     *     [
     *         'id' => 'assignment_created',
     *         'description' => 'Triggered when the User created an assignment for a Document. Includes a'
     *             . ' snapshot of the creator profile (name, email, telephone) and origin IP/user-agent.',
     *     ],
     *     [
     *         'id' => 'signature_requested',
     *         'description' => 'Triggered when the User requested signature of a Document',
     *     ],
     *     [
     *         'id' => 'document_ready',
     *         'description' => 'Triggered when the last Signer of the assignment signs the Document, as a'
     *             . ' result, the document status becomes ready.',
     *     ],
     *     [
     *         'id' => 'signer_created',
     *         'description' => 'Triggered when the User created a Signer',
     *     ],
     *     [
     *         'id' => 'signer_email_verified',
     *         'description' => 'Triggered when Signer\'s email has been verified by a verification code linked to a Document',
     *     ],
     *     [
     *         'id' => 'signer_whatsapp_verified',
     *         'description' => 'Triggered when Signer\'s WhatsApp phone number has been verified by a verification code linked to a Document',
     *     ],
     *     [
     *         'id' => 'signer_data_confirmed',
     *         'description' => 'Triggered when Signer\'s data has been confirmed',
     *     ],
     *     [
     *         'id' => 'signer_signed_document',
     *         'description' => 'Triggered when the Signer signed a Document',
     *     ],
     *     [
     *         'id' => 'signer_viewed_document',
     *         'description' => 'Triggered when the Signer viewed a Document for the first time',
     *     ],
     *     [
     *         'id' => 'signer_rejected_document',
     *         'description' => 'Triggered when the Signer rejected signing a Document',
     *     ],
     *     [
     *         'id' => 'user_rejected_document',
     *         'description' => 'Triggered when document has been cancelled.',
     *     ],
     *     [
     *         'id' => 'document_processing_failed',
     *         'description' => 'Unprocessable document, either invalid or the system couldn\'t process it',
     *     ],
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
     * Request (query string): `endpoint_id`, `event`, `delivered` (`true`/`false`), `from`
     * and `to` (Unix timestamps), `page`, `per-page`. OAuth scope: `documents:read`.
     *
     * Example query (no request body):
     * ```php
     * [
     *     'endpoint_id' => 'endpoint-id', 'event' => 'document_ready', 'delivered' => 'false',
     *     'from' => 1788220800, 'to' => 1790812800, 'page' => 1, 'per-page' => 20,
     * ]
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'status' => 200,
     *     'message' => '',
     *     'data' => [
     *         [
     *             'resource' => 'activity_dispatching_history',
     *             'id' => 'webhook-dispatch-id',
     *             'event' => 'document_ready',
     *             'activity_id' => 456,
     *             'endpoint' => 'https://example.com/webhook',
     *             'payload' => null,
     *             'delivered' => true,
     *             'http_status' => 200,
     *             'response_body' => 'OK',
     *             'error' => null,
     *             'created_at' => '2026-09-01T10:30:00Z',
     *             'updated_at' => '2026-09-01T10:30:00Z',
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
     * `http_status`, `response_body`, and `error` are the diagnostics for a failed delivery —
     * they record what your endpoint actually answered. On a successful delivery `delivered`
     * is true and `error` is empty.
     *
     * @param array<string, scalar> $filters optional `endpoint_id`, `event`, `delivered`,
     *     `from`, `to`, `page`, `per-page`
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
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'resource' => 'activity_dispatching_history',
     *     'id' => 'webhook-dispatch-id',
     *     'event' => 'document_ready',
     *     'activity_id' => 456,
     *     'endpoint' => 'https://example.com/webhook',
     *     'payload' => null,
     *     'delivered' => true,
     *     'http_status' => 200,
     *     'response_body' => 'OK',
     *     'error' => null,
     *     'created_at' => '2026-09-01T10:30:00Z',
     *     'updated_at' => '2026-09-01T10:30:00Z',
     * ]
     * ```
     *
     * @return array<string, mixed> the new dispatch record
     * @throws ValidationException when `$dispatchId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 400 when the webhook subscription is
     *     inactive or the event type is not subscribed; 404 when the dispatch does not exist
     */
    public function retryDispatch(string $dispatchId): array
    {
        $dispatchId = $this->pathSegment($dispatchId, 'dispatch ID');
        $response = $this->httpClient->post($this->accountPath("webhooks/{$dispatchId}/retry"));

        return $this->extractData($response->getData() ?? []);
    }

    private function endpointPath(string $endpointId, string $suffix = ''): string
    {
        return $this->accountPath('webhooks/endpoints/' . $this->pathSegment($endpointId, 'endpoint ID') . $suffix);
    }

    private function secretFrom(Response $response): string
    {
        $secret = $this->extractData($response->getData() ?? [])['secret'] ?? null;
        if (!is_string($secret) || $secret === '') {
            throw new NetworkException('Webhook secret response did not contain a secret');
        }

        return $secret;
    }

    private function assertUrl(#[\SensitiveParameter] string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || !in_array($scheme, ['http', 'https'], true)
            || parse_url($url, PHP_URL_HOST) === null
        ) {
            throw new ValidationException('Webhook URL must be an absolute HTTP or HTTPS URL');
        }
    }

    private function assertEmail(#[\SensitiveParameter] string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Webhook email must be valid', ['email' => $email]);
        }
    }

    /** @param array<array-key, mixed> $events */
    private function assertEvents(array $events): void
    {
        foreach ($events as $event) {
            if (!is_string($event) || trim($event) === '') {
                throw new ValidationException('Webhook events must be non-empty strings', [
                    'event' => $event,
                ]);
            }
        }
    }
}
