<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

/**
 * Assignments resource — every endpoint under `/documents/{document_id}/assignments`.
 *
 * @see https://api.assinafy.com.br/v1/docs
 */
class AssignmentResource extends AbstractResource
{
    public const METHOD_VIRTUAL = 'virtual';
    public const METHOD_COLLECT = 'collect';

    public const VERIFICATION_EMAIL = 'Email';
    public const VERIFICATION_WHATSAPP = 'Whatsapp';

    /**
     * Create an assignment (signature request).
     * `POST /documents/{document_id}/assignments`
     *
     * @param array<int, string|array<string, mixed>> $signers
     *     Either a list of signer IDs (strings) or a list of `{ id, verification_method?, notification_methods? }`
     *     objects. String IDs are normalized to `{ id }` objects before being sent.
     * @param array<string, mixed> $options
     *     Optional keys: `entries` (required for collect), `message`, `expires_at`, `copy_receivers`.
     */
    public function create(
        string $documentId,
        array $signers,
        string $method = self::METHOD_VIRTUAL,
        array $options = []
    ): array {
        $this->assertMethod($method);
        $this->assertSigners($signers);

        $payload = array_merge(
            [
                'method' => $method,
                'signers' => $this->normalizeSigners($signers),
            ],
            $options
        );

        $response = $this->httpClient->post("documents/{$documentId}/assignments", $payload);

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Estimate the credit cost of creating an assignment.
     * `POST /documents/{document_id}/assignments/estimate-cost`
     */
    public function estimateCost(
        string $documentId,
        array $signers,
        string $method = self::METHOD_VIRTUAL,
        array $options = []
    ): array {
        $this->assertMethod($method);

        $payload = array_merge(
            [
                'method' => $method,
                'signers' => $this->normalizeSigners($signers),
            ],
            $options
        );

        $response = $this->httpClient->post(
            "documents/{$documentId}/assignments/estimate-cost",
            $payload
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Resend the signing-notification to a single signer.
     * `PUT /documents/{document_id}/assignments/{assignment_id}/signers/{signer_id}/resend`
     */
    public function resend(string $documentId, string $assignmentId, string $signerId): array
    {
        $response = $this->httpClient->put(
            "documents/{$documentId}/assignments/{$assignmentId}/signers/{$signerId}/resend"
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Estimate the credit cost of resending a notification to one signer.
     * `POST /documents/{document_id}/assignments/{assignment_id}/signers/{signer_id}/estimate-resend-cost`
     */
    public function estimateResendCost(string $documentId, string $assignmentId, string $signerId): array
    {
        $response = $this->httpClient->post(
            "documents/{$documentId}/assignments/{$assignmentId}/signers/{$signerId}/estimate-resend-cost"
        );

        return $this->extractData($response->getData() ?? []);
    }

    /**
     * Reset the expiration date of an assignment.
     * `PUT /documents/{document_id}/assignments/{assignment_id}/reset-expiration`
     */
    public function resetExpiration(string $documentId, string $assignmentId, string $expiresAt): array
    {
        $response = $this->httpClient->put(
            "documents/{$documentId}/assignments/{$assignmentId}/reset-expiration",
            ['expires_at' => $expiresAt]
        );

        return $this->extractData($response->getData() ?? []);
    }

    private function assertMethod(string $method): void
    {
        if (!in_array($method, [self::METHOD_VIRTUAL, self::METHOD_COLLECT], true)) {
            throw new ValidationException(
                "Invalid assignment method '{$method}'",
                ['allowed' => [self::METHOD_VIRTUAL, self::METHOD_COLLECT]]
            );
        }
    }

    /**
     * @param array<int, mixed> $signers
     */
    private function assertSigners(array $signers): void
    {
        if (empty($signers)) {
            throw new ValidationException('At least one signer is required', ['signers' => $signers]);
        }
    }

    /**
     * Accept either string signer IDs or full signer objects and produce the
     * `signers: [{ id, verification_method?, notification_methods? }]` shape
     * documented by the API.
     *
     * @param array<int, mixed> $signers
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSigners(array $signers): array
    {
        $normalized = [];

        foreach ($signers as $signer) {
            if (is_string($signer)) {
                $normalized[] = ['id' => $signer];
                continue;
            }

            if (is_array($signer)) {
                $id = $signer['id'] ?? $signer['signer_id'] ?? null;

                if ($id === null) {
                    throw new ValidationException('Signer entry missing id', ['signer' => $signer]);
                }

                $entry = ['id' => (string) $id];

                if (isset($signer['verification_method'])) {
                    $entry['verification_method'] = $signer['verification_method'];
                }

                if (isset($signer['notification_methods'])) {
                    $entry['notification_methods'] = $signer['notification_methods'];
                }

                if (isset($signer['role_id'])) {
                    $entry['role_id'] = $signer['role_id'];
                }

                $normalized[] = $entry;
                continue;
            }

            throw new ValidationException('Invalid signer entry', ['signer' => $signer]);
        }

        return $normalized;
    }
}
