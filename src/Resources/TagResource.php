<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Tags resource — workspace-scoped labels under `/accounts/{account_id}/tags`.
 *
 * Tags are attached to documents (see {@see DocumentResource::appendTags()}) and
 * templates to make them easier to find. Tag names are unique per workspace,
 * case-insensitive; the API trims and collapses internal whitespace before storage.
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class TagResource extends AbstractResource
{
    /**
     * List the workspace's tags, ordered alphabetically by name.
     * `GET /accounts/{account_id}/tags`
     *
     * This endpoint is not paginated; the full tag list is returned as a flat array.
     *
     * Request (query string): `search`, sent only when supplied and non-empty.
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   [
     *     'id'         => '103aa221874346e6b3de41688526',
     *     'name'       => 'contracts',
     *     'color'      => null,          // 6-char hex without '#', or null
     *     'created_at' => '2026-07-18T19:03:45Z',
     *     'updated_at' => '2026-07-18T19:03:45Z',
     *   ],
     * ]
     * ```
     *
     * @param string|null $search optional case-insensitive substring filter on the tag name
     * @return array<int, array<string, mixed>>
     */
    public function list(?string $search = null): array
    {
        $params = [];
        if ($search !== null && $search !== '') {
            $params['search'] = $search;
        }

        $response = $this->httpClient->get($this->accountPath('tags'), $params);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Create a new tag in the workspace.
     * `POST /accounts/{account_id}/tags`
     *
     * Explicit creation is only needed when you want to set a `color`:
     * {@see DocumentResource::appendTags()} auto-creates unknown names on attach.
     *
     * Request body:
     * ```
     * ['name' => 'contracts', 'color' => '2072b9']   // color optional; '#2072b9' also accepted
     * ```
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'id'         => '103aa221874346e6b3de41688526',
     *   'name'       => 'contracts',
     *   'color'      => '2072b9',
     *   'created_at' => '2026-07-18T19:03:45Z',
     *   'updated_at' => '2026-07-18T19:03:45Z',
     * ]
     * ```
     *
     * @param string      $name  display name; trimmed, whitespace-collapsed, max 64 chars
     * @param string|null $color 6-character hex color (with or without leading `#`); null for none
     * @return array<string, mixed> the created tag
     *
     * @throws ValidationException when the name is empty, over 64 characters, or the color
     *     is not 6 hex characters
     * @throws \Assinafy\SDK\Exceptions\ApiException 409 when the name is already taken
     */
    public function create(string $name, ?string $color = null): array
    {
        $this->assertName($name);

        $payload = ['name' => $name];
        if ($color !== null) {
            $this->assertColor($color);
            $payload['color'] = $color;
        }

        $response = $this->httpClient->post($this->accountPath('tags'), $payload);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Update a tag's name and/or color.
     * `PUT /accounts/{account_id}/tags/{tag_id}`
     *
     * Either field may be omitted to leave it untouched. Pass `color: null` to clear
     * the color. Returns 409 Conflict (an {@see \Assinafy\SDK\Exceptions\ApiException})
     * if another tag already uses the new name.
     *
     * Renaming updates the tag everywhere it is attached — documents keep the association.
     *
     * Request body (at least one key required):
     * ```
     * ['name' => 'signed-contracts', 'color' => null]   // null clears the color
     * ```
     *
     * Response (unwrapped `data`) — the tag after the change:
     * ```
     * [
     *   'id'         => '103aa221874346e6b3de41688526',
     *   'name'       => 'signed-contracts',
     *   'color'      => null,
     *   'created_at' => '2026-07-18T19:03:45Z',
     *   'updated_at' => '2026-08-27T15:02:11Z',
     * ]
     * ```
     *
     * @param array<string, mixed> $data subset of `{ name, color }`
     * @return array<string, mixed> the updated tag
     *
     * @throws ValidationException when no updatable field is provided, or a value is malformed
     * @throws \Assinafy\SDK\Exceptions\ApiException 409 when the new name is already taken
     */
    public function update(string $tagId, array $data): array
    {
        if (!array_key_exists('name', $data) && !array_key_exists('color', $data)) {
            throw new ValidationException('Provide at least one of name or color to update');
        }

        if (array_key_exists('name', $data)) {
            if (!is_string($data['name'])) {
                throw new ValidationException('Tag name must be a string');
            }
            $this->assertName($data['name']);
        }

        if (array_key_exists('color', $data) && $data['color'] !== null) {
            if (!is_string($data['color'])) {
                throw new ValidationException('Tag color must be a string or null');
            }
            $this->assertColor($data['color']);
        }

        $tagId = $this->pathSegment($tagId, 'tag ID');

        $response = $this->httpClient->put($this->accountPath("tags/{$tagId}"), $data);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete a tag.
     * `DELETE /accounts/{account_id}/tags/{tag_id}`
     *
     * By default the API refuses with 409 Conflict if the tag is still attached to any
     * document or template. Pass `$force = true` to detach it from everything first; the
     * documents and templates themselves are not deleted.
     *
     * To remove a tag from one document while leaving it in the workspace, use
     * {@see DocumentResource::detachTag()} instead.
     *
     * Request (query string): `force=true`, sent only when `$force` is set. No body.
     *
     * Response (unwrapped `data`; empty on success):
     * ```
     * []
     * ```
     *
     * @param bool $force detach the tag from every document and template first
     * @return array<array-key, mixed>
     * @throws ValidationException when `$tagId` is empty
     * @throws \Assinafy\SDK\Exceptions\ApiException 409 when the tag is in use and `$force`
     *     is false
     */
    public function delete(string $tagId, bool $force = false): array
    {
        $tagId = $this->pathSegment($tagId, 'tag ID');
        $query = $force ? ['force' => 'true'] : [];

        $response = $this->httpClient->delete($this->accountPath("tags/{$tagId}"), [], $query);

        return $this->extractData($response->getData() ?? []);
    }

    private function assertName(string $name): void
    {
        if (trim($name) === '') {
            throw new ValidationException('Tag name cannot be empty', ['name' => $name]);
        }

        if (mb_strlen($name) > 64) {
            throw new ValidationException('Tag name cannot exceed 64 characters', ['name' => $name]);
        }
    }

    private function assertColor(string $color): void
    {
        if (preg_match('/^#?[0-9a-f]{6}$/i', $color) !== 1) {
            throw new ValidationException('Tag color must be a 6-character hex value', [
                'color' => $color,
            ]);
        }
    }
}
