<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Signer-facing session resource — endpoints consumed by the end-signer in the
 * browser, authenticated by a `signer-access-code` (not by the workspace API key).
 *
 * Covers:
 *   - GET  /signers/self
 *   - PUT  /signers/accept-terms
 *   - POST /verify
 *   - PUT  /documents/{documentId}/signers/confirm-data
 *   - POST /signature
 *   - GET  /signature/{type}
 *   - GET  /sign                                       (current document/assignment view)
 *   - POST /documents/{documentId}/assignments/{id}    (sign — collect method)
 *   - PUT  /documents/{documentId}/assignments/{id}/reject (decline)
 *
 * The access code is required for every call.
 */
class SignerSessionResource extends AbstractResource
{
    public const TYPE_SIGNATURE = 'signature';
    public const TYPE_INITIAL = 'initial';

    /**
     * Get the signer's own profile.
     * `GET /signers/self?signer-access-code={code}`
     *
     * @return array<string, mixed>
     */
    public function self(#[\SensitiveParameter] string $accessCode): array
    {
        $response = $this->httpClient->get('signers/self', $this->accessCodeQuery($accessCode));

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Accept terms of use.
     * `PUT /signers/accept-terms`
     *
     * @return array<array-key, mixed>
     */
    public function acceptTerms(#[\SensitiveParameter] string $accessCode): array
    {
        $response = $this->httpClient->put(
            'signers/accept-terms',
            null,
            [],
            $this->accessCodeQuery($accessCode)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Verify the 6-digit code sent to the signer's email/WhatsApp.
     * `POST /verify`
     *
     * @return array<array-key, mixed>
     */
    public function verifyCode(
        #[\SensitiveParameter] string $accessCode,
        #[\SensitiveParameter] string $verificationCode
    ): array {
        $response = $this->httpClient->post(
            'verify',
            ['verification-code' => $verificationCode],
            [],
            $this->accessCodeQuery($accessCode)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Confirm (or set) the signer's email / WhatsApp / terms acceptance.
     * `PUT /documents/{documentId}/signers/confirm-data?signer-access-code={code}`
     *
     * The `signer-access-code` is sent as a query parameter, the rest of the data
     * goes in the JSON body.
     *
     * Current API prose also requires `has_accepted_terms: true` here before a
     * digital-certificate signer can open the document, although that field is absent
     * from this operation's request schema.
     *
     * @param array<string, mixed> $data subset of { full_name, email, government_id,
     *     has_accepted_terms }
     * @return array<string, mixed> the confirmed signer data
     */
    public function confirmData(
        string $documentId,
        #[\SensitiveParameter] string $accessCode,
        #[\SensitiveParameter] array $data
    ): array {
        $documentId = $this->pathSegment($documentId, 'document ID');
        $response = $this->httpClient->put(
            "documents/{$documentId}/signers/confirm-data",
            $data,
            [],
            $this->accessCodeQuery($accessCode)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Upload a signature or initial image (PNG/JPEG bytes).
     * `POST /signature?type=signature|initial&signer-access-code=…`
     *
     * @return array<array-key, mixed>
     */
    public function uploadSignature(
        #[\SensitiveParameter] string $accessCode,
        string $type,
        #[\SensitiveParameter] string $imageBytes,
        string $mimeType = 'image/png',
        ?bool $reuse = null
    ): array {
        $this->assertType($type);

        if ($imageBytes === '') {
            throw new ValidationException('Signature image cannot be empty');
        }

        if (!in_array($mimeType, ['image/png', 'image/jpeg'], true)) {
            throw new ValidationException("Unsupported image mime type '{$mimeType}'");
        }

        $query = array_merge(['type' => $type], $this->accessCodeQuery($accessCode));
        if ($reuse !== null) {
            $query['reuse'] = $reuse ? 'true' : 'false';
        }

        $response = $this->httpClient->postRaw(
            'signature',
            $imageBytes,
            $mimeType,
            $query
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Download the signer's saved signature or initial image (raw PNG/JPEG bytes).
     * `GET /signature/{type}?signer-access-code={code}`
     */
    public function downloadSignature(#[\SensitiveParameter] string $accessCode, string $type): string
    {
        $this->assertType($type);

        $response = $this->httpClient->get(
            "signature/{$type}",
            $this->accessCodeQuery($accessCode)
        );

        return $response->getBody();
    }

    /**
     * Retrieve the document/assignment the signer currently has access to.
     * `GET /sign?signer-access-code={code}`
     *
     * Requires the access code (and a verified code on the underlying request). The
     * response mirrors the document shape with the signer's `current_signer` and the
     * assignment items they must complete.
     *
     * @return array<string, mixed>
     */
    public function currentDocument(
        #[\SensitiveParameter] string $accessCode,
        ?bool $hasAcceptedTerms = null
    ): array {
        $query = $this->accessCodeQuery($accessCode);
        if ($hasAcceptedTerms !== null) {
            $query['has_accepted_terms'] = $hasAcceptedTerms ? 'true' : 'false';
        }

        $response = $this->httpClient->get('sign', $query);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Sign a document with input fields (collect method).
     * `POST /documents/{documentId}/assignments/{assignmentId}?signer-access-code={code}`
     *
     * For virtual assignments the signer must first call {@see confirmData()}.
     *
     * @param array<int, array{itemId: string, fieldId: string, pageId: string, value: string}> $fields
     * @return array<array-key, mixed>
     */
    public function sign(
        string $documentId,
        string $assignmentId,
        #[\SensitiveParameter] string $accessCode,
        #[\SensitiveParameter] array $fields
    ): array {
        $documentId = $this->pathSegment($documentId, 'document ID');
        $assignmentId = $this->pathSegment($assignmentId, 'assignment ID');

        $response = $this->httpClient->post(
            "documents/{$documentId}/assignments/{$assignmentId}",
            array_values($fields),
            [],
            $this->accessCodeQuery($accessCode)
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Decline (reject) an assignment as a signer.
     * `PUT /documents/{documentId}/assignments/{assignmentId}/reject?signer-access-code={code}`
     *
     * @return array<array-key, mixed>
     * @throws ValidationException when no reason is provided
     */
    public function decline(
        string $documentId,
        string $assignmentId,
        #[\SensitiveParameter] string $accessCode,
        #[\SensitiveParameter] string $reason
    ): array {
        if (trim($reason) === '') {
            throw new ValidationException('A decline reason is required');
        }

        $documentId = $this->pathSegment($documentId, 'document ID');
        $assignmentId = $this->pathSegment($assignmentId, 'assignment ID');

        $response = $this->httpClient->put(
            "documents/{$documentId}/assignments/{$assignmentId}/reject",
            ['decline_reason' => $reason],
            [],
            $this->accessCodeQuery($accessCode)
        );

        return $this->extractData($response->getData() ?? []);
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, [self::TYPE_SIGNATURE, self::TYPE_INITIAL], true)) {
            throw new ValidationException("Invalid signature type '{$type}'", [
                'allowed' => [self::TYPE_SIGNATURE, self::TYPE_INITIAL],
            ]);
        }
    }
}
