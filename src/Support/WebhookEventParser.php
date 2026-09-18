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
     * Local parsing only — makes no HTTP request.
     *
     * Request (the delivery your endpoint receives): the raw POST body.
     *
     * Response (the decoded envelope, using the signer_created event):
     * ```php
     * [
     *     'id' => 42,
     *     'event' => 'signer_created',
     *     'message' => 'Signer created',
     *     'subject' => ['id' => 'user-id', 'type' => 'User', 'name' => 'Example User'],
     *     'origin' => ['ip' => '203.0.113.10', 'user-agent' => 'Example/1.0'],
     *     'account_id' => 'account-id',
     *     'created_at' => 1788264000,
     *     'object' => [
     *         'id' => 'signer-id',
     *         'type' => 'Signer',
     *         'full_name' => 'Example Signer',
     *         'email' => 'signer@example.com',
     *         'whatsapp_phone_number' => null,
     *         'government_id' => null,
     *         'has_accepted_terms' => false,
     *     ],
     *     'payload' => ['signer_full_name' => 'Example Signer'],
     * ]
     * ```
     *
     * Note there is no `data` key — the entity lives under `object` and the event-specific
     * detail under `payload`. Returns `null` rather than throwing when the body is not
     * valid JSON, so a malformed delivery can be answered with a 400 instead of a 500:
     * ```php
     * $raw = file_get_contents('php://input');
     * $event = is_string($raw) ? $client->webhookEvents()->extractEvent($raw) : null;
     * if ($event === null) {
     *     http_response_code(400);
     *     return;
     * }
     * ```
     *
     * @param string $payload raw request body, exactly as received
     * @return array<string, mixed>|null the decoded envelope, or null when the body is not
     *     a JSON object or array
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
     * Reads the envelope's `event` key. Accepts the `null` that
     * {@see self::extractEvent()} returns, so the two compose without a guard, and returns
     * `null` for anything that is not a string — an unrecognised body can never be mistaken
     * for a known event.
     *
     * Local parsing only — makes no HTTP request.
     *
     * Request: the decoded envelope from {@see self::extractEvent()}.
     *
     * Response: one of the `EVENT_*` values, e.g. `'document_ready'`.
     *
     * @param array<string, mixed>|null $event the decoded envelope
     * @return string|null the event name, or null when absent or not a string
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
     * Local parsing only — makes no HTTP request.
     *
     * Request: the decoded envelope from {@see self::extractEvent()}.
     *
     * Response: the complete object as received. Its shape depends on `object.type`.
     * Example for signer_created:
     * ```php
     * [
     *     'id' => 'signer-id',
     *     'type' => 'Signer',
     *     'full_name' => 'Example Signer',
     *     'email' => 'signer@example.com',
     *     'whatsapp_phone_number' => null,
     *     'government_id' => null,
     *     'has_accepted_terms' => false,
     * ]
     * ```
     *
     * Returns `[]` rather than null when the key is missing, so the result is always safe to
     * iterate. Treat it as a hint, not as truth: deliveries are unsigned, so re-fetch the
     * entity through the API before acting on it.
     *
     * @param array<string, mixed>|null $event the decoded envelope
     * @return array<string, mixed> the `object` entity, or `[]` when absent
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
     * Local parsing only — makes no HTTP request.
     *
     * Request: the decoded envelope from {@see self::extractEvent()}.
     *
     * Response — for `signer_signed_document`, `object` is the Document while `payload`
     * names which signer signed:
     * ```
     * ['signer_full_name' => 'Example Signer']
     * ```
     *
     * Many events carry an empty `payload` — the entity alone is the news. Returns `[]`
     * rather than null when the key is missing, so the result is always safe to iterate.
     *
     * @param array<string, mixed>|null $event the decoded envelope
     * @return array<string, mixed> the `payload` detail, or `[]` when absent
     */
    public function getEventPayload(?array $event): array
    {
        return is_array($event['payload'] ?? null) ? $event['payload'] : [];
    }

    /**
     * The account the event belongs to — useful when one endpoint serves several workspaces.
     *
     * Reads the envelope's top-level `account_id`. Route on this to pick the right API
     * credential before re-fetching the entity. Local parsing only — makes no HTTP request.
     *
     * Request: the decoded envelope from {@see self::extractEvent()}.
     *
     * Response: the workspace ID, or `null` when absent or not a string.
     * ```php
     * $accountId = $client->webhookEvents()->getAccountId($event);   // 'account-id'
     * ```
     *
     * @param array<string, mixed>|null $event the decoded envelope
     * @return string|null the workspace ID, or null when absent
     */
    public function getAccountId(?array $event): ?string
    {
        $accountId = $event['account_id'] ?? null;

        return is_string($accountId) ? $accountId : null;
    }
}
