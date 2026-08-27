<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Signers resource — every endpoint under `/accounts/{account_id}/signers`.
 *
 * Signer-facing endpoints (the ones consumed by the end-signer in the browser, e.g.
 * `/signers/self`, `/signers/accept-terms`, `/signature`) are intentionally NOT exposed
 * here: they require a `signer-access-code` rather than an account API key.
 */
class SignerResource extends AbstractResource
{
    /**
     * Create a signer.
     * `POST /accounts/{account_id}/signers`
     *
     * Only `full_name` is required by the API. `email` and `whatsapp_phone_number`
     * are optional but at least one is needed for any verification/notification — a signer
     * with neither can never be notified.
     *
     * Signers are workspace-level and reusable across documents. To avoid duplicates, look
     * first with {@see self::findByEmail()}.
     *
     * Phone numbers are normalised to E.164 locally: a leading `+` and country code are
     * mandatory, so a local number is never silently assigned to the wrong country.
     *
     * Request body:
     * ```
     * [
     *   'full_name'             => 'Jane Doe',        // required
     *   'email'                 => 'jane@example.com',
     *   'whatsapp_phone_number' => '+5548999990000',  // E.164
     * ]
     * ```
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'resource'              => 'signer',
     *   'id'                    => '19e6b92e7895332ed9708535d8c',
     *   'full_name'             => 'Jane Doe',
     *   'email'                 => 'jane@example.com',
     *   'whatsapp_phone_number' => '+5548999990000',
     *   'has_accepted_terms'    => false,
     * ]
     * ```
     *
     * `government_id` cannot be set here — add it afterwards with {@see self::update()},
     * which digital-certificate signing requires.
     *
     * @return array<string, mixed> the created signer
     * @throws ValidationException on an empty name, a malformed email, or a phone number
     *     without a country code
     */
    public function create(
        #[\SensitiveParameter] string $fullName,
        #[\SensitiveParameter] ?string $email = null,
        #[\SensitiveParameter] ?string $whatsappPhoneNumber = null
    ): array {
        if (trim($fullName) === '') {
            throw new ValidationException('full_name is required', ['full_name' => $fullName]);
        }

        if ($email !== null) {
            $this->validateEmail($email);
        }

        $payload = ['full_name' => $fullName];

        if ($email !== null) {
            $payload['email'] = $email;
        }

        if ($whatsappPhoneNumber !== null) {
            $payload['whatsapp_phone_number'] = self::normalizePhoneNumber($whatsappPhoneNumber);
        }

        $response = $this->httpClient->post($this->accountPath('signers'), $payload);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Retrieve a signer.
     * `GET /accounts/{account_id}/signers/{signer_id}`
     *
     * Request: no parameters.
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'resource'              => 'signer',
     *   'id'                    => '19e6b92e7895332ed9708535d8c',
     *   'full_name'             => 'Jane Doe',
     *   'email'                 => 'jane@example.com',
     *   'whatsapp_phone_number' => null,
     *   'has_accepted_terms'    => true,
     * ]
     * ```
     *
     * @return array<string, mixed>
     * @throws ValidationException when `$signerId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the signer does not exist
     */
    public function get(string $signerId): array
    {
        $signerId = $this->pathSegment($signerId, 'signer ID');
        $response = $this->httpClient->get($this->accountPath("signers/{$signerId}"));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List signers in the workspace.
     * `GET /accounts/{account_id}/signers`
     *
     * `$search` matches name and email substrings. For an exact email lookup use
     * {@see self::findByEmail()}, which pages through and compares case-insensitively.
     *
     * Request (query string): `page`, `per-page`, and `search` when supplied.
     *
     * Response (full envelope — pagination lifted from the `X-Pagination-*` headers):
     * ```
     * [
     *   'status'  => 200,
     *   'message' => '',
     *   'data'    => [
     *     [
     *       'id'                    => '19e6b92e7895332ed9708535d8c',
     *       'full_name'             => 'Jane Doe',
     *       'email'                 => 'jane@example.com',
     *       'whatsapp_phone_number' => null,
     *       'has_accepted_terms'    => true,
     *     ],
     *   ],
     *   'pagination' => ['current_page' => 1, 'page_count' => 3, 'per_page' => 20, 'total_count' => 47],
     * ]
     * ```
     *
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     *     full envelope with pagination lifted from response headers
     * @throws ValidationException when `$page` < 1 or `$perPage` is outside 1–100
     */
    public function list(
        int $page = 1,
        int $perPage = 20,
        #[\SensitiveParameter] ?string $search = null
    ): array {
        $params = $this->paginationQuery($page, $perPage);

        if ($search !== null && $search !== '') {
            $params['search'] = $search;
        }

        return $this->withPagination($this->httpClient->get($this->accountPath('signers'), $params));
    }

    /**
     * Update a signer.
     * `PUT /accounts/{account_id}/signers/{signer_id}`
     *
     * Send only the keys you want to change. This is the only way to set `government_id`,
     * which must be present **before** a digital-certificate assignment can be created for
     * this signer.
     *
     * `whatsapp_phone_number` is normalised to E.164 locally, exactly as in
     * {@see self::create()}.
     *
     * Request body (at least one key required):
     * ```
     * [
     *   'full_name'             => 'Jane A. Doe',
     *   'email'                 => 'jane@example.com',
     *   'whatsapp_phone_number' => '+5548999990000',
     *   'government_id'         => '11144477735',
     * ]
     * ```
     *
     * Response (unwrapped `data`) — the signer after the change:
     * ```
     * [
     *   'resource'              => 'signer',
     *   'id'                    => '19e6b92e7895332ed9708535d8c',
     *   'full_name'             => 'Jane A. Doe',
     *   'email'                 => 'jane@example.com',
     *   'whatsapp_phone_number' => '+5548999990000',
     *   'has_accepted_terms'    => true,
     * ]
     * ```
     *
     * @param array<string, mixed> $data subset of { full_name, email, whatsapp_phone_number,
     *     government_id }
     * @return array<string, mixed> the updated signer
     * @throws ValidationException when `$data` is empty or any supplied value is malformed
     */
    public function update(string $signerId, #[\SensitiveParameter] array $data): array
    {
        if ($data === []) {
            throw new ValidationException('Provide at least one signer field to update');
        }

        if (array_key_exists('full_name', $data)) {
            if (!is_string($data['full_name']) || trim($data['full_name']) === '') {
                throw new ValidationException('full_name must be a non-empty string');
            }
        }

        if (array_key_exists('whatsapp_phone_number', $data)) {
            if (!is_string($data['whatsapp_phone_number'])) {
                throw new ValidationException('whatsapp_phone_number must be a string');
            }
            $data['whatsapp_phone_number'] = self::normalizePhoneNumber($data['whatsapp_phone_number']);
        }

        if (array_key_exists('email', $data)) {
            if (!is_string($data['email'])) {
                throw new ValidationException('email must be a string');
            }
            $this->validateEmail($data['email']);
        }

        if (
            array_key_exists('government_id', $data)
            && (!is_string($data['government_id']) || trim($data['government_id']) === '')
        ) {
            throw new ValidationException('government_id must be a non-empty string');
        }

        $signerId = $this->pathSegment($signerId, 'signer ID');
        $response = $this->httpClient->put($this->accountPath("signers/{$signerId}"), $data);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete a signer.
     * `DELETE /accounts/{account_id}/signers/{signer_id}`
     *
     * Removes the signer from the workspace directory. Documents they have already signed
     * keep their record of the signature — the audit trail is not rewritten.
     *
     * Request: no body.
     *
     * Response (full envelope; `data` is empty because the resource is gone):
     * ```
     * ['status' => 200, 'message' => '', 'data' => []]
     * ```
     *
     * @return array<array-key, mixed> the raw envelope
     * @throws ValidationException when `$signerId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 400 when an assignment is still pending
     */
    public function delete(string $signerId): array
    {
        $signerId = $this->pathSegment($signerId, 'signer ID');
        $response = $this->httpClient->delete($this->accountPath("signers/{$signerId}"));

        return $response->getData() ?? [];
    }

    /**
     * Find a signer by email by searching the workspace.
     * Returns the first exact-match (case-insensitive) signer, or null if none found.
     *
     * Client-side helper over `GET /accounts/{account_id}/signers`, not a dedicated
     * endpoint. It pages through the `search` results 100 at a time and compares each
     * `email` exactly, because the API's `search` is a substring match and would otherwise
     * return `jane@example.com.br` for `jane@example.com`.
     *
     * Use it to keep {@see self::create()} idempotent:
     * ```php
     * $signer = $client->signers()->findByEmail('jane@example.com')
     *     ?? $client->signers()->create('Jane Doe', 'jane@example.com');
     * ```
     *
     * Costs one request per 100 matches — usually one.
     *
     * Response: the same entry shape as {@see self::list()}, or `null`:
     * ```
     * [
     *   'id'                    => '19e6b92e7895332ed9708535d8c',
     *   'full_name'             => 'Jane Doe',
     *   'email'                 => 'jane@example.com',
     *   'whatsapp_phone_number' => null,
     *   'has_accepted_terms'    => true,
     * ]
     * ```
     *
     * @return array<string, mixed>|null the matching signer, or null when there is none
     * @throws ValidationException on a malformed email
     */
    public function findByEmail(#[\SensitiveParameter] string $email): ?array
    {
        $this->validateEmail($email);

        $page = 1;

        do {
            $result = $this->withPagination($this->httpClient->get($this->accountPath('signers'), [
                'search' => $email,
                'page' => $page,
                'per-page' => 100,
            ]));

            foreach ($result['data'] ?? [] as $signer) {
                if (isset($signer['email']) && strcasecmp((string) $signer['email'], $email) === 0) {
                    return $signer;
                }
            }

            $pageCount = (int) ($result['pagination']['page_count'] ?? $page);
            $page++;
        } while ($page <= $pageCount);

        return null;
    }

    private function validateEmail(#[\SensitiveParameter] string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Invalid email address', ['email' => $email]);
        }
    }

    /**
     * Normalize explicitly international phone input into E.164 (e.g. `+5548999990000`).
     * Common visual separators are removed, but a leading `+` and country code are
     * mandatory so a local number is never silently assigned to the wrong country.
     */
    public static function normalizePhoneNumber(#[\SensitiveParameter] string $phone): string
    {
        $phone = trim($phone);
        if (preg_match('/^\+[0-9\s().-]+$/', $phone) !== 1) {
            throw new ValidationException('Invalid phone number', ['phone' => $phone]);
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (preg_match('/^[1-9]\d{7,14}$/', $digits) !== 1) {
            throw new ValidationException('Phone number must contain a country code and 8 to 15 digits', [
                'phone' => $phone,
            ]);
        }

        return '+' . $digits;
    }
}
