<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Authenticated user profile and cross-account statistics.
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
     *       'id' => 'md3j6p9w8b7y6qvqaoy5er42', 'name' => 'Jane Doe',
     *       'email' => 'user@example.com', 'telephone' => null, 'government_id' => '',
     *       'is_email_verified' => true, 'has_accepted_terms' => true,
     *       'is_password_set' => true, 'created_at' => '2026-05-12T18:05:11Z',
     *       'to_be_deleted_at' => null,
     *     ],
     *     'accounts' => [
     *       ['id' => '64f000000000000000000001', 'name' => 'Acme Inc.',
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
     * Response (what this method returns):
     * ```
     * [
     *   'id'                 => 'md3j6p9w8b7y6qvqaoy5er42',
     *   'name'               => 'Jane Doe',
     *   'email'              => 'user@example.com',
     *   'telephone'          => null,
     *   'government_id'      => '',
     *   'is_email_verified'  => true,
     *   'has_accepted_terms' => true,
     *   'created_at'         => '2026-05-12T18:05:11Z',
     *   'to_be_deleted_at'   => null,
     * ]
     * ```
     *
     * @throws ValidationException when called on a public client without an access token
     *
     * @return array{id?: string, name?: string, email?: string, telephone?: string|null,
     *     government_id?: string|null, is_email_verified?: bool, has_accepted_terms?: bool,
     *     created_at?: string, to_be_deleted_at?: string|null}
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
     * Response (unwrapped `data` — one entry per period, zero-filled, no gaps):
     * ```
     * [
     *   [
     *     'period'                                   => '2026-08',  // 'YYYY-MM-DD' when daily
     *     'documents_uploaded'                       => 128,
     *     'documents_sent'                           => 119,
     *     'signature_requests'                       => 204,
     *     'signature_requests_notification_email'    => 190,
     *     'signature_requests_notification_whatsapp' => 14,
     *     'signature_requests_notification_bypass'   => 0,
     *     'signature_requests_verification_email'    => 186,
     *     'signature_requests_verification_whatsapp' => 14,
     *     'signature_requests_verification_bypass'   => 0,
     *     'signature_requests_verification_digital_certificate' => 4,
     *     'signature_requests_viewed'                => 181,
     *     'signature_requests_completed'             => 167,
     *     'documents_certified'                      => 167,
     *   ],
     * ]
     * ```
     *
     * Available on production. The sandbox does not route this endpoint and answers 404.
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
     * Response (unwrapped `data`):
     * ```
     * [
     *   'DocumentCompleted'        => true,
     *   'SignerDeclined'           => true,
     *   'DocumentCancelled'        => true,
     *   'DocumentAboutToExpire'    => true,
     *   'DocumentExpired'          => true,
     *   'DocumentExpirationReset'  => true,
     *   'DocumentProcessingFailed' => true,
     *   'TemplateProcessingFailed' => true,
     *   'SignerWhatsappFailed'     => true,
     * ]
     * ```
     *
     * Available on production. The sandbox does not route this endpoint and answers 404.
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
     * Response (unwrapped `data`) — the full nine-key map, not just what you changed:
     * ```
     * [
     *   'DocumentCompleted'        => true,
     *   'SignerDeclined'           => true,
     *   'DocumentCancelled'        => true,
     *   'DocumentAboutToExpire'    => false,
     *   'DocumentExpired'          => true,
     *   'DocumentExpirationReset'  => true,
     *   'DocumentProcessingFailed' => true,
     *   'TemplateProcessingFailed' => true,
     *   'SignerWhatsappFailed'     => false,
     * ]
     * ```
     *
     * Available on production. The sandbox does not route this endpoint and answers 404.
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
}
