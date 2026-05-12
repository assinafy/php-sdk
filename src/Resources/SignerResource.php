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
     */
    public function create(
        string $fullName,
        ?string $email = null,
        ?string $whatsappPhoneNumber = null
    ): array {
        if ($fullName === '') {
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
            $payload['whatsapp_phone_number'] = $this->normalizePhone($whatsappPhoneNumber);
        }

        $response = $this->httpClient->post($this->accountPath('signers'), $payload);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Retrieve a signer.
     * `GET /accounts/{account_id}/signers/{signer_id}`
     */
    public function get(string $signerId): array
    {
        $response = $this->httpClient->get($this->accountPath("signers/{$signerId}"));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List signers in the workspace.
     * `GET /accounts/{account_id}/signers`
     */
    public function list(int $page = 1, int $perPage = 20, ?string $search = null): array
    {
        $params = [
            'page' => $page,
            'per-page' => $perPage,
        ];

        if ($search !== null && $search !== '') {
            $params['search'] = $search;
        }

        $response = $this->httpClient->get($this->accountPath('signers'), $params);

        return $response->getData() ?? [];
    }

    /**
     * Update a signer.
     * `PUT /accounts/{account_id}/signers/{signer_id}`
     *
     * @param array<string, mixed> $data subset of { full_name, email, whatsapp_phone_number }
     */
    public function update(string $signerId, array $data): array
    {
        if (isset($data['whatsapp_phone_number'])) {
            $data['whatsapp_phone_number'] = $this->normalizePhone((string) $data['whatsapp_phone_number']);
        }

        if (isset($data['email'])) {
            $this->validateEmail((string) $data['email']);
        }

        $response = $this->httpClient->put($this->accountPath("signers/{$signerId}"), $data);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete a signer.
     * `DELETE /accounts/{account_id}/signers/{signer_id}`
     */
    public function delete(string $signerId): array
    {
        $response = $this->httpClient->delete($this->accountPath("signers/{$signerId}"));

        return $response->getData() ?? [];
    }

    /**
     * Find a signer by email by searching the workspace.
     * Returns the first exact-match (case-insensitive) signer, or null if none found.
     */
    public function findByEmail(string $email): ?array
    {
        $this->validateEmail($email);

        $response = $this->httpClient->get($this->accountPath('signers'), [
            'search' => $email,
            'per-page' => 100,
        ]);

        $signers = $response->getData()['data'] ?? [];

        foreach ($signers as $signer) {
            if (isset($signer['email']) && strcasecmp((string) $signer['email'], $email) === 0) {
                return $signer;
            }
        }

        return null;
    }

    private function validateEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Invalid email address', ['email' => $email]);
        }
    }

    /**
     * Normalize a phone number into E.164 (e.g. `+5548999990000`).
     * If the input already starts with `+`, it's preserved; otherwise we keep digits only
     * and prepend `+`. This matches the format the Assinafy API expects for `whatsapp_phone_number`.
     */
    private function normalizePhone(string $phone): string
    {
        $trimmed = trim($phone);
        $hasPlus = strncmp($trimmed, '+', 1) === 0;
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            throw new ValidationException('Invalid phone number', ['phone' => $phone]);
        }

        return ($hasPlus ? '+' : '+') . $digits;
    }
}
