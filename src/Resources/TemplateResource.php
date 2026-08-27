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
     * Request: `multipart/form-data` with the PDF under the field name `file`.
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'resource'      => 'template',
     *   'id'            => '10414160b9d1a5ff705effd35c43',
     *   'name'          => 'service-agreement.pdf',
     *   'document_name' => 'service-agreement.pdf',
     *   'message'       => null,
     *   'status'        => 'Uploaded',   // PascalCase here, unlike document statuses
     *   'pages'         => [],           // populated once rendering finishes
     *   'roles'         => [
     *     ['id' => '10414160d1669a27520ea6d385cf', 'name' => 'TemplateEditor',
     *      'assignment_type' => 'Editor', 'created_at' => '2026-08-20T17:06:18Z',
     *      'updated_at' => '2026-08-20T17:06:18Z'],
     *   ],
     *   'tags'       => [],
     *   'created_at' => '2026-08-20T17:06:17Z',
     *   'updated_at' => '2026-08-20T17:06:17Z',
     * ]
     * ```
     *
     * This route is not part of the published OpenAPI contract but exists on the live API.
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
     * Request (query string): `page`, `per-page`, plus any `$filters` merged over them.
     *
     * Response (full envelope — pagination lifted from the `X-Pagination-*` headers). List
     * entries carry `pages` and `roles` but omit `default_document_tags`, which only
     * {@see self::get()} returns:
     * ```
     * [
     *   'status'  => 200,
     *   'message' => '',
     *   'data'    => [
     *     [
     *       'id'            => '10414160b9d1a5ff705effd35c43',
     *       'name'          => 'service-agreement.pdf',
     *       'document_name' => 'service-agreement.pdf',
     *       'message'       => null,
     *       'status'        => 'Ready',
     *       'pages'         => [
     *         ['id' => '104141610fe1037c1b4a9a9ca62c', 'number' => 1,
     *          'width' => 1275, 'height' => 1651,
     *          'download_url' => 'https://…/templates/1041…/pages/1041…/download',
     *          'fields' => []],
     *       ],
     *       'roles' => [
     *         ['id' => '10414160d1669a27520ea6d385cf', 'name' => 'TemplateEditor',
     *          'assignment_type' => 'Editor', 'created_at' => '2026-08-20T17:06:18Z',
     *          'updated_at' => '2026-08-20T17:06:18Z'],
     *       ],
     *       'tags'       => [],
     *       'created_at' => '2026-08-20T17:06:17Z',
     *       'updated_at' => '2026-08-20T17:06:21Z',
     *     ],
     *   ],
     *   'pagination' => ['current_page' => 1, 'page_count' => 2, 'per_page' => 20, 'total_count' => 24],
     * ]
     * ```
     *
     * @param array<string, scalar> $filters optional documented `search`; the live API also
     *     accepts the runtime-undocumented `status` and `sort` filters
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     *     full envelope with pagination lifted from response headers
     * @throws ValidationException when `$page` < 1 or `$perPage` is outside 1–100
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
     * Request: no parameters.
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'resource'      => 'template',
     *   'id'            => '10414160b9d1a5ff705effd35c43',
     *   'name'          => 'service-agreement.pdf',
     *   'document_name' => 'service-agreement.pdf',
     *   'message'       => null,
     *   'status'        => 'Ready',      // 'Uploaded' | 'Processing' | 'Ready' | 'Failed'
     *   'pages'         => [
     *     [
     *       'id' => '104141610fe1037c1b4a9a9ca62c', 'number' => 1,
     *       'width' => 1275, 'height' => 1651,
     *       'download_url' => 'https://…/templates/1041…/pages/1041…/download',
     *       'fields' => [],   // field placements configured in the web app
     *     ],
     *   ],
     *   'roles' => [
     *     ['id' => '10414160d1669a27520ea6d385cf', 'name' => 'TemplateEditor',
     *      'assignment_type' => 'Editor', 'created_at' => '2026-08-20T17:06:18Z',
     *      'updated_at' => '2026-08-20T17:06:18Z'],
     *   ],
     *   'tags'                  => [],
     *   'default_document_tags' => [],
     *   'created_at'            => '2026-08-20T17:06:17Z',
     *   'updated_at'            => '2026-08-20T17:06:21Z',
     * ]
     * ```
     *
     * Take the `roles[].id` values from here for `createFromTemplate()`, and the
     * `pages[].id` values for {@see self::downloadPage()}.
     *
     * This route is not part of the published OpenAPI contract but exists on the live API.
     *
     * @return array<string, mixed>
     * @throws ValidationException when `$templateId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the template does not exist
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
     * Request body (at least one key required):
     * ```
     * [
     *   'name'          => 'Service agreement (2026)',  // shown in the template list
     *   'document_name' => 'Acme — service agreement',  // default name for new documents
     *   'message'       => 'Please sign this contract', // default invitation message
     * ]
     * ```
     *
     * Response (unwrapped `data`) — the template after the change, same shape as
     * {@see self::get()}:
     * ```
     * [
     *   'resource'      => 'template',
     *   'id'            => '10414160b9d1a5ff705effd35c43',
     *   'name'          => 'Service agreement (2026)',
     *   'document_name' => 'Acme — service agreement',
     *   'message'       => 'Please sign this contract',
     *   'status'        => 'Ready',
     *   'pages'         => [ … ],
     *   'roles'         => [ … ],
     *   'updated_at'    => '2026-08-27T15:02:11Z',
     * ]
     * ```
     *
     * This route is not part of the published OpenAPI contract but exists on the live API.
     *
     * @param array<string, mixed> $data subset of { name, document_name, message }
     * @return array<string, mixed> the updated template
     * @throws ValidationException when `$data` is empty or `$templateId` is empty
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
     * Documents already created from the template are unaffected; they keep their
     * `template_id` even though the template is gone.
     *
     * Request: no body.
     *
     * Response (unwrapped `data`; empty on success):
     * ```
     * []
     * ```
     *
     * This route is not part of the published OpenAPI contract but exists on the live API.
     *
     * @return array<array-key, mixed>
     * @throws ValidationException when `$templateId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the template does not exist
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
     * The page IDs come from the `pages` array on the {@see get()} response. The `width` and
     * `height` reported there are the coordinate space field placements use.
     *
     * Request: no parameters.
     *
     * Response: raw `image/jpeg` bytes — not the JSON envelope.
     *
     * This route is not part of the published OpenAPI contract but exists on the live API.
     *
     * @return string raw JPEG bytes
     * @throws ValidationException when either identifier is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 before rendering finishes
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
     * Poll {@see self::get()} until the template finishes processing.
     *
     * Client-side helper, not an API endpoint — the mirror of
     * {@see DocumentResource::waitUntilReady()} for templates. Page rendering after
     * {@see self::create()} is asynchronous, and `pages` stays empty until it completes:
     * ```php
     * $template = $client->templates()->create('/path/agreement.pdf');
     * $ready    = $client->templates()->waitUntilReady($template['id']);
     * ```
     *
     * Returns as soon as `status` is `Ready` (compared case-insensitively — the API sends
     * template statuses in PascalCase, unlike document statuses), throws on `Failed` or
     * `processing_failed`, and otherwise sleeps and retries. The deadline is checked between
     * calls; an in-flight request is bounded separately by the configured transport timeout.
     *
     * Response: the same payload as {@see self::get()}, once it is ready.
     *
     * @param int $maxWaitSeconds      total budget before giving up
     * @param int $pollIntervalSeconds delay between polls; the last sleep is trimmed so the
     *     helper never overshoots the deadline
     * @return array<string, mixed> the ready template
     * @throws ValidationException when either interval is not a positive integer
     * @throws \RuntimeException on a failed template or on timeout
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
