<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

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
     * @return array<string, mixed> the created template
     */
    public function create(#[\SensitiveParameter] string $filePath): array
    {
        DocumentResource::assertUploadable($filePath);

        $this->logger->info('Uploading template', [
            'size' => filesize($filePath),
        ]);

        $response = $this->httpClient->uploadFile($this->accountPath('templates'), $filePath);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List templates in the workspace.
     * `GET /accounts/{account_id}/templates`
     *
     * @param array<string, scalar> $filters optional documented `search`; the live API also
     *     accepts the runtime-undocumented `status` and `sort` filters
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     *     full envelope with pagination lifted from response headers
     */
    public function list(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $params = $this->paginationQuery($page, $perPage, $filters);

        return $this->withPagination($this->httpClient->get($this->accountPath('templates'), $params));
    }

    /**
     * Retrieve a template, including roles and per-page field placements.
     * `GET /accounts/{account_id}/templates/{template_id}`
     *
     * The single-template response carries the `roles` array that
     * {@see DocumentResource::createFromTemplate()} relies on to bind signers to
     * role slots, plus `default_document_tags` (omitted from the list endpoint).
     *
     * @return array<string, mixed>
     */
    public function get(string $templateId): array
    {
        $templateId = $this->pathSegment($templateId, 'template ID');
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
     * @return array<string, mixed> the updated template
     */
    public function update(string $templateId, #[\SensitiveParameter] array $data): array
    {
        if ($data === []) {
            throw new ValidationException('Provide at least one template property to update');
        }

        $templateId = $this->pathSegment($templateId, 'template ID');
        $response = $this->httpClient->put($this->accountPath("templates/{$templateId}"), $data);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete a template.
     * `DELETE /accounts/{account_id}/templates/{template_id}`
     *
     * @return array<array-key, mixed>
     */
    public function delete(string $templateId): array
    {
        $this->logger->info('Deleting template', ['template_id' => $templateId]);

        $templateId = $this->pathSegment($templateId, 'template ID');
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
        $templateId = $this->pathSegment($templateId, 'template ID');
        $pageId = $this->pathSegment($pageId, 'page ID');
        $response = $this->httpClient->get(
            $this->accountPath("templates/{$templateId}/pages/{$pageId}/download")
        );

        return $response->getBody();
    }

    /**
     * Poll the runtime-supported template detail route until processing completes.
     * The deadline is checked between calls; an in-flight request is bounded by the
     * configured transport timeout.
     *
     * @return array<string, mixed>
     */
    public function waitUntilReady(
        string $templateId,
        int $maxWaitSeconds = 60,
        int $pollIntervalSeconds = 2
    ): array {
        if ($maxWaitSeconds < 1 || $pollIntervalSeconds < 1) {
            throw new ValidationException('Wait and poll intervals must be positive integers');
        }

        $deadline = hrtime(true) + ($maxWaitSeconds * 1_000_000_000);
        while (hrtime(true) < $deadline) {
            $template = $this->get($templateId);
            if (hrtime(true) >= $deadline) {
                break;
            }
            $status = strtolower((string) ($template['status'] ?? ''));

            if ($status === 'ready') {
                return $template;
            }

            if (in_array($status, ['failed', 'processing_failed'], true)) {
                throw new \RuntimeException("Template processing failed with status: {$status}");
            }

            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds > 0) {
                usleep(min(
                    $pollIntervalSeconds * 1_000_000,
                    (int) ceil($remainingNanoseconds / 1_000)
                ));
            }
        }

        throw new \RuntimeException(
            "Timed out after {$maxWaitSeconds}s waiting for template to become ready"
        );
    }
}
