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
     * are optional but at least one is needed for any verification/notification.
     *
     * @return array<string, mixed> the created signer
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
     * @return array<string, mixed>
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
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     *     full envelope with pagination lifted from response headers
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
     * @param array<string, mixed> $data subset of { full_name, email, whatsapp_phone_number }
     * @return array<string, mixed> the updated signer
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

        $signerId = $this->pathSegment($signerId, 'signer ID');
        $response = $this->httpClient->put($this->accountPath("signers/{$signerId}"), $data);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete a signer.
     * `DELETE /accounts/{account_id}/signers/{signer_id}`
     *
     * @return array<array-key, mixed>
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
     * @return array<string, mixed>|null
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
