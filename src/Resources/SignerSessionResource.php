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
     */
    public function self(string $accessCode): array
    {
        $response = $this->httpClient->get('signers/self', ['signer-access-code' => $accessCode]);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Accept terms of use.
     * `PUT /signers/accept-terms`
     */
    public function acceptTerms(string $accessCode): array
    {
        $response = $this->httpClient->put('signers/accept-terms', [
            'signer-access-code' => $accessCode,
        ]);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Verify the 6-digit code sent to the signer's email/WhatsApp.
     * `POST /verify`
     */
    public function verifyCode(string $accessCode, string $verificationCode): array
    {
        $response = $this->httpClient->post('verify', [
            'signer-access-code' => $accessCode,
            'verification-code' => $verificationCode,
        ]);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Confirm (or set) the signer's email / WhatsApp / terms acceptance.
     * `PUT /documents/{documentId}/signers/confirm-data?signer-access-code={code}`
     *
     * @param array<string, mixed> $data subset of { email, whatsapp_phone_number, has_accepted_terms }
     */
    public function confirmData(string $documentId, string $accessCode, array $data): array
    {
        $response = $this->httpClient->put(
            "documents/{$documentId}/signers/confirm-data?signer-access-code=" . rawurlencode($accessCode),
            $data
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Upload a signature or initial image (PNG/JPEG bytes).
     * `POST /signature?type=signature|initial&signer-access-code=…`
     */
    public function uploadSignature(string $accessCode, string $type, string $imageBytes, string $mimeType = 'image/png'): array
    {
        $this->assertType($type);

        if (!in_array($mimeType, ['image/png', 'image/jpeg'], true)) {
            throw new ValidationException("Unsupported image mime type '{$mimeType}'");
        }

        $response = $this->httpClient->postRaw(
            'signature',
            $imageBytes,
            $mimeType,
            ['type' => $type, 'signer-access-code' => $accessCode]
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Download the signer's saved signature or initial image (PNG bytes).
     * `GET /signature/{type}?signer-access-code={code}`
     */
    public function downloadSignature(string $accessCode, string $type): string
    {
        $this->assertType($type);

        $response = $this->httpClient->get(
            "signature/{$type}",
            ['signer-access-code' => $accessCode]
        );

        return $response->getBody();
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
