<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

/**
 * Authentication resource — every endpoint under `/login`, `/authentication/*`
 * and `/users/api-keys`.
 *
 * These endpoints are mostly used to bootstrap a user session. The Assinafy
 * API supports three concurrent auth schemes:
 *   1. `X-Api-Key` header (the SDK's default — see {@see Configuration})
 *   2. `Authorization: Bearer <token>` header (set via headers in this resource)
 *   3. `?access-token=<token>` query parameter
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class AuthResource extends AbstractResource
{
    /**
     * Sign in with email + password.
     * `POST /login`
     *
     * Returns `{ access_token, user, accounts }`.
     */
    public function login(string $email, string $password): array
    {
        $response = $this->httpClient->post('login', [
            'email' => $email,
            'password' => $password,
        ]);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Sign in with a social provider (Google, etc.).
     * `POST /authentication/social-login`
     */
    public function socialLogin(string $provider, string $token, bool $hasAcceptedTerms = false): array
    {
        $response = $this->httpClient->post('authentication/social-login', [
            'provider' => $provider,
            'token' => $token,
            'has_accepted_terms' => $hasAcceptedTerms,
        ]);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Generate (or regenerate) the API key for the authenticated user.
     * `POST /users/api-keys`  — requires a Bearer access token.
     */
    public function generateApiKey(string $accessToken, string $password): array
    {
        $response = $this->httpClient->post(
            'users/api-keys',
            ['password' => $password],
            $this->bearer($accessToken)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Retrieve the masked API key for the authenticated user.
     * `GET /users/api-keys` — requires a Bearer access token.
     */
    public function getApiKey(string $accessToken): array
    {
        $response = $this->httpClient->get('users/api-keys', [], $this->bearer($accessToken));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Delete the API key for the authenticated user.
     * `DELETE /users/api-keys` — requires a Bearer access token.
     */
    public function deleteApiKey(string $accessToken): array
    {
        $response = $this->httpClient->delete('users/api-keys', $this->bearer($accessToken));

        return $response->getData() ?? [];
    }

    /**
     * Change the password of the authenticated user.
     * `PUT /authentication/change-password` — requires a Bearer access token.
     */
    public function changePassword(string $accessToken, string $email, string $password, string $newPassword): array
    {
        $response = $this->httpClient->put(
            'authentication/change-password',
            ['email' => $email, 'password' => $password, 'new_password' => $newPassword],
            $this->bearer($accessToken)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Trigger a password-reset email.
     * `PUT /authentication/request-password-reset`
     */
    public function requestPasswordReset(string $email): array
    {
        $response = $this->httpClient->put('authentication/request-password-reset', [
            'email' => $email,
        ]);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Complete a password reset using the token emailed to the user.
     * `PUT /authentication/reset-password`
     */
    public function resetPassword(string $email, string $token, string $newPassword): array
    {
        $response = $this->httpClient->put('authentication/reset-password', [
            'email' => $email,
            'token' => $token,
            'new_password' => $newPassword,
        ]);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $accessToken): array
    {
        return ['Authorization' => 'Bearer ' . $accessToken];
    }
}
