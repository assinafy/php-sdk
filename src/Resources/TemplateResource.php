<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

class TemplateResource extends AbstractResource
{
    public function list(
        int $page = 1,
        int $perPage = 20,
        array $filters = []
    ): array {
        $params = array_merge([
            'page' => $page,
            'per_page' => $perPage,
        ], $filters);

        $this->logger->debug("Listing templates", [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $response = $this->httpClient->get(
            "accounts/{$this->config->getAccountId()}/templates",
            $params
        );

        return $response->getData() ?? [];
    }

    public function get(string $templateId): array
    {
        $this->logger->debug("Fetching template details", ['template_id' => $templateId]);

        $response = $this->httpClient->get(
            "accounts/{$this->config->getAccountId()}/templates/{$templateId}"
        );

        return $this->extractData($response->getData() ?? []);
    }
}
