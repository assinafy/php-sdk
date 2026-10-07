<?php

declare(strict_types=1);

namespace Assinafy\SDK\Support;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Verifies and parses Assinafy webhook deliveries.
 *
 * An endpoint created with `signing_enabled: true` signs every delivery following the
 * Standard Webhooks specification: the `webhook-id`, `webhook-timestamp` and
 * `webhook-signature` headers carry an HMAC-SHA256 over `{id}.{timestamp}.{raw body}`, keyed
 * by the endpoint's `whsec_` secret. Check it with {@see self::verifySignature()} before
 * decoding the body. Unsigned endpoints send `webhook-id` and `webhook-timestamp` only.
 *
 * Deduplicate by the `webhook-id` header: it is identical on every attempt of the same event
 * to the same endpoint, and distinct per endpoint.
 *
 * @see https://api.assinafy.com.br/v1/docs
 * @see https://www.standardwebhooks.com
 */
class WebhookEventParser
{
    /** Maximum distance, in seconds, between `webhook-timestamp` and the local clock. */
    public const SIGNATURE_TOLERANCE_SECONDS = 300;

    /**
     * Check a signed delivery's `webhook-signature` against the endpoint secret.
     *
     * Local verification only — makes no HTTP request. Pass the **raw** body exactly as
     * received (never a re-encoded copy) and the request headers. Header names are matched
     * case-insensitively, and `$_SERVER`-style keys (`HTTP_WEBHOOK_ID`) are accepted, so
     * `getallheaders()`, `$_SERVER` and PSR-7 `getHeaders()` all work unchanged.
     *
     * Request (the delivery your endpoint receives):
     * ```php
     * $headers = [
     *     'webhook-id' => 'msg_p5jXN8AQM9LWM0D4loKWxJek',
     *     'webhook-timestamp' => '1614265330',
     *     'webhook-signature' => 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
     * ];
     * $rawBody = '{"test": 2432232314}';
     * $secret = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';   // WebhookResource::endpointSecret()
     * ```
     *
     * Response: `true` when any space-separated `v1,<signature>` entry matches (compared in
     * constant time) and the timestamp lies within `$toleranceSeconds` of `$now`; otherwise
     * `false` — including when a header is missing. Answer `false` with HTTP 401 and do not
     * process the body:
     * ```php
     * $raw = (string) file_get_contents('php://input');
     * $parser = $client->webhookEvents();
     * if (!$parser->verifySignature($raw, getallheaders(), $secret)) {
     *     http_response_code(401);
     *     return;
     * }
     * $event = $parser->extractEvent($raw);
     * ```
     *
     * A rotated secret takes effect immediately, so read it from your configuration on each
     * request rather than caching it for the process lifetime.
     *
     * @param string $payload raw request body, exactly as received
     * @param array<array-key, mixed> $headers request headers; values may be strings or
     *     lists of strings
     * @param string $secret the endpoint's `whsec_` signing secret
     * @param int $toleranceSeconds accepted clock distance, in seconds
     * @param int|null $now Unix time to compare against; defaults to `time()`
     * @return bool whether the delivery is authentic and fresh
     * @throws ValidationException when `$secret` is not a `whsec_` base64 secret
     */
    public function verifySignature(
        #[\SensitiveParameter] string $payload,
        array $headers,
        #[\SensitiveParameter] string $secret,
        int $toleranceSeconds = self::SIGNATURE_TOLERANCE_SECONDS,
        ?int $now = null
    ): bool {
        $key = str_starts_with($secret, 'whsec_') ? base64_decode(substr($secret, 6), true) : false;
        if ($key === false || $key === '') {
            throw new ValidationException('Webhook secret must be the whsec_ value issued for the endpoint');
        }

        $id = self::header($headers, 'webhook-id');
        $timestamp = self::header($headers, 'webhook-timestamp');
        $signatures = self::header($headers, 'webhook-signature');
        if ($id === null || $signatures === null || $timestamp === null || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$payload}", $key, true));
        foreach (explode(' ', $signatures) as $entry) {
            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, '');
            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

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
     * iterate. The snapshot reflects the moment the event fired; re-fetch the entity through the API
     * before acting on state that may have changed since.
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

    /**
     * Case-insensitive header lookup that also accepts `$_SERVER` keys (`HTTP_WEBHOOK_ID`).
     *
     * @param array<array-key, mixed> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            $key = str_replace('_', '-', strtolower((string) $key));
            if ($key !== $name && $key !== 'http-' . $name) {
                continue;
            }
            $value = is_array($value) ? reset($value) : $value;

            return is_string($value) && $value !== '' ? $value : null;
        }

        return null;
    }
}
