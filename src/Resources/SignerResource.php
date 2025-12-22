<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Exceptions\ValidationException;

class SignerResource extends AbstractResource
{
    public function create(
        string $name,
        string $email,
        ?string $cpf = null,
        ?string $phone = null,
        array $metadata = []
    ): array {
        $this->validateEmail($email);

        $existingSigner = $this->findByEmail($email);
        if ($existingSigner) {
            $this->logger->info("Using existing signer", ['email' => $email]);
            return $this->normalizeSignerResponse($existingSigner);
        }

        $payload = [
            'full_name' => $name,
            'email' => $email,
            'metadata' => $metadata,
        ];

        if ($cpf) {
            $payload['cpf'] = $this->sanitizeDocument($cpf);
        }

        if ($phone) {
            $payload['phone'] = $this->sanitizePhone($phone);
        }

        $this->logger->info("Creating new signer", ['email' => $email]);

        try {
            $response = $this->httpClient->post(
                "accounts/{$this->config->getAccountId()}/signers",
                $payload
            );

            $data = $response->getData() ?? [];

            return $this->normalizeSignerResponse($this->extractData($data));
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (strpos($message, 'já existe') !== false || strpos($message, 'already exists') !== false) {
                $existingSigner = $this->findByEmail($email);
                if ($existingSigner) {
                    $this->logger->info("Signer already exists, using existing", ['email' => $email]);
                    return $this->normalizeSignerResponse($existingSigner);
                }
            }

            throw $e;
        }
    }

    public function get(string $signerId): array
    {
        $response = $this->httpClient->get(
            "accounts/{$this->config->getAccountId()}/signers/{$signerId}"
        );

        return $this->extractData($response->getData() ?? []);
    }

    public function list(int $page = 1, int $perPage = 20, ?string $search = null): array
    {
        $params = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($search) {
            $params['search'] = $search;
        }

        $response = $this->httpClient->get(
            "accounts/{$this->config->getAccountId()}/signers",
            $params
        );

        return $response->getData() ?? [];
    }

    public function findByEmail(string $email): ?array
    {
        try {
            $response = $this->httpClient->get(
                "accounts/{$this->config->getAccountId()}/signers",
                [
                    'search' => $email,
                    'per_page' => 100,
                ]
            );

            $data = $response->getData() ?? [];
            $signers = $data['data'] ?? [];

            foreach ($signers as $signer) {
                if (strtolower($signer['email']) === strtolower($email)) {
                    return $signer;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning("Error searching for signer by email", [
                'email' => $email,
                'exception' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function validateEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException("Invalid email address", ['email' => $email]);
        }
    }

    private function sanitizeDocument(string $document): string
    {
        return preg_replace('/[^0-9]/', '', $document) ?? '';
    }

    private function sanitizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }

    private function normalizeSignerResponse(array $signer): array
    {
        return [
            'data' => [
                'id' => $signer['id'] ?? null,
                'full_name' => $signer['full_name'] ?? null,
                'email' => $signer['email'] ?? null,
                'cpf' => $signer['cpf'] ?? null,
                'phone' => $signer['phone'] ?? null,
            ],
        ];
    }
}
