<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

/**
 * Authenticated user profile and cross-account statistics.
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class UserResource extends AbstractResource
{
    public const GRANULARITY_MONTHLY = AccountResource::GRANULARITY_MONTHLY;
    public const GRANULARITY_DAILY = AccountResource::GRANULARITY_DAILY;

    /**
     * Get the user represented by the configured API key or a Bearer token.
     * `GET /users/self`
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
     * @return array<int, array{period: string, documents_uploaded: int, documents_sent: int,
     *     signature_requests: int, signature_requests_email: int,
     *     signature_requests_whatsapp: int, signature_requests_viewed: int,
     *     signature_requests_completed: int, documents_certified: int}>
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
}
