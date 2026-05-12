<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

/**
 * Templates resource — every endpoint under `/accounts/{account_id}/templates`.
 *
 * Template creation/editing is performed in the Assinafy web app; the public REST API
 * only exposes read endpoints and the `documents`/`estimate-cost` sub-resources used
 * to instantiate documents from a template (see {@see DocumentResource::createFromTemplate}).
 */
class TemplateResource extends AbstractResource
{
    /**
     * List templates in the workspace.
     * `GET /accounts/{account_id}/templates`
     *
     * @param array<string, scalar> $filters optional `status`, `search`, `sort`
     */
    public function list(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $params = array_merge([
            'page' => $page,
            'per-page' => $perPage,
        ], $filters);

        $response = $this->httpClient->get($this->accountPath('templates'), $params);

        return $response->getData() ?? [];
    }

    /**
     * Retrieve a template, including roles and per-page field placements.
     * `GET /accounts/{account_id}/templates/{template_id}`
     */
    public function get(string $templateId): array
    {
        $response = $this->httpClient->get($this->accountPath("templates/{$templateId}"));

        return $this->extractData($response->getData() ?? []);
    }
}
