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
     * The signer's "who am I". The `has_signature` / `has_initial` flags tell you whether
     * they still need to draw one before signing — see {@see self::uploadSignature()}.
     *
     * Request (query string): `signer-access-code`.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'resource' => 'signer',
     *     'id' => 'signer-id',
     *     'full_name' => 'John Signer',
     *     'email' => 'person@example.com',
     *     'whatsapp_phone_number' => '+5548999990000',
     *     'has_accepted_terms' => false,
     *     'has_signature' => true,
     *     'has_initial' => false,
     *     'is_signature_reusable' => false,
     * ]
     * ```
     *
     * @return array<string, mixed>
     * @throws ValidationException when the access code is blank
     * @throws \Assinafy\SDK\Exceptions\ApiException 401 when the access code is wrong
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
     * A prerequisite for signing: until this is called, `has_accepted_terms` on
     * {@see self::self()} stays false and the signing routes refuse. Record it once per
     * signer, not per document.
     *
     * Request: no body; the credential travels as the `signer-access-code` query parameter.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'status' => 200,
     *     'message' => '',
     * ]
     * ```
     *
     * @return array<array-key, mixed>
     * @throws ValidationException when the access code is blank
     * @throws \Assinafy\SDK\Exceptions\ApiException 401 when the access code is wrong
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
     * Verify the one-time code sent to the signer's email/WhatsApp.
     * `POST /verify`
     *
     * Unlocks the signing flow. `$verificationCode` is the OTP from the notification;
     * `$accessCode` is the session credential delivered through the signer channel.
     * It is separate from both the verification code and the signing URL path token.
     *
     * Request — the code goes in the body under a **kebab-case** key, while the access code
     * travels as a query parameter:
     * ```
     * POST /verify?signer-access-code=<access code>
     * ['verification-code' => '482913']
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'status' => 200,
     *     'message' => '',
     * ]
     * ```
     *
     * @return array<array-key, mixed>
     * @throws ValidationException when the access code is blank
     * @throws \Assinafy\SDK\Exceptions\ApiException 400 on a wrong or expired code
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
     * Confirm the signer's name, email, government ID, or terms acceptance.
     * `PUT /documents/{documentId}/signers/confirm-data?signer-access-code={code}`
     *
     * The `signer-access-code` is sent as a query parameter, the rest of the data
     * goes in the JSON body.
     *
     * Current API prose also requires `has_accepted_terms: true` here before a
     * digital-certificate signer can open the document, although that field is absent
     * from this operation's request schema.
     *
     * The signer confirms the identity details that will be printed on the certificate page.
     *
     * Request:
     * ```
     * PUT /documents/{documentId}/signers/confirm-data?signer-access-code=<access code>
     * [
     *   'full_name'          => 'Jane Doe',
     *   'email'              => 'jane@example.com',
     *   'government_id'      => '11144477735',
     *   'has_accepted_terms' => true,
     * ]
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'resource' => 'signer',
     *     'id' => 'signer-id',
     *     'full_name' => 'Jane Doe',
     *     'email' => 'jane@example.com',
     *     'whatsapp_phone_number' => '+5548999990000',
     *     'has_accepted_terms' => true,
     * ]
     * ```
     *
     * @param array<string, mixed> $data subset of { full_name, email, government_id,
     *     has_accepted_terms }
     * @return array<string, mixed> the confirmed signer data
     * @throws ValidationException when the document ID or access code is blank
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
     * `POST /signature?type=signature|initial&signer-access-code={code}`
     *
     * The drawn or typed mark that gets stamped onto the document. Sent as a **raw image
     * body** with a `Content-Type` of `image/png` or `image/jpeg` — not multipart, and not
     * base64. Pass the bytes themselves:
     * ```php
     * $client->signerSession()->uploadSignature(
     *     $accessCode,
     *     SignerSessionResource::TYPE_SIGNATURE,
     *     file_get_contents('signature.png'),
     * );
     * ```
     *
     * `$reuse = true` stores the image for the signer's later documents, so they don't have
     * to redraw it; that is what `is_signature_reusable` on {@see self::self()} reports.
     *
     * Request (query string): `type=signature|initial`, `signer-access-code`, and `reuse`
     * when supplied. Body: the raw image bytes.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'status' => 200,
     *     'message' => '',
     * ]
     * ```
     *
     * @param string    $type      {@see self::TYPE_SIGNATURE} or {@see self::TYPE_INITIAL}
     * @param string    $imageBytes raw PNG/JPEG bytes
     * @param string    $mimeType  `image/png` or `image/jpeg`
     * @param bool|null $reuse     store the image for the signer's future documents
     * @return array<array-key, mixed>
     * @throws ValidationException on an unknown type, empty bytes, an unsupported mime type,
     *     or a blank access code
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
     *
     * Reads back what {@see self::uploadSignature()} stored — useful for showing a signer
     * their existing mark and offering to reuse it. Check `has_signature` / `has_initial` on
     * {@see self::self()} first to avoid a 404.
     *
     * Request (query string): `signer-access-code`. The type is a path segment.
     *
     * Response: raw image bytes — not the JSON envelope.
     *
     * @param string $type {@see self::TYPE_SIGNATURE} or {@see self::TYPE_INITIAL}
     * @return string raw PNG/JPEG bytes
     * @throws ValidationException on an unknown type or a blank access code
     * @throws \Assinafy\SDK\Exceptions\ApiException 404 when nothing has been stored
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
     * The signer's view of what they have been asked to sign. Requires the access code and
     * a completed {@see self::verifyCode()}. The response mirrors the document shape, plus
     * the signer's `current_signer` and the assignment `items` they must complete — those
     * `items[].id` values are the `itemId`s {@see self::sign()} expects.
     *
     * Request (query string): `signer-access-code`, plus `has_accepted_terms` when supplied.
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * [
     *     'resource' => 'document',
     *     'id' => 'document-id',
     *     'account_id' => 'account-id',
     *     'template_id' => null,
     *     'name' => 'agreement.pdf',
     *     'status' => 'pending_signature',
     *     'artifacts' => [
     *         'original' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/download/original',
     *         'thumbnail' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/thumbnail',
     *     ],
     *     'is_closed' => false,
     *     'signing_url' => 'https://app-sandbox.assinafy.com.br/sign/signing-token',
     *     'decline_reason' => null,
     *     'declined_by' => null,
     *     'tags' => [],
     *     'created_at' => '2026-09-01T12:00:00Z',
     *     'updated_at' => '2026-09-01T12:00:00Z',
     *     'assignment' => [
     *         'id' => 'assignment-id',
     *         'sender_email' => 'person@example.com',
     *         'method' => 'collect',
     *         'expires_at' => null,
     *         'message' => null,
     *         'signers' => [
     *             [
     *                 'id' => 'signer-id',
     *                 'full_name' => 'Example Signer',
     *                 'email' => 'person@example.com',
     *                 'whatsapp_phone_number' => null,
     *                 'government_id' => null,
     *                 'has_accepted_terms' => false,
     *                 'completed' => false,
     *                 'notification_history' => [
     *                     [
     *                         'event' => 'signature_request',
     *                         'status' => 'sent',
     *                         'error_code' => null,
     *                         'error_message' => null,
     *                         'sent_at' => '2026-09-01T12:00:00Z',
     *                         'failed_at' => null,
     *                     ],
     *                 ],
     *                 'verification_method' => 'Email',
     *                 'notification_methods' => ['Email'],
     *                 'step' => 1,
     *                 'notified' => true,
     *             ],
     *         ],
     *         'copy_receivers' => [],
     *         'items' => [
     *             [
     *                 'id' => 'assignment-item-id',
     *                 'page' => [
     *                     'id' => 'page-id',
     *                     'number' => 1,
     *                     'height' => 1651,
     *                     'width' => 1275,
     *                     'download_url' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/pages/page-id/download',
     *                 ],
     *                 'signer' => [
     *                     'id' => 'signer-id',
     *                     'full_name' => 'Example Signer',
     *                     'email' => 'person@example.com',
     *                     'whatsapp_phone_number' => null,
     *                     'government_id' => null,
     *                     'has_accepted_terms' => false,
     *                 ],
     *                 'field' => [
     *                     'id' => 'field-id',
     *                     'name' => 'Assinatura',
     *                     'type' => 'signature',
     *                     'regex' => null,
     *                     'is_pre_defined' => true,
     *                     'is_active' => true,
     *                     'is_required' => true,
     *                     'is_standard' => true,
     *                     'is_read_only' => false,
     *                     'is_visible' => true,
     *                 ],
     *                 'display_settings' => [
     *                     'top' => 10,
     *                     'left' => 10,
     *                     'width' => 240,
     *                     'height' => 60,
     *                     'fontSize' => 18,
     *                 ],
     *                 'value' => null,
     *                 'completed' => false,
     *             ],
     *         ],
     *         'summary' => [
     *             'signer_count' => 1,
     *             'completed_count' => 0,
     *             'signers' => [
     *                 [
     *                     'id' => 'signer-id',
     *                     'full_name' => 'Example Signer',
     *                     'email' => 'person@example.com',
     *                     'whatsapp_phone_number' => null,
     *                     'government_id' => null,
     *                     'has_accepted_terms' => false,
     *                     'completed' => false,
     *                 ],
     *             ],
     *         ],
     *         'signing_urls' => [
     *             [
     *                 'signer_id' => 'signer-id',
     *                 'url' => 'https://app-sandbox.assinafy.com.br/sign/signing-token?email=signer%40example.com',
     *             ],
     *         ],
     *     ],
     *     'pages' => [
     *         [
     *             'id' => 'page-id',
     *             'number' => 1,
     *             'height' => 1651,
     *             'width' => 1275,
     *             'download_url' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/pages/page-id/download',
     *         ],
     *     ],
     * ]
     * ```
     *
     * @param bool|null $hasAcceptedTerms sent as a query flag when supplied
     * @return array<string, mixed>
     * @throws ValidationException when the access code is blank
     * @throws \Assinafy\SDK\Exceptions\ApiException 401 before the code is verified
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
     * The act of signing: submits a value for every assignment item. For virtual assignments
     * the signer must first call {@see confirmData()}. Take the three IDs from the
     * `assignment.items` array on {@see self::currentDocument()}.
     *
     * The request body is a bare JSON **array**, not an object with a wrapper key — the SDK
     * re-indexes `$fields` so a filtered PHP array still encodes as a JSON list.
     *
     * Request:
     * ```
     * POST /documents/{documentId}/assignments/{assignmentId}?signer-access-code=<access code>
     * [
     *   [
     *     'itemId'  => 'assignment-item-id',
     *     'fieldId' => 'field-id',
     *     'pageId'  => 'page-id',
     *     'value'   => '111.444.777-35',
     *   ],
     * ]
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * []
     * ```
     *
     * When this signer is the last one outstanding, the document moves to `ready` and
     * certification begins.
     *
     * @param array<int, array{itemId: string, fieldId: string, pageId: string, value: string}> $fields
     * @return array<array-key, mixed>
     * @throws ValidationException when an identifier or the access code is blank
     * @throws \Assinafy\SDK\Exceptions\ApiException 400 when an item is missing or invalid
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
     * Terminal for the whole document, not just this signer: the status becomes
     * `rejected_by_signer` and the remaining signers are never asked. The reason is
     * mandatory and is surfaced on the document as `decline_reason`.
     *
     * Request:
     * ```
     * PUT /documents/{documentId}/assignments/{assignmentId}/reject?signer-access-code=<access code>
     * ['decline_reason' => 'The payment terms are wrong']
     * ```
     *
     * Example response (SDK return; optional fields depend on state):
     * ```php
     * []
     * ```
     *
     * @param string $reason why the signer refuses; required and non-empty
     * @return array<array-key, mixed>
     * @throws ValidationException when no reason is provided, or an identifier is blank
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
