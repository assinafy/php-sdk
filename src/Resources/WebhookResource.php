<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

/**
 * Webhooks resource — manages the workspace's single webhook subscription.
 *
 * The Assinafy webhook subscription is an upsert: `PUT` creates or replaces it,
 * `GET` returns the current configuration, `DELETE` removes it. Although these
 * endpoints are not currently rendered in the public docs UI they are part of
 * the v1 API surface and respond at the documented paths.
 */
class WebhookResource extends AbstractResource
{
    public const EVENT_DOCUMENT_READY = 'document_ready';
    public const EVENT_SIGNER_SIGNED = 'signer_signed_document';
    public const EVENT_SIGNER_REJECTED = 'signer_rejected_document';
    public const EVENT_DOCUMENT_PROCESSING_FAILED = 'document_processing_failed';

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
     * @param array<int, string> $events when empty, {@see DEFAULT_EVENTS} is sent
     */
    public function register(string $url, string $email, array $events = []): array
    {
        $payload = [
            'url' => $url,
            'email' => $email,
            'events' => $events !== [] ? $events : self::DEFAULT_EVENTS,
            'is_active' => true,
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
     * Delete the webhook subscription.
     * `DELETE /accounts/{account_id}/webhooks/subscriptions`
     */
    public function delete(): array
    {
        $response = $this->httpClient->delete($this->accountPath('webhooks/subscriptions'));

        return $response->getData() ?? [];
    }
}
