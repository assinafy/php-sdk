<?php

declare(strict_types=1);

namespace Assinafy\SDK;

use Assinafy\SDK\Http\GuzzleHttpClient;
use Assinafy\SDK\Http\HttpClientInterface;
use Assinafy\SDK\Resources\AccountResource;
use Assinafy\SDK\Resources\AssignmentResource;
use Assinafy\SDK\Resources\AuthResource;
use Assinafy\SDK\Resources\DocumentResource;
use Assinafy\SDK\Resources\FieldResource;
use Assinafy\SDK\Resources\SignerDocumentResource;
use Assinafy\SDK\Resources\SignerResource;
use Assinafy\SDK\Resources\SignerSessionResource;
use Assinafy\SDK\Resources\TagResource;
use Assinafy\SDK\Resources\TemplateResource;
use Assinafy\SDK\Resources\UserResource;
use Assinafy\SDK\Resources\WebhookResource;
use Assinafy\SDK\Support\MutableLogger;
use Assinafy\SDK\Support\WebhookEventParser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AssinafyClient
{
    private Configuration $config;
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private MutableLogger $loggerProxy;

    private ?AccountResource $accounts = null;
    private ?DocumentResource $documents = null;
    private ?SignerResource $signers = null;
    private ?AssignmentResource $assignments = null;
    private ?TemplateResource $templates = null;
    private ?TagResource $tags = null;
    private ?FieldResource $fields = null;
    private ?WebhookResource $webhooks = null;
    private ?AuthResource $auth = null;
    private ?SignerSessionResource $signerSession = null;
    private ?SignerDocumentResource $signerDocuments = null;
    private ?WebhookEventParser $webhookEvents = null;
    private ?UserResource $users = null;

    public function __construct(
        #[\SensitiveParameter] Configuration $config,
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null
    ) {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->loggerProxy = new MutableLogger($this->logger);
        $this->httpClient = $httpClient ?? new GuzzleHttpClient($config, $this->loggerProxy);
    }

    public static function create(
        #[\SensitiveParameter] string $apiKey,
        string $accountId,
        string $baseUrl = Configuration::DEFAULT_BASE_URL
    ): self {
        return new self(new Configuration($apiKey, $accountId, $baseUrl));
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(#[\SensitiveParameter] array $config): self
    {
        return new self(Configuration::fromArray($config));
    }

    /**
     * Build a client for the unauthenticated surface of the API — the place where
     * you don't yet have an API key.
     *
     * Lets you call `$client->auth()->login(...)`, `requestPasswordReset(...)`,
     * `resetPassword(...)`, `socialLogin(...)`, and the public document endpoints
     * (`verify`, `publicInfo`, `sendToken`) without having to fabricate credentials
     * just to satisfy the Configuration constructor.
     *
     * Calling an account-scoped resource on a public client (e.g. `$client->signers()->list()`)
     * raises a `\RuntimeException` with a clear message — see {@see Configuration::forPublic()}.
     */
    public static function forAuth(string $baseUrl = Configuration::DEFAULT_BASE_URL): self
    {
        return new self(Configuration::forPublic($baseUrl));
    }

    /**
     * Build an account-scoped client authenticated with a Bearer access token.
     */
    public static function forBearer(
        #[\SensitiveParameter] string $accessToken,
        string $accountId,
        string $baseUrl = Configuration::DEFAULT_BASE_URL
    ): self {
        return new self(Configuration::forBearer($accessToken, $accountId, $baseUrl));
    }

    /**
     * Accounts (workspaces).
     *
     * `accounts()->list()` and `accounts()->create()` are not account-scoped. On a client built
     * with {@see self::forAuth()}, pass the Bearer token returned by `auth()->login()`. The
     * remaining methods act on the configured account using its API key or global Bearer token.
     */
    public function accounts(): AccountResource
    {
        if ($this->accounts === null) {
            $this->accounts = new AccountResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->accounts;
    }

    public function documents(): DocumentResource
    {
        if ($this->documents === null) {
            $this->documents = new DocumentResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->documents;
    }

    public function signers(): SignerResource
    {
        if ($this->signers === null) {
            $this->signers = new SignerResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->signers;
    }

    public function assignments(): AssignmentResource
    {
        if ($this->assignments === null) {
            $this->assignments = new AssignmentResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->assignments;
    }

    public function templates(): TemplateResource
    {
        if ($this->templates === null) {
            $this->templates = new TemplateResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->templates;
    }

    public function tags(): TagResource
    {
        if ($this->tags === null) {
            $this->tags = new TagResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->tags;
    }

    public function fields(): FieldResource
    {
        if ($this->fields === null) {
            $this->fields = new FieldResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->fields;
    }

    public function webhooks(): WebhookResource
    {
        if ($this->webhooks === null) {
            $this->webhooks = new WebhookResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->webhooks;
    }

    public function auth(): AuthResource
    {
        if ($this->auth === null) {
            $this->auth = new AuthResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->auth;
    }

    public function signerSession(): SignerSessionResource
    {
        if ($this->signerSession === null) {
            $this->signerSession = new SignerSessionResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->signerSession;
    }

    public function signerDocuments(): SignerDocumentResource
    {
        if ($this->signerDocuments === null) {
            $this->signerDocuments = new SignerDocumentResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->signerDocuments;
    }

    /**
     * Authenticated user profile and cross-account KPI endpoints.
     */
    public function users(): UserResource
    {
        if ($this->users === null) {
            $this->users = new UserResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->users;
    }

    /**
     * Helpers for decoding incoming webhook deliveries.
     *
     * Replaces `webhookVerifier()` from 1.x. Signature verification was removed in 2.0.0 —
     * see {@see WebhookEventParser} for why and for how to secure your endpoint instead.
     */
    public function webhookEvents(): WebhookEventParser
    {
        if ($this->webhookEvents === null) {
            $this->webhookEvents = new WebhookEventParser();
        }

        return $this->webhookEvents;
    }

    /**
     * High-level helper: upload a PDF, create signers if needed, then dispatch a virtual
     * assignment to all of them.
     *
     * Each entry in `$signers` may be either:
     *   - an existing signer ID (string), or
     *   - an associative array `{ full_name (or name), email?, whatsapp_phone_number? (or phone)?,
     *     verification_method?, notification_methods? }`
     *
     * Signers without an `id` are created via the API; signers found by email (when an email
     * is supplied) are reused. Returns the created document, the assignment, and the resolved
     * signer IDs.
     *
     * @param array<int, string|array<string, mixed>> $signers
     * @return array{document: array<string, mixed>, assignment: array<string, mixed>, signer_ids: array<int, string>}
     */
    public function uploadAndRequestSignatures(
        #[\SensitiveParameter] string $filePath,
        #[\SensitiveParameter] array $signers,
        #[\SensitiveParameter] ?string $message = null,
        ?string $expiresAt = null,
        bool $waitForReady = true
    ): array {
        $this->validateSignerDescriptions($signers);
        if ($expiresAt !== null) {
            $this->validateExpiration($expiresAt);
        }

        $this->logger->info('Upload + signature workflow starting', [
            'signers_count' => count($signers),
        ]);

        $document = $this->documents()->upload($filePath);
        $documentId = $document['id'] ?? null;

        if (!is_string($documentId) || $documentId === '') {
            throw new \RuntimeException('Upload succeeded but no document id returned');
        }

        if ($waitForReady) {
            $document = $this->documents()->waitUntilReady($documentId);
        }

        $assignmentSigners = [];
        foreach ($signers as $signer) {
            $assignmentSigners[] = $this->resolveAssignmentSigner($signer);
        }
        $signerIds = array_column($assignmentSigners, 'id');

        $options = [];
        if ($message !== null) {
            $options['message'] = $message;
        }
        if ($expiresAt !== null) {
            $options['expires_at'] = $expiresAt;
        }

        $assignment = $this->assignments()->create(
            $documentId,
            $assignmentSigners,
            \Assinafy\SDK\Resources\AssignmentResource::METHOD_VIRTUAL,
            $options
        );

        return [
            'document' => $document,
            'assignment' => $assignment,
            'signer_ids' => $signerIds,
        ];
    }

    /**
     * @param string|array<string, mixed> $signer
     * @return array<string, mixed>
     */
    private function resolveAssignmentSigner(#[\SensitiveParameter] string|array $signer): array
    {
        if (is_string($signer)) {
            return ['id' => $signer];
        }

        if (isset($signer['id']) && is_string($signer['id']) && $signer['id'] !== '') {
            $signerId = $signer['id'];
        } else {
            $fullName = (string) ($signer['full_name'] ?? $signer['name'] ?? '');
            $email = $signer['email'] ?? null;
            $phone = $signer['whatsapp_phone_number'] ?? $signer['phone'] ?? null;

            $signerId = null;
            if ($email !== null) {
                $existing = $this->signers()->findByEmail((string) $email);
                if ($existing !== null && isset($existing['id'])) {
                    $signerId = (string) $existing['id'];
                }
            }

            if ($signerId === null) {
                $created = $this->signers()->create(
                    $fullName,
                    $email !== null ? (string) $email : null,
                    $phone !== null ? (string) $phone : null
                );

                if (!isset($created['id']) || !is_string($created['id']) || $created['id'] === '') {
                    throw new \RuntimeException('Signer creation returned no id');
                }

                $signerId = $created['id'];
            }
        }

        $resolved = ['id' => $signerId];
        foreach (['verification_method', 'notification_methods', 'step'] as $option) {
            if (array_key_exists($option, $signer)) {
                $resolved[$option] = $signer[$option];
            }
        }

        return $resolved;
    }

    /**
     * Validate all local input before creating the document, preventing avoidable
     * orphan uploads when a signer description is malformed.
     *
     * @param array<int, string|array<string, mixed>> $signers
     */
    private function validateSignerDescriptions(#[\SensitiveParameter] array $signers): void
    {
        if ($signers === []) {
            throw new \InvalidArgumentException('At least one signer is required');
        }

        $references = [];
        $steps = [];
        foreach ($signers as $signer) {
            $reference = null;
            if (is_string($signer)) {
                if (trim($signer) === '') {
                    throw new \InvalidArgumentException('Signer ID cannot be empty');
                }
                $reference = 'id:' . $signer;
            } elseif (is_array($signer)) {
                $id = $signer['id'] ?? null;
                $name = $signer['full_name'] ?? $signer['name'] ?? null;
                if ((!is_string($id) || trim($id) === '') && (!is_string($name) || trim($name) === '')) {
                    throw new \InvalidArgumentException(
                        'Each signer must contain a non-empty id, full_name, or name'
                    );
                }

                $email = $signer['email'] ?? null;
                if ($email !== null && (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                    throw new \InvalidArgumentException('Signer email must be valid');
                }

                $phone = $signer['whatsapp_phone_number'] ?? $signer['phone'] ?? null;
                $normalizedPhone = null;
                if ($phone !== null) {
                    if (!is_string($phone)) {
                        throw new \InvalidArgumentException('Signer phone number must be a string');
                    }
                    try {
                        $normalizedPhone = SignerResource::normalizePhoneNumber($phone);
                    } catch (\Assinafy\SDK\Exceptions\ValidationException $e) {
                        throw new \InvalidArgumentException($e->getMessage(), 0, $e);
                    }
                }

                $contactRequirements = $this->validateWorkflowSignerOptions($signer);
                if (array_key_exists('step', $signer)) {
                    $steps[] = $signer['step'];
                }

                if (!is_string($id) || trim($id) === '') {
                    if ($contactRequirements['email'] && $email === null) {
                        throw new \InvalidArgumentException(
                            'Email verification or notification requires the signer email'
                        );
                    }
                    if ($contactRequirements['phone'] && $phone === null) {
                        throw new \InvalidArgumentException(
                            'WhatsApp verification or notification requires the signer phone number'
                        );
                    }
                }

                $reference = is_string($id) && trim($id) !== ''
                    ? 'id:' . $id
                    : ($email !== null
                        ? 'email:' . strtolower($email)
                        : ($normalizedPhone !== null ? 'phone:' . $normalizedPhone : null));
            } else {
                throw new \InvalidArgumentException('Signer entries must be IDs or associative arrays');
            }

            if ($reference !== null && isset($references[$reference])) {
                throw new \InvalidArgumentException('Duplicate signer entries are not allowed');
            }
            if ($reference !== null) {
                $references[$reference] = true;
            }
        }

        if ($steps !== [] && count($steps) !== count($signers)) {
            throw new \InvalidArgumentException('Either every signer must define a step or none may');
        }
        if ($steps !== []) {
            $unique = array_values(array_unique($steps));
            sort($unique);
            foreach ($unique as $index => $step) {
                if ($step !== $index + 1) {
                    throw new \InvalidArgumentException(
                        'Signer steps must be contiguous and start at 1'
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $signer
     * @return array{email: bool, phone: bool}
     */
    private function validateWorkflowSignerOptions(#[\SensitiveParameter] array $signer): array
    {
        $verification = $signer['verification_method'] ?? null;
        $notifications = $signer['notification_methods'] ?? null;

        if (
            $verification !== null
            && !in_array(
                $verification,
                [AssignmentResource::VERIFICATION_EMAIL, AssignmentResource::VERIFICATION_WHATSAPP],
                true
            )
        ) {
            throw new \InvalidArgumentException('Unknown signer verification method');
        }

        if ($notifications !== null) {
            if (!is_array($notifications)) {
                throw new \InvalidArgumentException('Signer notification methods must be an array');
            }
            foreach ($notifications as $notification) {
                if (
                    !in_array(
                        $notification,
                        [AssignmentResource::NOTIFICATION_EMAIL, AssignmentResource::NOTIFICATION_WHATSAPP],
                        true
                    )
                ) {
                    throw new \InvalidArgumentException('Unknown signer notification method');
                }
            }
        }

        $verification ??= AssignmentResource::VERIFICATION_EMAIL;
        $notificationChannels = $notifications === null
            ? [AssignmentResource::NOTIFICATION_EMAIL]
            : array_values($notifications);

        if (array_key_exists('step', $signer) && (!is_int($signer['step']) || $signer['step'] < 1)) {
            throw new \InvalidArgumentException('Signer step must be a positive integer');
        }

        return [
            'email' => $verification === AssignmentResource::VERIFICATION_EMAIL
                || in_array(AssignmentResource::NOTIFICATION_EMAIL, $notificationChannels, true),
            'phone' => $verification === AssignmentResource::VERIFICATION_WHATSAPP
                || in_array(AssignmentResource::NOTIFICATION_WHATSAPP, $notificationChannels, true),
        ];
    }

    private function validateExpiration(string $expiresAt): void
    {
        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-](\d{2}):(\d{2}))$/';
        if (preg_match($pattern, $expiresAt, $matches) !== 1) {
            throw new \InvalidArgumentException('Expiration must be an ISO 8601 date-time');
        }
        if (isset($matches[1]) && ((int) $matches[1] > 23 || (int) $matches[2] > 59)) {
            throw new \InvalidArgumentException('Expiration must use a valid UTC offset');
        }

        try {
            new \DateTimeImmutable($expiresAt);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Expiration must be a valid ISO 8601 date-time', 0, $e);
        }

        $parseErrors = \DateTimeImmutable::getLastErrors();
        if (
            is_array($parseErrors)
            && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0)
        ) {
            throw new \InvalidArgumentException('Expiration must be a valid ISO 8601 date-time');
        }
    }

    public function getConfig(): Configuration
    {
        return $this->config;
    }

    public function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        $this->loggerProxy->setLogger($logger);

        return $this;
    }
}
