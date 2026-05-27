<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Field definitions resource — `/accounts/{account_id}/fields` plus the global
 * `/field-types` catalog.
 *
 * Field definitions describe the inputs (text, CPF, e-mail, date, …) that can be
 * placed on a document when building a `collect` assignment. The `validate` and
 * `validateMultiple` endpoints can be called either by an authenticated account
 * user (default) or by a signer — pass `$signerAccessCode` for the latter.
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class FieldResource extends AbstractResource
{
    /**
     * Create a field definition.
     * `POST /accounts/{account_id}/fields`
     *
     * @param string               $type    field type code (see {@see types()})
     * @param string               $name    label shown for the input
     * @param array<string, mixed> $options optional `regex`, `is_required`, `is_active`
     *
     * @throws ValidationException when type or name is empty
     */
    public function create(string $type, string $name, array $options = []): array
    {
        if ($type === '') {
            throw new ValidationException('Field type is required', ['type' => $type]);
        }
        if ($name === '') {
            throw new ValidationException('Field name is required', ['name' => $name]);
        }

        $payload = array_merge(['type' => $type, 'name' => $name], $options);

        $response = $this->httpClient->post($this->accountPath('fields'), $payload);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List field definitions.
     * `GET /accounts/{account_id}/fields`
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(bool $includeInactive = false, bool $includeStandard = false): array
    {
        $params = [];
        if ($includeInactive) {
            $params['include_inactive'] = 'true';
        }
        if ($includeStandard) {
            $params['include_standard'] = 'true';
        }

        $response = $this->httpClient->get($this->accountPath('fields'), $params);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Retrieve a single field definition.
     * `GET /accounts/{account_id}/fields/{field_id}`
     */
    public function get(string $fieldId): array
    {
        $response = $this->httpClient->get($this->accountPath("fields/{$fieldId}"));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Update a field definition.
     * `PUT /accounts/{account_id}/fields/{field_id}`
     *
     * @param array<string, mixed> $data subset of `{ type, name, regex, is_required, is_active }`
     */
    public function update(string $fieldId, array $data): array
    {
        $response = $this->httpClient->put($this->accountPath("fields/{$fieldId}"), $data);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete a field definition. A field already used in a document cannot be deleted.
     * `DELETE /accounts/{account_id}/fields/{field_id}`
     */
    public function delete(string $fieldId): array
    {
        $response = $this->httpClient->delete($this->accountPath("fields/{$fieldId}"));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Validate a single input value against a field definition.
     * `POST /accounts/{account_id}/fields/{field_id}/validate`
     *
     * @param string|null $signerAccessCode pass when validating as a signer rather than
     *                                       as an authenticated account user
     */
    public function validate(string $fieldId, string $value, ?string $signerAccessCode = null): array
    {
        $response = $this->httpClient->post(
            $this->accountPath("fields/{$fieldId}/validate"),
            ['value' => $value],
            [],
            $this->accessCodeQuery($signerAccessCode)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Validate several input values at once.
     * `POST /accounts/{account_id}/fields/validate-multiple`
     *
     * @param array<int, array{field_id: string, value: mixed}> $values
     * @param string|null                                       $signerAccessCode pass when
     *     validating as a signer rather than as an authenticated account user
     * @return array<int, array<string, mixed>>
     */
    public function validateMultiple(array $values, ?string $signerAccessCode = null): array
    {
        $response = $this->httpClient->post(
            $this->accountPath('fields/validate-multiple'),
            $values,
            [],
            $this->accessCodeQuery($signerAccessCode)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List the field types supported by the platform.
     * `GET /field-types` (not account-scoped).
     *
     * @return array<int, array{type: string, name: string}>
     */
    public function types(): array
    {
        $response = $this->httpClient->get('field-types');

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * @return array<string, string>
     */
    private function accessCodeQuery(?string $signerAccessCode): array
    {
        return $signerAccessCode !== null ? ['signer-access-code' => $signerAccessCode] : [];
    }
}
