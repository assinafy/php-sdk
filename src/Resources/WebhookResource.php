<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

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
     * The API requires `is_active`, `url`, `email`, and `events` in the body.
     *
     * @param array<int, string> $events when empty, {@see DEFAULT_EVENTS} is sent
     */
    public function register(string $url, string $email, array $events = [], bool $isActive = true): array
    {
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
     * later with {@see activate()} without re-supplying them.
     */
    public function deactivate(): array
    {
        $response = $this->httpClient->put($this->accountPath('webhooks/inactivate'));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Re-enable delivery on the existing subscription.
     *
     * There is no dedicated "activate" route, so this re-sends the stored URL / email /
     * events via {@see register()} with `is_active = true`.
     *
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
     * @param array<string, scalar> $filters optional `event`, `delivered`, `from`, `to`,
     *     `page`, `per-page`
     * @return array{data?: array<int, array<string, mixed>>, meta?: array<string, mixed>} full
     *     envelope — items under `['data']`, pagination under `['meta']`.
     */
    public function dispatches(array $filters = []): array
    {
        $response = $this->httpClient->get($this->accountPath('webhooks'), $filters);

        return $response->getData() ?? [];
    }

    /**
     * Manually retry a single webhook dispatch.
     * `POST /accounts/{account_id}/webhooks/{dispatch_id}/retry`
     *
     * Returns the newly created dispatch entry.
     */
    public function retryDispatch(string $dispatchId): array
    {
        $response = $this->httpClient->post($this->accountPath("webhooks/{$dispatchId}/retry"));

        return $this->extractData($response->getData() ?? []);
    }
}
