<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Contract tracking for documents in the configured workspace.
 *
 * A document may hold the root contract terms or be linked as an amendment, renewal,
 * or termination. Reading requires `documents:read`; writes require `documents:write`
 * for OAuth clients and a plan that includes contract tracking.
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class ContractResource extends AbstractResource
{
    /**
     * List tracked contracts in the workspace.
     * `GET /accounts/{account_id}/contracts`
     *
     * Request query example:
     * ```php
     * [
     *     'page' => 1,
     *     'per-page' => 25,
     *     'status' => 'active,expired',
     *     'counterparty' => 'Example',
     *     'search' => 'services',
     *     'end_date_from' => '2026-11-01',
     *     'end_date_to' => '2027-10-31',
     *     'ending_within_days' => 90,
     *     'external_system' => 'erp',
     *     'external_id' => 'C-1042',
     *     'sort' => '-updated_at',
     *     'expand' => 'document',
     * ]
     * ```
     *
     * Example response (SDK return):
     * ```php
     * [
     *     'status' => 200,
     *     'message' => '',
     *     'data' => [[
     *         'resource' => 'document_contract',
     *         'document_id' => 'document-id',
     *         'status' => 'active',
     *         'counterparty_name' => 'Example Company',
     *         'counterparty_taxpayer_id' => '11222333000181',
     *         'value' => 120000.50,
     *         'currency' => 'BRL',
     *         'start_date' => '2026-11-01',
     *         'end_date' => '2027-10-31',
     *         'auto_renew' => true,
     *         'renewal_term_months' => 12,
     *         'notice_period_days' => 30,
     *         'term' => 1,
     *         'owner_id' => 'user-id',
     *         'metadata' => ['crm_stage' => 'won'],
     *         'external_references' => [['system' => 'erp', 'id' => 'C-1042']],
     *         'created_at' => '2026-10-23T12:00:00Z',
     *         'updated_at' => '2026-10-23T12:00:00Z',
     *     ]],
     *     'pagination' => [
     *         'current_page' => 1,
     *         'page_count' => 1,
     *         'per_page' => 25,
     *         'total_count' => 1,
     *     ],
     * ]
     * ```
     *
     * @param array<string, scalar> $filters optional `status`, `counterparty`, `search`,
     *     `end_date_from`, `end_date_to`, `ending_within_days`, `external_system`,
     *     `external_id`, `sort`, and `expand` filters
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     * @throws ValidationException when `$page` < 1 or `$perPage` is outside 1–100
     */
    public function list(int $page = 1, int $perPage = 25, array $filters = []): array
    {
        return $this->withPagination($this->httpClient->get(
            $this->accountPath('contracts'),
            $this->paginationQuery($page, $perPage, $filters)
        ));
    }

    /**
     * Read the terms or amendment link tracked on a document.
     * `GET /accounts/{account_id}/documents/{document_id}/contract`
     *
     * Request: no query or body.
     *
     * Example root-contract response (SDK return):
     * ```php
     * [
     *     'resource' => 'document_contract',
     *     'document_id' => 'document-id',
     *     'status' => 'active',
     *     'counterparty_name' => 'Example Company',
     *     'counterparty_taxpayer_id' => '11222333000181',
     *     'value' => 120000.50,
     *     'currency' => 'BRL',
     *     'start_date' => '2026-11-01',
     *     'end_date' => '2027-10-31',
     *     'auto_renew' => true,
     *     'renewal_term_months' => 12,
     *     'notice_period_days' => 30,
     *     'term' => 1,
     *     'owner_id' => 'user-id',
     *     'metadata' => ['crm_stage' => 'won'],
     *     'external_references' => [['system' => 'erp', 'id' => 'C-1042']],
     *     'amendments' => [],
     *     'created_at' => '2026-10-23T12:00:00Z',
     *     'updated_at' => '2026-10-23T12:00:00Z',
     * ]
     * ```
     *
     * An amendment document returns `resource: document_contract_amendment` with
     * `parent_document_id`, `relation_type`, `status`, `document_status`, `terms`,
     * `changes`, `apply_error`, and `applied_at` instead.
     *
     * @return array<string, mixed> the contract terms or amendment link
     * @throws ValidationException when `$documentId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the document has no contract terms
     */
    public function get(string $documentId): array
    {
        $response = $this->httpClient->get($this->contractPath($documentId));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Create or partially update contract terms, or link a document as an amendment.
     * `PUT /accounts/{account_id}/documents/{document_id}/contract`
     *
     * Request body example for root terms:
     * ```php
     * [
     *     'status' => 'active',
     *     'counterparty_name' => 'Example Company',
     *     'counterparty_taxpayer_id' => '11.222.333/0001-81',
     *     'value' => 120000.50,
     *     'currency' => 'BRL',
     *     'start_date' => '2026-11-01',
     *     'end_date' => '2027-10-31',
     *     'auto_renew' => true,
     *     'renewal_term_months' => 12,
     *     'notice_period_days' => 30,
     *     'owner_id' => 'user-id',
     *     'metadata' => ['crm_stage' => 'won'],
     *     'external_references' => [['system' => 'erp', 'id' => 'C-1042']],
     *     'updated_at' => '2026-10-23T12:00:00Z',
     * ]
     * ```
     *
     * Link an amendment with `parent_document_id`, `relation_type` (`amendment`, `renewal`,
     * or `termination`) and the changed terms. `external_references` replaces the list;
     * `parent_document_id: null` unlinks a pending amendment. Send the last `updated_at`
     * to make concurrent changes fail with 409.
     *
     * Example response (SDK return): the `document_contract` shape from {@see self::get()},
     * or this amendment shape:
     * ```php
     * [
     *     'resource' => 'document_contract_amendment',
     *     'document_id' => 'amendment-document-id',
     *     'document_name' => 'Amendment 1.pdf',
     *     'parent_document_id' => 'document-id',
     *     'relation_type' => 'amendment',
     *     'number' => null,
     *     'status' => 'pending',
     *     'document_status' => 'pending_signature',
     *     'apply_error' => null,
     *     'currency' => 'BRL',
     *     'terms' => ['value' => 130000, 'end_date' => '2028-06-30'],
     *     'changes' => ['value' => ['from' => 120000.50, 'to' => 130000]],
     *     'applied_at' => null,
     *     'created_at' => '2027-05-10T15:00:00Z',
     *     'updated_at' => '2027-05-10T15:00:00Z',
     * ]
     * ```
     *
     * @param array<string, mixed> $data at least one documented contract or amendment field
     * @return array<string, mixed> the saved contract terms or amendment link
     * @throws ValidationException when `$documentId` or `$data` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 403 when the plan lacks contract tracking,
     *     409 when `updated_at` no longer matches
     */
    public function update(string $documentId, array $data): array
    {
        if ($data === []) {
            throw new ValidationException('At least one contract field is required');
        }

        $response = $this->httpClient->put($this->contractPath($documentId), $data);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Read the root contract and every related amendment, renewal, or termination document.
     * `GET /accounts/{account_id}/documents/{document_id}/contract/related`
     *
     * Request: no query or body. `$documentId` may identify the root or any related document.
     * The response is the root `document_contract` shape from {@see self::get()}, including
     * its complete `amendments` array.
     *
     * @return array<string, mixed> the root contract and its related documents
     * @throws ValidationException when `$documentId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the document has no contract family
     */
    public function related(string $documentId): array
    {
        $response = $this->httpClient->get($this->contractPath($documentId, '/related'));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Start the contract's next term.
     * `POST /accounts/{account_id}/documents/{document_id}/contract/renew`
     *
     * Request body examples:
     * ```php
     * ['term' => 1, 'value' => 126000, 'updated_at' => '2026-10-23T12:00:00Z']
     * ['end_date' => '2029-06-30', 'value' => 130000]
     * ```
     *
     * With no explicit `end_date`, send the current `term`; the API advances by
     * `renewal_term_months` (12 when unset). A terminated contract cannot be renewed.
     * The SDK returns the updated `document_contract` shape from {@see self::get()}.
     *
     * @param array<string, mixed> $data optional `end_date`, `value`, `term`, and `updated_at`
     * @return array<string, mixed> the renewed contract
     * @throws ValidationException when `$documentId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 400 for an invalid/repeated renewal,
     *     403 when the plan lacks contract tracking, or 409 on a stale `updated_at`
     */
    public function renew(string $documentId, array $data = []): array
    {
        $response = $this->httpClient->post(
            $this->contractPath($documentId, '/renew'),
            $data === [] ? null : $data
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Terminate a contract and stop its lifecycle alerts.
     * `POST /accounts/{account_id}/documents/{document_id}/contract/terminate`
     *
     * Request body example:
     * ```php
     * ['reason' => 'Supplier replaced', 'updated_at' => '2026-10-23T12:00:00Z']
     * ```
     *
     * Both fields are optional; `reason` is stored on the
     * `document_contract_terminated` event and is limited by the API to 500 characters.
     * The SDK returns the terminated `document_contract` shape from {@see self::get()}.
     *
     * @param array<string, mixed> $data optional `reason` and `updated_at`
     * @return array<string, mixed> the terminated contract
     * @throws ValidationException when `$documentId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 403 when the plan lacks contract tracking,
     *     or 409 on a stale `updated_at`
     */
    public function terminate(string $documentId, array $data = []): array
    {
        $response = $this->httpClient->post(
            $this->contractPath($documentId, '/terminate'),
            $data === [] ? null : $data
        );

        return $this->extractData($response->getData() ?? []);
    }

    private function contractPath(string $documentId, string $suffix = ''): string
    {
        $documentId = $this->pathSegment($documentId, 'document ID');

        return $this->accountPath("documents/{$documentId}/contract") . $suffix;
    }
}
