<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

/**
 * Templates resource — every endpoint under `/accounts/{account_id}/templates`.
 *
 * Covers the full template-management surface: create (PDF upload), list, get,
 * update, delete, the per-page render download, and the `documents`/`estimate-cost`
 * sub-resources used to instantiate documents from a template
 * (see {@see DocumentResource::createFromTemplate()}).
 *
 * Signer roles and field placements are configured in the Assinafy web app after
 * upload — a freshly created template carries only the default `Editor` role.
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class TemplateResource extends AbstractResource
{
    /**
     * Create a template by uploading a PDF.
     * `POST /accounts/{account_id}/templates`
     *
     * The file is uploaded as `multipart/form-data` (same transport as a document
     * upload). The template starts in the `Uploaded` state; the API renders its
     * pages asynchronously, so poll {@see get()} until `status` is `Ready` before
     * downloading pages or creating documents from it.
     *
     * @throws \Assinafy\SDK\Exceptions\ValidationException when the file is missing,
     *     not a PDF, or larger than the 25 MB API limit
     */
    public function create(string $filePath): array
    {
        DocumentResource::assertUploadable($filePath);

        $this->logger->info('Uploading template', [
            'file' => $filePath,
            'size' => filesize($filePath),
        ]);

        $response = $this->httpClient->uploadFile($this->accountPath('templates'), $filePath);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List templates in the workspace.
     * `GET /accounts/{account_id}/templates`
     *
     * @param array<string, scalar> $filters optional `status`, `search`, `sort`
     * @return array{data?: array<int, array<string, mixed>>, meta?: array<string, mixed>} full
     *     envelope — items live under `['data']`, pagination under `['meta']`.
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
     *
     * The single-template response carries the `roles` array that
     * {@see DocumentResource::createFromTemplate()} relies on to bind signers to
     * role slots, plus `default_document_tags` (omitted from the list endpoint).
     */
    public function get(string $templateId): array
    {
        $response = $this->httpClient->get($this->accountPath("templates/{$templateId}"));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Update a template's editable metadata.
     * `PUT /accounts/{account_id}/templates/{template_id}`
     *
     * Only the document file itself is immutable; the editable fields are the
     * display `name`, the default `document_name` applied to documents created from
     * the template, and the default invitation `message`.
     *
     * @param array<string, mixed> $data subset of { name, document_name, message }
     */
    public function update(string $templateId, array $data): array
    {
        $response = $this->httpClient->put($this->accountPath("templates/{$templateId}"), $data);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete a template.
     * `DELETE /accounts/{account_id}/templates/{template_id}`
     */
    public function delete(string $templateId): array
    {
        $this->logger->info('Deleting template', ['template_id' => $templateId]);

        $response = $this->httpClient->delete($this->accountPath("templates/{$templateId}"));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Download a rendered template page as JPEG (raw binary body).
     * `GET /accounts/{account_id}/templates/{template_id}/pages/{page_id}/download`
     *
     * The page IDs come from the `pages` array on the {@see get()} response.
     */
    public function downloadPage(string $templateId, string $pageId): string
    {
        $response = $this->httpClient->get(
            $this->accountPath("templates/{$templateId}/pages/{$pageId}/download")
        );

        return $response->getBody();
    }
}
