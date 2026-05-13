<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

/**
 * Webhooks resource — manages the workspace's single webhook subscription.
 *
 * The Assinafy webhook subscription is an upsert: `PUT` creates or replaces it,
 * `GET` returns the current configuration, `DELETE` removes it. These endpoints
 * are not currently rendered in the public docs UI (`https://api.assinafy.com.br/v1/docs`)
 * but they are part of the v1 API surface and respond at the documented paths;
 * the live integration test exercises them on every release.
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
     * The API requires `is_active` in the request body (the live sandbox returns
     * `O atributo "is_active" é obrigatório.` if it's missing) even though the
     * field is not currently rendered in the public docs UI.
     *
     * @param array<int, string> $events when empty, {@see DEFAULT_EVENTS} is sent
     */
    public function register(string $url, string $email, array $events = [], bool $isActive = true): array
    {
        $payload = [
            'url' => $url,
            'email' => $email,
            'events' => $events !== [] ? $events : self::DEFAULT_EVENTS,
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
     *
     * The v1 API has no `DELETE /accounts/{id}/webhooks/subscriptions` route (it
     * returns 404). The way to stop receiving events is to flip `is_active` to
     * `false` via `PUT`. The URL / email / events stay on file so the subscription
     * can be re-enabled later with {@see activate()} without re-supplying them.
     */
    public function deactivate(): array
    {
        $current = $this->get();

        if ($current === null) {
            throw new \RuntimeException('No webhook subscription is configured — nothing to deactivate.');
        }

        return $this->register(
            (string) ($current['url'] ?? ''),
            (string) ($current['email'] ?? ''),
            is_array($current['events'] ?? null) && $current['events'] !== []
                ? $current['events']
                : self::DEFAULT_EVENTS,
            false
        );
    }

    /**
     * Re-enable delivery on the existing subscription.
     *
     * Counterpart of {@see deactivate()}; flips `is_active` back to `true`
     * via `PUT` while preserving the configured URL / email / events.
     */
    public function activate(): array
    {
        $current = $this->get();

        if ($current === null) {
            throw new \RuntimeException('No webhook subscription is configured — call register() first.');
        }

        return $this->register(
            (string) ($current['url'] ?? ''),
            (string) ($current['email'] ?? ''),
            is_array($current['events'] ?? null) && $current['events'] !== []
                ? $current['events']
                : self::DEFAULT_EVENTS,
            true
        );
    }
}
