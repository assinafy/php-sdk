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
     * Returns `{ access_token, user, accounts }`.
     *
     * @return array<string, mixed>
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
     * Sign in with a social provider (Google, etc.).
     * `POST /authentication/social-login`
     *
     * @return array<string, mixed>
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
     * Uses the configured API key by default. Pass `$accessToken` when using a
     * public/bootstrap client.
     *
     * @return array<string, mixed>
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
     * This runtime route was removed from the current OpenAPI contract and its
     * production/sandbox redirects were misconfigured when checked on 2026-08-19.
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
     * `/login-callback` was removed from the current OpenAPI contract and is not
     * an operational OAuth integration until Assinafy fixes the upstream routes.
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
     * @return array<string, mixed>
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
     * @return array<string, mixed>
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
     * @return array<array-key, mixed>
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
     * @return array<string, mixed>
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
     * @return array<string, mixed>
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
     * @return array<string, mixed>
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
