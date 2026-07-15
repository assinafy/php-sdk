<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

/**
 * Accounts (workspaces) resource — covers every documented endpoint under `/accounts`.
 *
 * An "account" is a workspace: the container every document, signer, tag and field belongs to.
 * Each account-scoped resource in this SDK sends the account ID configured on
 * {@see \Assinafy\SDK\Configuration}; this resource is how you discover and manage those IDs.
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class AccountResource extends AbstractResource
{
    /**
     * Signers see the individual user as the sender of notifications. API default.
     */
    public const NOTIFICATION_SENDER_USER = 'User';

    /**
     * Signers see the account (workspace) name as the sender of notifications.
     */
    public const NOTIFICATION_SENDER_ACCOUNT = 'Account';

    /** Values accepted by `notification_sender_type`. Note the PascalCase — the API is strict. */
    private const NOTIFICATION_SENDER_TYPES = [
        self::NOTIFICATION_SENDER_USER,
        self::NOTIFICATION_SENDER_ACCOUNT,
    ];

    /**
     * List the accounts the authenticated credential belongs to.
     * `GET /accounts`
     *
     * This is the only documented way to discover an account ID, so it is deliberately NOT
     * account-scoped: it works on a client built with {@see \Assinafy\SDK\AssinafyClient::forAuth()}
     * — i.e. before you have an account ID to configure.
     *
     * Request: no parameters.
     *
     * Response (full envelope, not unwrapped):
     * ```
     * [
     *   'status'  => 200,
     *   'message' => '',
     *   'data'    => [
     *     [
     *       'id'                => '102d25a489f34a275d31a16045fd',
     *       'name'              => 'Acme Inc.',
     *       'roles'             => ['owner'],
     *       'is_delete_allowed' => true,
     *       'created_at'        => '2026-05-12T18:05:11Z',
     *     ],
     *   ],
     * ]
     * ```
     *
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>}
     */
    public function list(): array
    {
        return $this->withPagination($this->httpClient->get('accounts'));
    }

    /**
     * Retrieve the account this client is configured against.
     * `GET /accounts/{account_id}`
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'id'              => '102d25a489f34a275d31a16045fd',
     *   'name'            => 'Acme Inc.',
     *   'primary_color'   => null,   // hex without '#', e.g. '2072b9'
     *   'secondary_color' => null,
     *   'created_at'      => '2026-05-12T18:05:11Z',
     * ]
     * ```
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $response = $this->httpClient->get($this->accountPath());

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Create a new account (workspace).
     * `POST /accounts`
     *
     * Not account-scoped — callable before an account ID exists.
     *
     * Request body:
     * ```
     * ['name' => 'Acme Inc.', 'notification_sender_type' => 'User']  // name required
     * ```
     *
     * @param string|null $notificationSenderType one of the `NOTIFICATION_SENDER_*` constants
     * @return array<string, mixed> the created account
     * @throws \Assinafy\SDK\Exceptions\ValidationException on an unknown sender type
     */
    public function create(string $name, ?string $notificationSenderType = null): array
    {
        $payload = ['name' => $name];

        if ($notificationSenderType !== null) {
            self::assertNotificationSenderType($notificationSenderType);
            $payload['notification_sender_type'] = $notificationSenderType;
        }

        $response = $this->httpClient->post('accounts', $payload);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Update the configured account.
     * `PUT /accounts/{account_id}`
     *
     * Both fields are optional; send only what you want to change.
     *
     * @param string|null $notificationSenderType one of the `NOTIFICATION_SENDER_*` constants
     * @return array<string, mixed> the updated account
     * @throws \Assinafy\SDK\Exceptions\ValidationException when nothing was supplied to update
     */
    public function update(?string $name = null, ?string $notificationSenderType = null): array
    {
        $payload = [];

        if ($name !== null) {
            $payload['name'] = $name;
        }

        if ($notificationSenderType !== null) {
            self::assertNotificationSenderType($notificationSenderType);
            $payload['notification_sender_type'] = $notificationSenderType;
        }

        if ($payload === []) {
            throw new \Assinafy\SDK\Exceptions\ValidationException(
                'Nothing to update — pass $name and/or $notificationSenderType'
            );
        }

        $response = $this->httpClient->put($this->accountPath(), $payload);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete the configured account.
     * `DELETE /accounts/{account_id}`
     *
     * Destructive and irreversible: removes the workspace and every document, signer, tag
     * and field in it.
     *
     * Request body: `['force' => true]` — sent as JSON per the documented schema. Unlike the
     * rest of this class, this call is NOT live-verified: exercising it would have destroyed
     * the sandbox workspace, so it follows the spec exactly. If the API turns out to expect
     * `force` as a query parameter instead, this is the first place to look.
     *
     * @param bool $force cancel an active paid subscription instead of refusing to delete
     * @return array<string, mixed>
     */
    public function delete(bool $force = false): array
    {
        $this->logger->info('Deleting account', [
            'account_id' => $this->config->getAccountId(),
            'force' => $force,
        ]);

        $body = $force ? ['force' => true] : [];

        $response = $this->httpClient->delete($this->accountPath(), [], [], $body);

        return $response->getData() ?? [];
    }

    /**
     * Retrieve the account's branding theme.
     * `GET /accounts/{account_id}/theme`
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'account_name'    => 'Acme Inc.',
     *   'primary_color'   => '2072b9',   // hex, no leading '#'
     *   'secondary_color' => 'ffffff',
     *   'logo'            => null,       // URL once a logo is uploaded
     * ]
     * ```
     *
     * @return array<string, mixed>
     */
    public function theme(): array
    {
        $response = $this->httpClient->get($this->accountPath('theme'));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Download the account logo.
     * `GET /accounts/{account_id}/logo`
     *
     * Returns the raw binary image body — not JSON. Throws
     * {@see \Assinafy\SDK\Exceptions\ApiException} (404) when no logo has been uploaded.
     */
    public function downloadLogo(): string
    {
        $response = $this->httpClient->get($this->accountPath('logo'));

        return $response->getBody();
    }

    /**
     * Upload (or replace) the account logo.
     * `POST /accounts/{account_id}/logo`
     *
     * Sent as multipart/form-data under the field name `file`.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException when the file does not exist
     */
    public function uploadLogo(string $filePath): array
    {
        $this->logger->info('Uploading account logo', ['file' => $filePath]);

        $response = $this->httpClient->uploadFile($this->accountPath('logo'), $filePath);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Remove the account logo.
     * `DELETE /accounts/{account_id}/logo`
     *
     * @return array<string, mixed>
     */
    public function deleteLogo(): array
    {
        $response = $this->httpClient->delete($this->accountPath('logo'));

        return $response->getData() ?? [];
    }

    /**
     * @throws \Assinafy\SDK\Exceptions\ValidationException
     */
    private static function assertNotificationSenderType(string $type): void
    {
        if (!in_array($type, self::NOTIFICATION_SENDER_TYPES, true)) {
            throw new \Assinafy\SDK\Exceptions\ValidationException(sprintf(
                'Invalid notification sender type "%s". Expected one of: %s',
                $type,
                implode(', ', self::NOTIFICATION_SENDER_TYPES)
            ));
        }
    }
}
