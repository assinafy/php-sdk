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
 * `validateMultiple` endpoints remain workspace-authenticated in the published
 * OpenAPI contract. Pass `$signerAccessCode` only when the workflow also needs
 * signer context; it does not replace API-key or bearer authentication.
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
     * @param array<string, mixed> $options optional `regex` and `is_required`
     * @return array<string, mixed> the created field definition
     *
     * @throws ValidationException when type or name is empty
     */
    public function create(string $type, string $name, array $options = []): array
    {
        if (trim($type) === '') {
            throw new ValidationException('Field type is required', ['type' => $type]);
        }
        if (trim($name) === '') {
            throw new ValidationException('Field name is required', ['name' => $name]);
        }

        unset($options['type'], $options['name']);
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
     *
     * @return array<string, mixed>
     */
    public function get(string $fieldId): array
    {
        $fieldId = $this->pathSegment($fieldId, 'field ID');
        $response = $this->httpClient->get($this->accountPath("fields/{$fieldId}"));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Update a field definition.
     * `PUT /accounts/{account_id}/fields/{field_id}`
     *
     * @param array<string, mixed> $data subset of `{ name, regex, is_active }`
     * @return array<string, mixed> the updated field definition
     */
    public function update(string $fieldId, array $data): array
    {
        if ($data === []) {
            throw new ValidationException('Provide at least one field property to update');
        }

        $fieldId = $this->pathSegment($fieldId, 'field ID');
        $response = $this->httpClient->put($this->accountPath("fields/{$fieldId}"), $data);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete a field definition. A field already used in a document cannot be deleted.
     * `DELETE /accounts/{account_id}/fields/{field_id}`
     *
     * @return array<array-key, mixed>
     */
    public function delete(string $fieldId): array
    {
        $fieldId = $this->pathSegment($fieldId, 'field ID');
        $response = $this->httpClient->delete($this->accountPath("fields/{$fieldId}"));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Validate a single input value against a field definition.
     * `POST /accounts/{account_id}/fields/{field_id}/validate`
     *
     * @param string|null $signerAccessCode optional signer context in addition to
     *                                       workspace authentication
     * @return array<string, mixed> validation result
     */
    public function validate(
        string $fieldId,
        #[\SensitiveParameter] mixed $value,
        #[\SensitiveParameter] ?string $signerAccessCode = null
    ): array {
        $fieldId = $this->pathSegment($fieldId, 'field ID');
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
     * @param string|null                                       $signerAccessCode optional signer
     *     context in addition to workspace authentication
     * @return array<int, array<string, mixed>>
     */
    public function validateMultiple(
        #[\SensitiveParameter] array $values,
        #[\SensitiveParameter] ?string $signerAccessCode = null
    ): array {
        $response = $this->httpClient->post(
            $this->accountPath('fields/validate-multiple'),
            array_values($values),
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
}
