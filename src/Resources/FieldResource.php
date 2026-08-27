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
     * Defines a reusable input for the workspace. The definition is what a `collect`
     * assignment places on a page — see {@see AssignmentResource::create()} `entries`.
     *
     * Typed fields (`cpf`, `cnpj`, `email`, `date`, …) validate their own format; add `regex`
     * only to narrow a `text` field further.
     *
     * Request body:
     * ```
     * [
     *   'type'        => 'text',            // required — a code from types()
     *   'name'        => 'Job title',       // required — the label the signer sees
     *   'regex'       => '^[A-Z].*$',       // optional extra constraint
     *   'is_required' => true,              // optional, defaults to false
     * ]
     * ```
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'id'             => '102d25a48bec03ebcf3b5f651998',
     *   'name'           => 'Job title',
     *   'type'           => 'text',
     *   'regex'          => '^[A-Z].*$',
     *   'is_pre_defined' => false,   // true for fields the platform ships
     *   'is_active'      => true,
     *   'is_required'    => true,
     *   'is_standard'    => false,   // true for signature/initial/signatureDate
     *   'is_read_only'   => false,   // true when the platform fills it in
     *   'is_visible'     => true,
     * ]
     * ```
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
     * Defaults to the active, non-standard fields — the ones a person would pick from when
     * preparing a document. Not paginated; the whole set comes back at once.
     *
     * Set `$includeStandard` to also get the platform's `signature`, `initial` and
     * `signatureDate` fields, which every `collect` assignment needs but which are hidden
     * from the default listing.
     *
     * Request (query string): `include_inactive=true`, `include_standard=true` — each sent
     * only when its flag is set.
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   [
     *     'id'             => '102d25a48bcf142065f2b06cf821',
     *     'name'           => 'Assinatura',
     *     'type'           => 'signature',
     *     'regex'          => null,
     *     'is_pre_defined' => true,
     *     'is_active'      => true,
     *     'is_required'    => true,
     *     'is_standard'    => true,
     *     'is_read_only'   => false,
     *     'is_visible'     => true,
     *   ],
     * ]
     * ```
     *
     * @param bool $includeInactive also return fields that have been deactivated
     * @param bool $includeStandard also return the platform's signature/initial fields
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
     * Request: no parameters.
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'id'             => '102d25a48bec03ebcf3b5f651998',
     *   'name'           => 'CPF',
     *   'type'           => 'cpf',
     *   'regex'          => null,
     *   'is_pre_defined' => true,
     *   'is_active'      => true,
     *   'is_required'    => false,
     *   'is_standard'    => false,
     *   'is_read_only'   => false,
     *   'is_visible'     => true,
     * ]
     * ```
     *
     * @return array<string, mixed>
     * @throws ValidationException when `$fieldId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when the field does not exist
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
     * `type` is immutable — create a new field instead of retyping one. Setting
     * `is_active => false` retires a field from the picker without breaking documents that
     * already use it; that is the graceful alternative to {@see self::delete()}.
     *
     * Request body (send only what changes):
     * ```
     * ['name' => 'Job title', 'regex' => null, 'is_active' => false]
     * ```
     *
     * Response (unwrapped `data`) — the field after the change:
     * ```
     * [
     *   'id'          => '102d25a48bec03ebcf3b5f651998',
     *   'name'        => 'Job title',
     *   'type'        => 'text',
     *   'regex'       => null,
     *   'is_active'   => false,
     *   'is_required' => true,
     *   'is_standard' => false,
     *   'is_visible'  => true,
     * ]
     * ```
     *
     * @param array<string, mixed> $data subset of `{ name, regex, is_active }`
     * @return array<string, mixed> the updated field definition
     * @throws ValidationException when `$data` is empty or `$fieldId` is empty
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
     * Because a field in use is undeletable, prefer `is_active => false` via
     * {@see self::update()} for anything that has ever been placed on a document.
     *
     * Request: no body.
     *
     * Response (unwrapped `data`; empty on success):
     * ```
     * []
     * ```
     *
     * @return array<array-key, mixed>
     * @throws ValidationException when `$fieldId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 400 when the field is in use
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
     * Runs the field's own rules (type check plus any `regex`) server-side, so a form can
     * give the same verdict the API will give at signing time. Punctuation is ignored for
     * `cpf`/`cnpj`.
     *
     * A failed validation is **not** an error: the call answers 200 and reports the verdict
     * in `success`. Branch on `success`, not on the HTTP status.
     *
     * Request body:
     * ```
     * ['value' => '111.444.777-35']
     * ```
     *
     * Response (unwrapped `data`) — valid:
     * ```
     * ['type' => 'cpf', 'success' => true, 'error_message' => '']
     * ```
     * …and invalid:
     * ```
     * ['type' => 'cpf', 'success' => false, 'error_message' => 'CPF inválido.']
     * ```
     *
     * @param mixed       $value            the input to check
     * @param string|null $signerAccessCode optional signer context in addition to
     *                                       workspace authentication
     * @return array<string, mixed> `{ type, success, error_message }`
     * @throws ValidationException when `$fieldId` is empty or the access code is blank
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
     * One round trip for a whole form. As with {@see self::validate()}, failures come back
     * as 200 with `success => false` — branch on the per-entry flag, not the HTTP status.
     *
     * The request body is a bare JSON **array**, not an object with a wrapper key. Results
     * come back in request order and each carries its `field_id`, so the same field may
     * appear more than once.
     *
     * Request body:
     * ```
     * [
     *   ['field_id' => '102d25a48bf5816b9029b0ca6043', 'value' => '111.444.777-35'],
     *   ['field_id' => '102d25a48bf5816b9029b0ca6043', 'value' => '123'],
     * ]
     * ```
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   ['field_id' => '102d25a48bf5816b9029b0ca6043', 'type' => 'cpf',
     *    'success' => true,  'error_message' => ''],
     *   ['field_id' => '102d25a48bf5816b9029b0ca6043', 'type' => 'cpf',
     *    'success' => false, 'error_message' => 'CPF inválido.'],
     * ]
     * ```
     *
     * @param array<int, array{field_id: string, value: mixed}> $values
     * @param string|null                                       $signerAccessCode optional signer
     *     context in addition to workspace authentication
     * @return array<int, array<string, mixed>> one verdict per input, in request order
     * @throws ValidationException when the access code is blank
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
     * The vocabulary for the `type` argument of {@see self::create()}. `name` is a
     * Portuguese display label; `type` is the code to send.
     *
     * `cpf` expects 11 digits. `cnpj` accepts 14 characters, where positions 1–12 may
     * include letters A–Z under the CNPJ Alfanumérico rule and the two check digits stay
     * numeric. Punctuation is ignored during validation.
     *
     * Request: no parameters.
     *
     * Response (unwrapped `data`, verbatim from the API):
     * ```
     * [
     *   ['type' => 'personName',  'name' => 'Nome'],
     *   ['type' => 'cpf',         'name' => 'CPF'],
     *   ['type' => 'phoneNumber', 'name' => 'Número de Telefone'],
     *   ['type' => 'postalCode',  'name' => 'CEP'],
     *   ['type' => 'email',       'name' => 'E-mail'],
     *   ['type' => 'cnpj',        'name' => 'CNPJ'],
     *   ['type' => 'companyName', 'name' => 'Nome da empresa'],
     *   ['type' => 'text',        'name' => 'Texto'],
     *   ['type' => 'number',      'name' => 'Número'],
     *   ['type' => 'date',        'name' => 'Data'],
     * ]
     * ```
     *
     * The live list repeats `email`; de-duplicate on `type` before rendering a picker.
     *
     * @return array<int, array{type: string, name: string}>
     */
    public function types(): array
    {
        $response = $this->httpClient->get('field-types');

        return $this->extractData($response->getData() ?? []);
    }
}
