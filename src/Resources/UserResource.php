<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Authenticated user profile, cross-account statistics, notification preferences and
 * two-factor authentication (TOTP authenticator plus recovery codes).
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class UserResource extends AbstractResource
{
    public const GRANULARITY_MONTHLY = AccountResource::GRANULARITY_MONTHLY;
    public const GRANULARITY_DAILY = AccountResource::GRANULARITY_DAILY;

    /** @var list<string> */
    public const NOTIFICATION_PREFERENCE_CODES = [
        'DocumentCompleted',
        'SignerDeclined',
        'DocumentCancelled',
        'DocumentAboutToExpire',
        'DocumentExpired',
        'DocumentExpirationReset',
        'DocumentProcessingFailed',
        'TemplateProcessingFailed',
        'SignerWhatsappFailed',
    ];

    /**
     * Get the user represented by the configured API key or a Bearer token.
     * `GET /users/self`
     *
     * The "who am I" call — use it to confirm a credential works and to discover which
     * accounts it can reach.
     *
     * Request: no parameters.
     *
     * The live API answers with `data: { user, accounts }`:
     * ```
     * [
     *   'status'  => 200,
     *   'message' => '',
     *   'data'    => [
     *     'user' => [
     *       'id' => 'resource-id', 'name' => 'Jane Doe',
     *       'email' => 'user@example.com', 'telephone' => null, 'government_id' => '',
     *       'is_email_verified' => true, 'has_accepted_terms' => true,
     *       'is_password_set' => true, 'created_at' => '2026-05-12T18:05:11Z',
     *       'to_be_deleted_at' => null,
     *     ],
     *     'accounts' => [
     *       ['id' => 'resource-id', 'name' => 'Acme Inc.',
     *        'roles' => ['owner'], 'is_delete_allowed' => true,
     *        'created_at' => '2026-05-12T18:05:11Z'],
     *     ],
     *   ],
     * ]
     * ```
     *
     * The published contract instead declares `data` to be the user object directly. This
     * method returns the **user** either way — it unwraps the nested `user` key when present.
     * Use {@see AccountResource::list()} for the workspace list rather than relying on the
     * `accounts` key, which the documented shape does not carry.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'id' => 'auth-user-id',
     *     'name' => 'John Smith',
     *     'email' => 'person@example.com',
     *     'telephone' => null,
     *     'government_id' => null,
     *     'is_email_verified' => false,
     *     'has_accepted_terms' => true,
     *     'is_password_set' => true,
     *     'created_at' => '2023-03-03T11:51:34Z',
     *     'to_be_deleted_at' => null,
     * ]
     * ```
     *
     * @throws ValidationException when called on a public client without an access token
     *
     * @return array{id?: string, name?: string, email?: string, telephone?: string|null,
     *     government_id?: string|null, is_email_verified?: bool, has_accepted_terms?: bool,
     *     is_password_set?: bool, created_at?: string, to_be_deleted_at?: string|null}
     */
    public function get(#[\SensitiveParameter] ?string $accessToken = null): array
    {
        $response = $this->httpClient->get(
            'users/self',
            [],
            $this->bearerHeaders($accessToken)
        );

        $data = $this->extractData($response->getData() ?? []);

        // OpenAPI declares data: AuthUser. The current sandbox instead returns
        // data: {user: AuthUser, accounts: AuthAccount[]}; normalize both shapes
        // to this method's documented AuthUser return contract.
        if (isset($data['user']) && is_array($data['user'])) {
            return $data['user'];
        }

        return $data;
    }

    /**
     * Get document-funnel KPIs summed over every account the user belongs to.
     * `GET /users/self/stats`
     *
     * The cross-account counterpart to {@see AccountResource::stats()}: identical series
     * shape, but totalled over every workspace rather than scoped to one.
     *
     * Request (query string): `granularity=monthly|daily`, plus `month=YYYY-MM` which is
     * required for `daily` and optional for `monthly`.
     *
     * Example query (no request body):
     * ```php
     * ['granularity' => 'monthly', 'month' => '2026-09']
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     [
     *         'period' => '2026-09',
     *         'documents_uploaded' => 0,
     *         'documents_sent' => 0,
     *         'signature_requests' => 0,
     *         'signature_requests_notification_email' => 0,
     *         'signature_requests_notification_whatsapp' => 0,
     *         'signature_requests_notification_bypass' => 0,
     *         'signature_requests_verification_email' => 0,
     *         'signature_requests_verification_whatsapp' => 0,
     *         'signature_requests_verification_bypass' => 0,
     *         'signature_requests_verification_digital_certificate' => 0,
     *         'signature_requests_viewed' => 0,
     *         'signature_requests_completed' => 0,
     *         'documents_certified' => 0,
     *     ],
     * ]
     * ```
     *
     * Available in production and sandbox; access depends on the authenticated account.
     *
     * @throws ValidationException on an unknown granularity, or on `daily` without a
     *     `YYYY-MM` month
     *
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
        ?string $month = null,
        #[\SensitiveParameter] ?string $accessToken = null
    ): array {
        $response = $this->httpClient->get(
            'users/self/stats',
            $this->statsQuery($granularity, $month),
            $this->bearerHeaders($accessToken)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Get all owner-facing document email preferences.
     * `GET /users/self/notification-preferences`
     *
     * These are the emails the document **owner** receives about their own documents — not
     * the signature invitations sent to signers. Account and security email (welcome,
     * password reset, invitations, account deletion) is not configurable and never appears
     * here.
     *
     * All nine keys are always returned; everything defaults to `true`. The codes are
     * available as {@see self::NOTIFICATION_PREFERENCE_CODES}.
     *
     * Request: no parameters.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'DocumentCompleted' => true,
     *     'SignerDeclined' => true,
     *     'DocumentCancelled' => true,
     *     'DocumentAboutToExpire' => true,
     *     'DocumentExpired' => true,
     *     'DocumentExpirationReset' => true,
     *     'DocumentProcessingFailed' => true,
     *     'TemplateProcessingFailed' => true,
     *     'SignerWhatsappFailed' => true,
     * ]
     * ```
     *
     * Available in production and sandbox; access depends on the authenticated account.
     *
     * @throws ValidationException when called on a public client without an access token
     *
     * @return array{DocumentCompleted?: bool, SignerDeclined?: bool,
     *     DocumentCancelled?: bool, DocumentAboutToExpire?: bool, DocumentExpired?: bool,
     *     DocumentExpirationReset?: bool, DocumentProcessingFailed?: bool,
     *     TemplateProcessingFailed?: bool, SignerWhatsappFailed?: bool}
     */
    public function notificationPreferences(#[\SensitiveParameter] ?string $accessToken = null): array
    {
        $response = $this->httpClient->get(
            'users/self/notification-preferences',
            [],
            $this->bearerHeaders($accessToken)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Merge selected owner-facing document email preferences.
     * `PUT /users/self/notification-preferences`
     *
     * A merge, not a replace: omitted keys keep their current values, so you never have to
     * read-modify-write the whole map. Setting a key to `false` stops that email for this
     * user in **every** account they belong to — the setting is per-user, not per-workspace.
     *
     * Keys and values are validated locally against
     * {@see self::NOTIFICATION_PREFERENCE_CODES} before the request is sent; the API
     * likewise rejects an unknown code, a non-boolean value, or an empty body with 400 and
     * writes nothing.
     *
     * Request body (at least one key required):
     * ```
     * ['DocumentAboutToExpire' => false, 'SignerWhatsappFailed' => false]
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'DocumentCompleted' => true,
     *     'SignerDeclined' => true,
     *     'DocumentCancelled' => true,
     *     'DocumentAboutToExpire' => false,
     *     'DocumentExpired' => true,
     *     'DocumentExpirationReset' => true,
     *     'DocumentProcessingFailed' => true,
     *     'TemplateProcessingFailed' => true,
     *     'SignerWhatsappFailed' => false,
     * ]
     * ```
     *
     * Available in production and sandbox; access depends on the authenticated account.
     *
     * @throws ValidationException when `$preferences` is empty, a code is unknown, or a
     *     value is not a boolean
     * @param array<array-key, mixed> $preferences
     * @return array{DocumentCompleted?: bool, SignerDeclined?: bool,
     *     DocumentCancelled?: bool, DocumentAboutToExpire?: bool, DocumentExpired?: bool,
     *     DocumentExpirationReset?: bool, DocumentProcessingFailed?: bool,
     *     TemplateProcessingFailed?: bool, SignerWhatsappFailed?: bool}
     */
    public function updateNotificationPreferences(
        array $preferences,
        #[\SensitiveParameter] ?string $accessToken = null
    ): array {
        if ($preferences === []) {
            throw new ValidationException('At least one notification preference is required');
        }
        foreach ($preferences as $code => $enabled) {
            if (!is_string($code) || !in_array($code, self::NOTIFICATION_PREFERENCE_CODES, true)) {
                throw new ValidationException('Unknown notification preference: ' . (string) $code);
            }
            if (!is_bool($enabled)) {
                throw new ValidationException("Notification preference {$code} must be boolean");
            }
        }

        $response = $this->httpClient->put(
            'users/self/notification-preferences',
            $preferences,
            $this->bearerHeaders($accessToken)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * List the user's enrolled two-factor methods and remaining recovery codes.
     * `GET /users/self/mfa`
     *
     * Request: no parameters.
     *
     * Example response (SDK return):
     * ```php
     * [
     *     'methods' => [
     *         [
     *             'id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
     *             'type' => 'Totp',
     *             'label' => 'My phone',
     *             'confirmed_at' => '2026-09-09T14:21:03Z',
     *             'last_used_at' => '2026-09-09T18:02:44Z',
     *         ],
     *     ],
     *     'recovery_codes_remaining' => 8,
     * ]
     * ```
     *
     * @return array{methods?: array<int, array<string, mixed>>, recovery_codes_remaining?: int}
     * @throws ValidationException when called on a public client without an access token
     */
    public function mfaMethods(#[\SensitiveParameter] ?string $accessToken = null): array
    {
        $response = $this->httpClient->get('users/self/mfa', [], $this->bearerHeaders($accessToken));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Start enrolling an authenticator app.
     * `POST /users/self/mfa/totp`
     *
     * Creates an unconfirmed method and returns its shared secret — returned only by this call.
     * Render `provisioning_uri` as a QR code, then prove one code with
     * {@see self::confirmTotpEnrollment()}; two-factor login is not active until then.
     *
     * Request body:
     * ```php
     * ['label' => 'My phone']   // omitted when $label is null
     * ```
     *
     * Example response (SDK return):
     * ```php
     * [
     *     'id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
     *     'secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
     *     'provisioning_uri' => 'otpauth://totp/user%40example.com?issuer=Assinafy&secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
     * ]
     * ```
     *
     * @return array{id?: string, secret?: string, provisioning_uri?: string}
     * @throws ValidationException when called on a public client without an access token
     */
    public function startTotpEnrollment(
        ?string $label = null,
        #[\SensitiveParameter] ?string $accessToken = null
    ): array {
        $response = $this->httpClient->post(
            'users/self/mfa/totp',
            $label === null ? [] : ['label' => $label],
            $this->bearerHeaders($accessToken)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Confirm an authenticator enrollment and receive the recovery codes.
     * `PUT /users/self/mfa/totp/confirm`
     *
     * `$code` is a live code from the device being enrolled. From now on every login needs a
     * second factor ({@see AuthResource::verifyMfa()}). The recovery codes are shown only
     * once. Replacing an already confirmed authenticator also needs re-authentication:
     * `$password`, or `$reauthCode` (a code from the current device or a recovery code). A
     * first enrollment needs neither.
     *
     * Request body:
     * ```php
     * [
     *     'id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
     *     'code' => '123456',
     *     'password' => 'current-password',   // only when replacing; or 'reauth_code'
     * ]
     * ```
     *
     * Example response (SDK return):
     * ```php
     * ['recovery_codes' => ['ABCD-EFGH-JKMN', 'PQRS-TUVW-XYZ2']]
     * ```
     *
     * @return array{recovery_codes?: list<string>}
     * @throws ValidationException on an empty method ID or code
     */
    public function confirmTotpEnrollment(
        string $methodId,
        #[\SensitiveParameter] string $code,
        #[\SensitiveParameter] ?string $password = null,
        #[\SensitiveParameter] ?string $reauthCode = null,
        #[\SensitiveParameter] ?string $accessToken = null
    ): array {
        if (trim($methodId) === '' || trim($code) === '') {
            throw new ValidationException('MFA method ID and code cannot be empty');
        }

        $response = $this->httpClient->put(
            'users/self/mfa/totp/confirm',
            ['id' => $methodId, 'code' => $code] + $this->reauthentication($password, $reauthCode, 'reauth_code'),
            $this->bearerHeaders($accessToken)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Issue ten new recovery codes, invalidating the previous set.
     * `POST /users/self/mfa/recovery-codes`
     *
     * Requires `$password` or `$code` — a live authenticator code or an existing recovery code,
     * which is then consumed.
     *
     * Request body:
     * ```php
     * ['password' => 'current-password']   // or ['code' => '123456']
     * ```
     *
     * Example response (SDK return):
     * ```php
     * ['recovery_codes' => ['ABCD-EFGH-JKMN', 'PQRS-TUVW-XYZ2']]
     * ```
     *
     * @return array{recovery_codes?: list<string>}
     * @throws ValidationException when neither `$password` nor `$code` is given
     */
    public function regenerateRecoveryCodes(
        #[\SensitiveParameter] ?string $password = null,
        #[\SensitiveParameter] ?string $code = null,
        #[\SensitiveParameter] ?string $accessToken = null
    ): array {
        $response = $this->httpClient->post(
            'users/self/mfa/recovery-codes',
            $this->requiredReauthentication($password, $code),
            $this->bearerHeaders($accessToken)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Remove an enrolled two-factor method.
     * `DELETE /users/self/mfa/{method_id}`
     *
     * Requires `$password` or `$code` (a live authenticator code or a recovery code, then
     * consumed), so a stolen session cannot silently disable two-factor login. Removing the
     * last method also discards the recovery codes.
     *
     * Request body:
     * ```php
     * ['password' => 'current-password']   // or ['code' => '123456']
     * ```
     *
     * Example response (SDK return):
     * ```php
     * ['is_mfa_enabled' => false]
     * ```
     *
     * @return array{is_mfa_enabled?: bool}
     * @throws ValidationException on an empty method ID, or when neither `$password` nor
     *     `$code` is given
     */
    public function removeMfaMethod(
        string $methodId,
        #[\SensitiveParameter] ?string $password = null,
        #[\SensitiveParameter] ?string $code = null,
        #[\SensitiveParameter] ?string $accessToken = null
    ): array {
        $response = $this->httpClient->delete(
            'users/self/mfa/' . $this->pathSegment($methodId, 'MFA method ID'),
            $this->bearerHeaders($accessToken),
            [],
            $this->requiredReauthentication($password, $code)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * @return array<string, string> the non-empty proofs, keyed by their wire names
     */
    private function reauthentication(
        #[\SensitiveParameter] ?string $password,
        #[\SensitiveParameter] ?string $code,
        string $codeField
    ): array {
        return array_filter(
            ['password' => $password, $codeField => $code],
            static fn (?string $value): bool => $value !== null && trim($value) !== ''
        );
    }

    /**
     * @return array<string, string>
     */
    private function requiredReauthentication(
        #[\SensitiveParameter] ?string $password,
        #[\SensitiveParameter] ?string $code
    ): array {
        $proof = $this->reauthentication($password, $code, 'code');
        if ($proof === []) {
            throw new ValidationException('The current password or a two-factor code is required');
        }

        return $proof;
    }
}
