<?php

declare(strict_types=1);

namespace Assinafy\SDK\Support;

/**
 * Parses Assinafy webhook deliveries into their component parts.
 *
 * The webhook contract provides no signing secret or signature header. The subscription
 * endpoint (`PUT /accounts/{id}/webhooks/subscriptions`) accepts `events`, `is_active`, `url`,
 * and `email`, so this parser decodes payloads without claiming HMAC verification.
 *
 * Authenticate deliveries by other means: keep the endpoint URL secret and unguessable, and
 * re-fetch the referenced entity through the API before acting on it.
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class WebhookEventParser
{
    /**
     * Decode a raw webhook body into an event array, or null when it is not valid JSON.
     *
     * The delivered envelope looks like:
     * ```
     * [
     *   'id'         => 8629,
     *   'event'      => 'signature_requested',
     *   'message'    => 'Signature requested',
     *   'subject'    => ['id' => 'user-id', 'type' => 'User', 'email' => 'sender@example.com'],
     *   'origin'     => ['ip' => '203.0.113.10', 'user-agent' => 'Example/1.0'],
     *   'account_id' => '64f000000000000000000001',
     *   'created_at' => 1781044129,
     *   'object'     => ['id' => '1032c…', 'type' => 'Document', 'status' => 'pending_signature', …],
     *   'payload'    => [ … event-specific parameters … ],
     * ]
     * ```
     *
     * @param string $payload raw request body, exactly as received
     * @return array<string, mixed>|null
     */
    public function extractEvent(#[\SensitiveParameter] string $payload): ?array
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * The event name, e.g. `signature_requested`.
     *
     * @param array<string, mixed>|null $event
     * @see \Assinafy\SDK\Resources\WebhookResource the `EVENT_*` constants
     */
    public function getEventType(?array $event): ?string
    {
        $type = $event['event'] ?? null;

        return is_string($type) ? $type : null;
    }

    /**
     * The entity the event is about — the `object` key of the envelope.
     *
     * Its shape varies by `subject` (Document, Signer, Template, …); a Document object carries
     * `id`, `name`, `status`, `artifacts`, `pages`, `tags` and friends.
     *
     * @param array<string, mixed>|null $event
     * @return array<string, mixed>
     */
    public function getEventData(?array $event): array
    {
        return is_array($event['object'] ?? null) ? $event['object'] : [];
    }

    /**
     * The event-specific parameters — the `payload` key of the envelope.
     *
     * Distinct from {@see self::getEventData()}: `object` is the entity the event concerns,
     * `payload` is the extra detail about what happened to it.
     *
     * @param array<string, mixed>|null $event
     * @return array<string, mixed>
     */
    public function getEventPayload(?array $event): array
    {
        return is_array($event['payload'] ?? null) ? $event['payload'] : [];
    }

    /**
     * The account the event belongs to — useful when one endpoint serves several workspaces.
     *
     * @param array<string, mixed>|null $event
     */
    public function getAccountId(?array $event): ?string
    {
        $accountId = $event['account_id'] ?? null;

        return is_string($accountId) ? $accountId : null;
    }
}
