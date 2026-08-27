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
    public const GRANULARITY_MONTHLY = 'monthly';
    public const GRANULARITY_DAILY = 'daily';

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
     * This is the documented way to discover account IDs, so it is deliberately not
     * account-scoped. It accepts the configured API key/global Bearer credential, or an
     * OAuth access token passed explicitly.
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
     *       'id'                => '64f000000000000000000001',
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
    public function list(#[\SensitiveParameter] ?string $accessToken = null): array
    {
        return $this->withPagination($this->httpClient->get(
            'accounts',
            [],
            $this->bearerHeaders($accessToken)
        ));
    }

    /**
     * Retrieve the account this client is configured against.
     * `GET /accounts/{account_id}`
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'id'              => '64f000000000000000000001',
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
     * [
     *   'name'                     => 'Acme Inc.',  // required
     *   'notification_sender_type' => 'User',       // optional: 'User' | 'Account'
     * ]
     * ```
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'id'              => '64f000000000000000000001',
     *   'name'            => 'Acme Inc.',
     *   'primary_color'   => null,
     *   'secondary_color' => null,
     *   'created_at'      => '2026-05-12T18:05:11Z',
     * ]
     * ```
     *
     * @param string|null $notificationSenderType one of the `NOTIFICATION_SENDER_*` constants
     * @return array<string, mixed> the created account
     * @throws \Assinafy\SDK\Exceptions\ValidationException on an empty name or unknown sender type
     */
    public function create(
        string $name,
        ?string $notificationSenderType = null,
        #[\SensitiveParameter] ?string $accessToken = null
    ): array {
        if (trim($name) === '') {
            throw new \Assinafy\SDK\Exceptions\ValidationException('Account name cannot be empty');
        }

        $payload = ['name' => $name];

        if ($notificationSenderType !== null) {
            self::assertNotificationSenderType($notificationSenderType);
            $payload['notification_sender_type'] = $notificationSenderType;
        }

        $response = $this->httpClient->post(
            'accounts',
            $payload,
            $this->bearerHeaders($accessToken)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Update the configured account.
     * `PUT /accounts/{account_id}`
     *
     * Both fields are optional; send only what you want to change. Omitted keys are left
     * untouched, so this is a partial update despite the `PUT` verb.
     *
     * Request body (at least one key required):
     * ```
     * ['name' => 'Acme Holdings', 'notification_sender_type' => 'Account']
     * ```
     *
     * Response (unwrapped `data`) — the account after the change:
     * ```
     * [
     *   'id'              => '64f000000000000000000001',
     *   'name'            => 'Acme Holdings',
     *   'primary_color'   => null,
     *   'secondary_color' => null,
     *   'created_at'      => '2026-05-12T18:05:11Z',
     * ]
     * ```
     *
     * @param string|null $notificationSenderType one of the `NOTIFICATION_SENDER_*` constants
     * @return array<string, mixed> the updated account
     * @throws \Assinafy\SDK\Exceptions\ValidationException when nothing was supplied to update
     */
    public function update(?string $name = null, ?string $notificationSenderType = null): array
    {
        $payload = [];

        if ($name !== null) {
            if (trim($name) === '') {
                throw new \Assinafy\SDK\Exceptions\ValidationException(
                    'Account name cannot be empty'
                );
            }
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
     * Request body: `['force' => true]` when `$force` is set, otherwise no body. The
     * lifecycle is live-tested only against a disposable sandbox workspace; the configured
     * workspace is never used as the deletion target.
     *
     * Response (full envelope — `data` is an empty array, there is nothing left to return):
     * ```
     * ['status' => 200, 'message' => '', 'data' => []]
     * ```
     *
     * On refusal the API answers `400` and this method raises an
     * {@see \Assinafy\SDK\Exceptions\ApiException} whose `getResponseData()` carries the
     * blocking `restrictions`:
     * ```
     * [
     *   'status'       => 400,
     *   'message'      => 'Cannot delete while restrictions are active.',
     *   'restrictions' => [['code' => 'ActivePaidSubscription'], ['code' => 'PendingDocuments']],
     * ]
     * ```
     *
     * @param bool $force cancel an active paid subscription instead of refusing to delete
     * @return array<string, mixed> the raw envelope
     */
    public function delete(bool $force = false): array
    {
        $this->logger->info('Deleting account', [
            'account_id' => $this->requireAccountId(),
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
     *   'logo'            => 'https://cdn.example.test/logo.png',
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
     * Return the configured account's document-funnel KPI series.
     * `GET /accounts/{account_id}/stats`
     *
     * Monthly mode returns the latest 12 months. Daily mode requires `$month`
     * in `YYYY-MM` form and returns every day in that month. Both series are
     * zero-filled by the API, so there are no gaps to interpolate.
     *
     * Request (query string): `granularity=monthly|daily`, plus `month=YYYY-MM` which is
     * required for `daily` and optional for `monthly`.
     *
     * Signature requests carry two independent breakdowns of the same total: the
     * `*_notification_*` counters split them by the channel the signer was notified
     * through, the `*_verification_*` counters by how the signer proved identity.
     *
     * Response (unwrapped `data` — one entry per period):
     * ```
     * [
     *   [
     *     'period'                                   => '2026-08',  // 'YYYY-MM-DD' when daily
     *     'documents_uploaded'                       => 42,
     *     'documents_sent'                           => 37,
     *     'signature_requests'                       => 51,
     *     'signature_requests_notification_email'    => 48,
     *     'signature_requests_notification_whatsapp' => 3,
     *     'signature_requests_notification_bypass'   => 0,
     *     'signature_requests_verification_email'    => 45,
     *     'signature_requests_verification_whatsapp' => 3,
     *     'signature_requests_verification_bypass'   => 0,
     *     'signature_requests_verification_digital_certificate' => 3,
     *     'signature_requests_viewed'                => 44,
     *     'signature_requests_completed'             => 39,
     *     'documents_certified'                      => 39,
     *   ],
     * ]
     * ```
     *
     * Available on production. The sandbox does not route this endpoint and answers 404.
     *
     * @throws \Assinafy\SDK\Exceptions\ValidationException on an unknown granularity, or on
     *     `daily` without a `YYYY-MM` month
     * @return array<int, array{period: string, documents_uploaded: int, documents_sent: int,
     *     signature_requests: int, signature_requests_notification_email: int,
     *     signature_requests_notification_whatsapp: int,
     *     signature_requests_notification_bypass: int,
     *     signature_requests_verification_email: int,
     *     signature_requests_verification_whatsapp: int,
     *     signature_requests_verification_bypass: int,
     *     signature_requests_verification_digital_certificate: int,
     *     signature_requests_viewed: int, signature_requests_completed: int,
     *     documents_certified: int}>
     */
    public function stats(
        string $granularity = self::GRANULARITY_MONTHLY,
        ?string $month = null
    ): array {
        $response = $this->httpClient->get(
            $this->accountPath('stats'),
            $this->statsQuery($granularity, $month)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Download the account logo.
     * `GET /accounts/{account_id}/logo`
     *
     * Request: no parameters.
     *
     * Response: the raw image bytes — **not** JSON, and not the envelope. The
     * `Content-Type` header carries the format the workspace uploaded (`image/png`,
     * `image/jpeg`). Write the return value straight to disk:
     * ```php
     * file_put_contents('logo.png', $client->accounts()->downloadLogo());
     * ```
     *
     * @return string raw image bytes
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when no logo has been uploaded
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
     * Request: `multipart/form-data` with the image under the field name `file`.
     * Replaces any logo already stored — there is no separate update route.
     *
     * Response: the envelope carries no `data` for this operation, so the unwrapped
     * result is an empty array. Call {@see self::theme()} afterwards to read back the
     * public `logo` URL:
     * ```
     * ['status' => 200, 'message' => '']
     * ```
     *
     * @return array<string, mixed> empty on success
     * @throws \InvalidArgumentException when the file does not exist or is unreadable
     */
    public function uploadLogo(#[\SensitiveParameter] string $filePath): array
    {
        $this->logger->info('Uploading account logo');

        $response = $this->httpClient->uploadFile($this->accountPath('logo'), $filePath);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Remove the account logo.
     * `DELETE /accounts/{account_id}/logo`
     *
     * Request: no body. Succeeds even when no logo is currently set.
     *
     * Response (full envelope, no `data` key for this operation):
     * ```
     * ['status' => 200, 'message' => '']
     * ```
     *
     * @return array<string, mixed> the raw envelope
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
