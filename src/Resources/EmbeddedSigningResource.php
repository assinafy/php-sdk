<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

/** Embedded signer sessions; configure allowed origins through AccountResource first. */
class EmbeddedSigningResource extends AbstractResource
{
    /**
     * Mint a single-use URL for a prepared document's active, embedded signer.
     * POST /documents/{documentId}/assignments/{assignmentId}/signers/{signerId}/embed-sessions
     *
     * Requires an API key or user-session Bearer, the Embed plan feature and a registered
     * origin. Marketplace OAuth tokens cannot create sessions. Request: no body.
     * ```php
     * $client->embeddedSigning()->createSession('document-id', 'assignment-id', 'signer-id');
     * // ['url' => 'https://app.assinafy.com.br/embed/<token>',
     * //  'expires_at' => '2027-01-01T12:05:00Z']
     * ```
     * Load the URL within five minutes; mint a new one for each iframe opening. The API
     * permits 30 sessions per signer per hour. No signer access code is returned.
     *
     * @param string $documentId Document containing the assignment
     * @param string $assignmentId Existing assignment ID
     * @param string $signerId Signer marked embedded on that assignment
     * @return array<string, mixed> Unwrapped session: url and expires_at
     * @throws \Assinafy\SDK\Exceptions\ValidationException on an empty identifier
     * @throws \Assinafy\SDK\Exceptions\ApiException 400 if not embedded, 403 if plan restricted,
     *     404 if missing, 409 if already signed, not ready, unavailable or origins missing;
     *     429 when the hourly limit is reached
     */
    public function createSession(string $documentId, string $assignmentId, string $signerId): array
    {
        $this->bearerHeaders(null);
        $response = $this->httpClient->post(
            'documents/' . $this->pathSegment($documentId, 'document ID')
            . '/assignments/' . $this->pathSegment($assignmentId, 'assignment ID')
            . '/signers/' . $this->pathSegment($signerId, 'signer ID') . '/embed-sessions'
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Exchange a session token once. Intended for Assinafy's hosted embed page;
     * integrations normally load the createSession() URL instead of exchanging it.
     * POST /embed/sessions/{token}/exchange
     *
     * Public: the path token is the credential; workspace credentials are stripped.
     * Optional request: ['parent_origin' => 'https://app.example.com']; omitted sends no body.
     * ```php
     * $public->embeddedSigning()->exchangeSession($token, 'https://app.example.com');
     * // ['signer_access_code' => '<signer-access-code>', 'signer_id' => 'signer-id',
     * //  'document_id' => 'document-id', 'verification_method' => 'Email',
     * //  'embed_origins' => ['https://app.example.com']]
     * ```
     * Even a 409 refusal spends the token. Never retry or log the token or returned code.
     *
     * @param string $token Single-use session token from the embed URL
     * @param string|null $parentOrigin Origin of the page hosting the iframe
     * @return array<string, mixed> Unwrapped EmbedSessionExchange
     * @throws \Assinafy\SDK\Exceptions\ValidationException on an empty token
     * @throws \Assinafy\SDK\Exceptions\ApiException 409 if signing is unavailable;
     *     410 for an unknown, expired or already used token
     */
    public function exchangeSession(#[\SensitiveParameter] string $token, ?string $parentOrigin = null): array
    {
        $response = $this->httpClient->post(
            'embed/sessions/' . $this->pathSegment($token, 'embed session token') . '/exchange',
            $parentOrigin === null ? null : ['parent_origin' => $parentOrigin]
        );

        return $this->extractData($response->getData() ?? []);
    }
}
