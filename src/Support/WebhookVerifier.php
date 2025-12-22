<?php

declare(strict_types=1);

namespace Assinafy\SDK\Support;

use Assinafy\SDK\Configuration;

class WebhookVerifier
{
    private Configuration $config;

    public function __construct(Configuration $config)
    {
        $this->config = $config;
    }

    public function verify(string $payload, string $signature): bool
    {
        $webhookSecret = $this->config->getWebhookSecret();

        if (empty($webhookSecret)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }

    public function extractEvent(string $payload): ?array
    {
        $data = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $data;
    }

    public function getEventType(?array $event): ?string
    {
        return $event['event'] ?? $event['type'] ?? null;
    }

    public function getEventData(?array $event): array
    {
        return $event['data'] ?? $event['object'] ?? [];
    }
}
