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
     * Response (the decoded envelope):
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
     * Note there is no `data` key — the entity lives under `object` and the event-specific
     * detail under `payload`. Returns `null` rather than throwing when the body is not
     * valid JSON, so a malformed delivery can be answered with a 400 instead of a 500:
     * ```php
     * $event = $client->webhookEvents()->extractEvent(file_get_contents('php://input'));
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
     * Response — the shape varies by `subject` (Document, Signer, Template, …); a Document
     * object carries `id`, `name`, `status`, `artifacts`, `pages`, `tags` and friends:
     * ```
     * [
     *   'id'         => '10413df89e95e0097dcd8e2f9ea7',
     *   'type'       => 'Document',
     *   'name'       => 'contract.pdf',
     *   'status'     => 'pending_signature',
     *   'account_id' => '64f000000000000000000001',
     *   'artifacts'  => ['original' => 'https://…', 'thumbnail' => 'https://…'],
     *   'pages'      => [['id' => '1041…', 'number' => 1, 'width' => 1275, 'height' => 1651]],
     *   'tags'       => [],
     *   'is_closed'  => false,
     *   'assignment' => ['id' => '1041…', 'items' => [ … ], 'signers' => [ … ]],
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
     * ['signer_id' => '19e6b92e7895332ed9708535d8c', 'signed_at' => '2026-08-27T15:10:04Z']
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
     * $accountId = $client->webhookEvents()->getAccountId($event);   // '64f000000000000000000001'
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
