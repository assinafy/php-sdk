<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

class WebhookResource extends AbstractResource
{
    public function register(string $url, string $email, array $events = []): array
    {
        if (empty($events)) {
            $events = [
                'document_ready',
                'signer_signed_document',
                'signer_rejected_document',
                'document_processing_failed',
            ];
        }

        $payload = [
            'events' => $events,
            'is_active' => true,
            'url' => $url,
            'email' => $email,
        ];

        $this->logger->info("Registering webhook", ['url' => $url]);

        $response = $this->httpClient->put(
            "accounts/{$this->config->getAccountId()}/webhooks/subscriptions",
            $payload
        );

        return $response->getData() ?? [];
    }

    public function get(): ?array
    {
        try {
            $response = $this->httpClient->get(
                "accounts/{$this->config->getAccountId()}/webhooks/subscriptions"
            );

            $data = $response->getData() ?? [];
            return $data['data'] ?? null;
        } catch (\Exception $e) {
            $this->logger->warning("Error getting webhook subscription", [
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function delete(): array
    {
        $this->logger->info("Deleting webhook subscription");

        $response = $this->httpClient->delete(
            "accounts/{$this->config->getAccountId()}/webhooks/subscriptions"
        );

        return $response->getData() ?? [];
    }
}

