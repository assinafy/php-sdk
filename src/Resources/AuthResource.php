<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Authentication resource — every endpoint under `/login`, `/authentication/*`
 * and `/users/api-keys`.
 *
 * These endpoints are mostly used to bootstrap a user session. The Assinafy
 * API supports three concurrent auth schemes:
 *   1. `X-Api-Key` header (the SDK's default — see {@see Configuration})
 *   2. `Authorization: Bearer <token>` header (configured globally or per call here)
 *   3. `?access-token=<token>` query parameter
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class AuthResource extends AbstractResource
{
    public const PROVIDER_GOOGLE = 'google';

    /**
     * Sign in with email + password.
     * `POST /login`
     *
     * The bootstrap call: exchange credentials for a Bearer token when you don't yet hold an
     * API key. Callable on a {@see \Assinafy\SDK\AssinafyClient::forAuth()} client, which
     * carries no credentials of its own.
     *
     * Request body:
     * ```
     * ['email' => 'user@example.com', 'password' => 's3cret']
     * ```
     *
     * Response (unwrapped `data`):
     * ```
     * [
     *   'access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9…',
     *   'user'     => [
     *     'id' => 'bgjazeo5r9v2lq7l36dx48np', 'name' => 'Jane Doe',
     *     'email' => 'user@example.com', 'telephone' => null, 'government_id' => '',
     *     'is_email_verified' => true, 'has_accepted_terms' => true,
     *     'created_at' => '2026-05-12T18:05:11Z', 'to_be_deleted_at' => null,
     *   ],
     *   'accounts' => [
     *     ['id' => '64f000000000000000000001', 'name' => 'Acme Inc.', 'roles' => ['owner'],
     *      'is_delete_allowed' => true, 'created_at' => '2026-05-12T18:05:11Z'],
     *   ],
     * ]
     * ```
     *
     * Feed `access_token` and one of the `accounts[].id` values into
     * {@see \Assinafy\SDK\AssinafyClient::forBearer()} to get a workspace-scoped client.
     *
     * @return array<string, mixed> `{ access_token, user, accounts }`
     * @throws ValidationException on a malformed email or empty password
     * @throws \Assinafy\SDK\Exceptions\ApiException 401 on wrong credentials
     */
    public function login(
        #[\SensitiveParameter] string $email,
        #[\SensitiveParameter] string $password
    ): array {
        $this->assertEmail($email);
        $this->assertNotEmpty('Password', $password);
        $response = $this->httpClient->post('login', [
            'email' => $email,
            'password' => $password,
        ]);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Sign in with a social provider.
     * `POST /authentication/social-login`
     *
     * `$token` is the provider's own ID token, obtained in the browser — the SDK does not
     * run the OAuth dance. Only `google` is accepted today ({@see self::PROVIDER_GOOGLE}).
     * Set `$hasAcceptedTerms` on the first sign-in of a brand-new user.
     *
     * Request body:
     * ```
     * ['provider' => 'google', 'token' => '<google id token>', 'has_accepted_terms' => true]
     * ```
     *
     * Response (unwrapped `data`) — identical in shape to {@see self::login()}:
     * ```
     * [
     *   'access_token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9…',
     *   'user'         => ['id' => 'bgjaz…', 'name' => 'Jane Doe', 'email' => 'user@example.com', …],
     *   'accounts'     => [['id' => '64f0…', 'name' => 'Acme Inc.', 'roles' => ['owner'], …]],
     * ]
     * ```
     *
     * @return array<string, mixed> `{ access_token, user, accounts }`
     * @throws ValidationException on an unsupported provider or empty token
     */
    public function socialLogin(
        string $provider,
        #[\SensitiveParameter] string $token,
        bool $hasAcceptedTerms = false
    ): array {
        $this->assertProvider($provider);
        $this->assertNotEmpty('Social provider token', $token);
        $response = $this->httpClient->post('authentication/social-login', [
            'provider' => $provider,
            'token' => $token,
            'has_accepted_terms' => $hasAcceptedTerms,
        ]);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Link a social provider account to the authenticated Assinafy user.
     * `POST /auth/link-social-login`
     *
     * Attaches a social identity to an account that already exists, so the user can sign in
     * either way afterwards. Distinct from {@see self::socialLogin()}, which authenticates.
     *
     * Uses the configured API key by default. Pass `$accessToken` when using a
     * public/bootstrap client.
     *
     * Request body:
     * ```
     * ['provider' => 'google', 'token' => '<google id token>']
     * ```
     *
     * Response (unwrapped `data`; empty on success):
     * ```
     * []
     * ```
     *
     * @return array<string, mixed>
     * @throws ValidationException on an unsupported provider, an empty token, or an empty
     *     access token on a public client
     */
    public function linkSocialLogin(
        string $provider,
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] ?string $accessToken = null
    ): array {
        $this->assertProvider($provider);
        $this->assertNotEmpty('Social provider token', $token);
        $response = $this->httpClient->post(
            'auth/link-social-login',
            ['provider' => $provider, 'token' => $token],
            $this->bearerHeaders($accessToken)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Build the legacy browser OAuth start URL without requesting it.
     *
     * Pure string construction — makes **no** HTTP request. Redirect a browser here to
     * begin the provider handshake; the result comes back to
     * {@see self::socialLoginCallbackUrl()}.
     *
     * Request/Response: none.
     *
     * Returns, for the default base URL:
     * ```
     * https://api.assinafy.com.br/v1/auth/authenticate?authclient=google
     * ```
     *
     * This legacy runtime route is not part of the current OpenAPI contract, and
     * `GET /auth/authenticate` answers 404 when called directly as an API endpoint — it is
     * meaningful only as a browser navigation target.
     *
     * @return string absolute URL
     * @throws ValidationException on an unsupported provider
     */
    public function socialLoginUrl(string $provider = self::PROVIDER_GOOGLE): string
    {
        $this->assertProvider($provider);

        return $this->config->getBaseUrl()
            . '/auth/authenticate?authclient=' . rawurlencode($provider);
    }

    /**
     * Return the legacy browser callback URL without requesting it.
     *
     * Pure string construction — makes **no** HTTP request. This is where the provider
     * returns the browser after {@see self::socialLoginUrl()}.
     *
     * Request/Response: none.
     *
     * Returns, for the default base URL:
     * ```
     * https://api.assinafy.com.br/v1/login-callback
     * ```
     *
     * `/login-callback` is not part of the current OpenAPI contract and serves HTML rather
     * than JSON, so it is a redirect target rather than an endpoint to call.
     *
     * @return string absolute URL
     */
    public function socialLoginCallbackUrl(): string
    {
        return $this->config->getBaseUrl() . '/login-callback';
    }

    /**
     * Generate (or regenerate) the API key for the authenticated user.
     * `POST /users/api-keys` — uses the configured API key/Bearer credential or an
     * explicitly supplied Bearer access token.
     *
     * **This is the only call that ever returns the key in full**; {@see self::getApiKey()}
     * returns it masked from then on. Store the value now or you will have to regenerate.
     * Regenerating invalidates the previous key immediately.
     *
     * The password is re-checked here even though the request is already authenticated.
     *
     * Request body:
     * ```
     * ['password' => 's3cret']
     * ```
     *
     * Response (unwrapped `data`):
     * ```
     * ['api_key' => 'mIpe_zdJfKUpMK9Va3XuYgzPXMxz49fIaRCWXseVkpVAX608A9j3i_D67qU5qW3M']
     * ```
     *
     * @param string|null $accessToken Bearer token, or null to use the configured credential
     * @return array<string, mixed> `{ api_key }` — the full, unmasked key
     * @throws ValidationException on an empty password or empty access token
     */
    public function generateApiKey(
        #[\SensitiveParameter] ?string $accessToken,
        #[\SensitiveParameter] string $password
    ): array {
        $this->assertNotEmpty('Password', $password);
        $response = $this->httpClient->post(
            'users/api-keys',
            ['password' => $password],
            $this->bearerHeaders($accessToken)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Retrieve the masked API key for the authenticated user.
     * `GET /users/api-keys` — uses the configured API key/Bearer credential or an
     * explicitly supplied Bearer access token.
     *
     * Confirms a key exists and shows its last four characters; it is **not** usable for
     * authentication. Only {@see self::generateApiKey()} ever returns the full value.
     *
     * Request: no parameters.
     *
     * Response (unwrapped `data`) — all but the final four characters replaced by `*`:
     * ```
     * ['api_key' => '************************************************************qW3M']
     * ```
     *
     * @return array<string, mixed> `{ api_key }` — masked
     * @throws ValidationException when called on a public client without an access token
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when no key has been generated
     */
    public function getApiKey(#[\SensitiveParameter] ?string $accessToken = null): array
    {
        $response = $this->httpClient->get('users/api-keys', [], $this->bearerHeaders($accessToken));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete the API key for the authenticated user.
     * `DELETE /users/api-keys` — uses the configured API key/Bearer credential or an
     * explicitly supplied Bearer access token.
     *
     * Revokes the key immediately. If the client was authenticating **with** that key, every
     * later call on it fails with 401 — pass a Bearer `$accessToken` when you need the
     * session to survive the revocation.
     *
     * Request: no body.
     *
     * Response (full envelope; no `data` payload):
     * ```
     * ['status' => 200, 'message' => '']
     * ```
     *
     * @return array<array-key, mixed> the raw envelope
     * @throws ValidationException when called on a public client without an access token
     */
    public function deleteApiKey(#[\SensitiveParameter] ?string $accessToken = null): array
    {
        $response = $this->httpClient->delete('users/api-keys', $this->bearerHeaders($accessToken));

        return $response->getData() ?? [];
    }

    /**
     * Change the password of the authenticated user.
     * `PUT /authentication/change-password` — uses the configured API key/Bearer
     * credential or an explicitly supplied Bearer access token.
     *
     * For a user who knows their current password. When they don't, use
     * {@see self::requestPasswordReset()} followed by {@see self::resetPassword()}.
     *
     * Request body:
     * ```
     * [
     *   'email'        => 'user@example.com',
     *   'password'     => 's3cret',      // current
     *   'new_password' => 'n3wS3cret',
     * ]
     * ```
     *
     * Response (unwrapped `data`):
     * ```
     * ['email' => 'user@example.com']
     * ```
     *
     * @return array<string, mixed> `{ email }`
     * @throws ValidationException on a malformed email or an empty password
     * @throws \Assinafy\SDK\Exceptions\ApiException 401 when the current password is wrong
     */
    public function changePassword(
        #[\SensitiveParameter] ?string $accessToken,
        #[\SensitiveParameter] string $email,
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $newPassword
    ): array {
        $this->assertEmail($email);
        $this->assertNotEmpty('Current password', $password);
        $this->assertNotEmpty('New password', $newPassword);
        $response = $this->httpClient->put(
            'authentication/change-password',
            ['email' => $email, 'password' => $password, 'new_password' => $newPassword],
            $this->bearerHeaders($accessToken)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Trigger a password-reset email.
     * `PUT /authentication/request-password-reset`
     *
     * Unauthenticated — callable on a {@see \Assinafy\SDK\AssinafyClient::forAuth()} client.
     * The SDK strips workspace credentials from this request even on an authenticated one.
     * Step one of two; the emailed token is then spent by {@see self::resetPassword()}.
     *
     * Request body:
     * ```
     * ['email' => 'user@example.com']
     * ```
     *
     * Response (unwrapped `data`):
     * ```
     * ['email' => 'user@example.com']
     * ```
     *
     * Answers 200 whether or not the address belongs to a user, so it cannot be used to
     * enumerate accounts — a 200 is not proof the mail was sent.
     *
     * @return array<string, mixed> `{ email }`
     * @throws ValidationException on a malformed email
     */
    public function requestPasswordReset(#[\SensitiveParameter] string $email): array
    {
        $this->assertEmail($email);
        $response = $this->httpClient->put('authentication/request-password-reset', [
            'email' => $email,
        ]);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Complete a password reset using the token emailed to the user.
     * `PUT /authentication/reset-password`
     *
     * Step two of the flow started by {@see self::requestPasswordReset()}. Unauthenticated —
     * callable on a {@see \Assinafy\SDK\AssinafyClient::forAuth()} client, and the SDK strips
     * workspace credentials from this request even on an authenticated one.
     *
     * Request body:
     * ```
     * [
     *   'email'        => 'user@example.com',
     *   'token'        => '<token from the reset email>',
     *   'new_password' => 'n3wS3cret',
     * ]
     * ```
     *
     * Response (unwrapped `data`):
     * ```
     * ['email' => 'user@example.com']
     * ```
     *
     * @return array<string, mixed> `{ email }`
     * @throws ValidationException on a malformed email, or an empty token or password
     * @throws \Assinafy\SDK\Exceptions\ApiException 400 when the token is expired or already used
     */
    public function resetPassword(
        #[\SensitiveParameter] string $email,
        #[\SensitiveParameter] string $token,
        #[\SensitiveParameter] string $newPassword
    ): array {
        $this->assertEmail($email);
        $this->assertNotEmpty('Password-reset token', $token);
        $this->assertNotEmpty('New password', $newPassword);
        $response = $this->httpClient->put('authentication/reset-password', [
            'email' => $email,
            'token' => $token,
            'new_password' => $newPassword,
        ]);

        return $this->extractData($response->getData() ?? []);
    }

    private function assertProvider(string $provider): void
    {
        if ($provider !== self::PROVIDER_GOOGLE) {
            throw new ValidationException('Social provider must be google', ['provider' => $provider]);
        }
    }

    private function assertEmail(#[\SensitiveParameter] string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Email address is invalid', ['email' => $email]);
        }
    }

    private function assertNotEmpty(string $name, #[\SensitiveParameter] string $value): void
    {
        if (trim($value) === '') {
            throw new ValidationException("{$name} cannot be empty");
        }
    }
}
