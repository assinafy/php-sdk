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
use Assinafy\SDK\Resources\OAuthResource;
use Assinafy\SDK\Resources\SignerDocumentResource;
use Assinafy\SDK\Resources\SignerResource;
use Assinafy\SDK\Resources\SignerSessionResource;
use Assinafy\SDK\Resources\TagResource;
use Assinafy\SDK\Resources\TemplateResource;
use Assinafy\SDK\Resources\UserResource;
use Assinafy\SDK\Resources\WebhookResource;
use Assinafy\SDK\Support\Iso8601;
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

    /**
     * Construct a client without making an HTTP request.
     *
     * Uses the supplied configuration, an optional replacement transport and an optional
     * PSR-3 logger. Omitted dependencies become GuzzleHttpClient and NullLogger.
     * Obtain typed resources through the accessors; they share this configuration.
     */
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

    /**
     * Create a workspace client using an API key; no network request is made.
     *
     * Example: `AssinafyClient::create($apiKey, $accountId, Configuration::SANDBOX_BASE_URL)`.
     * Returns a configured client. Invalid credentials, account IDs or URLs throw
     * InvalidArgumentException through Configuration validation.
     */
    public static function create(
        #[\SensitiveParameter] string $apiKey,
        string $accountId,
        string $baseUrl = Configuration::DEFAULT_BASE_URL
    ): self {
        return new self(new Configuration($apiKey, $accountId, $baseUrl));
    }

    /**
     * Build a client from the keys accepted by Configuration::fromArray(), without HTTP I/O.
     *
     * Input example: `['api_key' => '<api-key>', 'account_id' => 'account-id',
     * 'base_url' => Configuration::SANDBOX_BASE_URL, 'timeout' => 30, 'connect_timeout' => 10]`.
     * Returns a configured client; the configuration validates types and required values.
     *
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

    /**
     * Documents: upload, list, search, rename, download, tag, and delete.
     *
     * The heart of the SDK. A document is uploaded, processed asynchronously into pages,
     * then assigned for signature via {@see self::assignments()}. Account-scoped.
     */
    public function documents(): DocumentResource
    {
        if ($this->documents === null) {
            $this->documents = new DocumentResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->documents;
    }

    /**
     * Signers: the address book of people who can be asked to sign.
     *
     * Signers are workspace-level and reusable across documents; create one once and
     * reference its id in every assignment. Account-scoped.
     */
    public function signers(): SignerResource
    {
        if ($this->signers === null) {
            $this->signers = new SignerResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->signers;
    }

    /**
     * Assignments: request signatures on a document and manage those requests.
     *
     * Create binds signers to a document and notifies them; the rest of the resource
     * estimates cost, resends notifications, and extends deadlines.
     */
    public function assignments(): AssignmentResource
    {
        if ($this->assignments === null) {
            $this->assignments = new AssignmentResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->assignments;
    }

    /**
     * Templates: reusable documents with named roles bound to signers at creation time.
     *
     * Note that a template created through the API receives only an `Editor` role;
     * signing roles are configured in the web app. Account-scoped.
     */
    public function templates(): TemplateResource
    {
        if ($this->templates === null) {
            $this->templates = new TemplateResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->templates;
    }

    /**
     * Tags: workspace labels that can be attached to documents for filtering.
     *
     * Attach and detach them through {@see DocumentResource::appendTags()} and friends.
     * Account-scoped.
     */
    public function tags(): TagResource
    {
        if ($this->tags === null) {
            $this->tags = new TagResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->tags;
    }

    /**
     * Fields: custom and standard data captured from signers during signing.
     *
     * Covers field definitions, their types, and server-side value validation.
     * Account-scoped.
     */
    public function fields(): FieldResource
    {
        if ($this->fields === null) {
            $this->fields = new FieldResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->fields;
    }

    /**
     * Webhooks: the workspace's single event subscription and its delivery history.
     *
     * Deliveries are unsigned — see {@see self::webhookEvents()} for how to handle that.
     * Account-scoped.
     */
    public function webhooks(): WebhookResource
    {
        if ($this->webhooks === null) {
            $this->webhooks = new WebhookResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->webhooks;
    }

    /**
     * Authentication: login, social login, API-key management, and password flows.
     *
     * Mostly used on a client built with {@see self::forAuth()}, before any workspace
     * credential exists.
     */
    public function auth(): AuthResource
    {
        if ($this->auth === null) {
            $this->auth = new AuthResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->auth;
    }

    /**
     * Marketplace OAuth: the authorization-code + PKCE flow for acting on **another**
     * workspace, plus token exchange, refresh, revocation, userinfo and discovery.
     *
     * Automating your own workspace needs none of this — keep using an API key.
     * Usually called on a {@see self::forAuth()} client, since the flow runs before any
     * workspace credential exists:
     *
     * ```php
     * $oauth = AssinafyClient::forAuth()->oauth($clientId, $clientSecret);
     * ```
     *
     * Unlike the other accessors this one is not memoized: the resource is a small
     * stateless object and the credentials are arguments, so caching it would only
     * create a stale-secret footgun.
     *
     * @param string      $clientId     the application's `client_id` from the Assinafy app
     * @param string|null $clientSecret confidential applications only; public applications
     *     authenticate with PKCE and are never issued a secret
     * @throws \Assinafy\SDK\Exceptions\ValidationException on an empty client ID or a
     *     present-but-blank secret
     */
    public function oauth(
        string $clientId,
        #[\SensitiveParameter] ?string $clientSecret = null
    ): OAuthResource {
        return new OAuthResource(
            $this->httpClient,
            $this->config,
            $this->loggerProxy,
            $clientId,
            $clientSecret
        );
    }

    /**
     * The signer's own session: everything a recipient does with their access code.
     *
     * Accept terms, verify a one-time code, confirm data, upload a signature image,
     * then sign or decline. Authenticated by the signer access code, not the API key.
     */
    public function signerSession(): SignerSessionResource
    {
        if ($this->signerSession === null) {
            $this->signerSession = new SignerSessionResource($this->httpClient, $this->config, $this->loggerProxy);
        }

        return $this->signerSession;
    }

    /**
     * A signer's view of the documents assigned to them.
     *
     * Read-only listing, search, and download, plus bulk sign/decline. Authenticated by
     * the signer access code, not the API key.
     */
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
     * The webhook contract provides no signing secret or signature header. Secure the
     * endpoint as described by {@see WebhookEventParser}.
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
     *   - an associative array `{ id? or full_name (or name), email?, whatsapp_phone_number?
     *     (or phone)?, verification_method?, notification_methods?, step? }`
     *
     * Signers without an `id` are created via the API; signers found by email (when an email
     * is supplied) are reused. DigitalCertificate entries must instead supply an existing
     * signer ID whose government_id was set first. Returns the created document, the assignment,
     * and the resolved signer IDs.
     *
     * SDK input (the helper composes multipart and JSON requests):
     * ```php
     * [
     *     'filePath' => '/absolute/path/agreement.pdf',
     *     'signers' => [
     *         [
     *             'full_name' => 'Example Signer',
     *             'email' => 'person@example.com',
     *             'verification_method' => 'Email',
     *             'notification_methods' => ['Email'],
     *             'step' => 1,
     *         ],
     *     ],
     *     'message' => null,
     *     'expiresAt' => null,
     *     'waitForReady' => true,
     * ]
     * ```
     *
     * Full return example:
     * ```php
     * [
     *     'document' => [
     *         'resource' => 'document',
     *         'id' => 'document-id',
     *         'account_id' => 'account-id',
     *         'template_id' => null,
     *         'name' => 'agreement.pdf',
     *         'status' => 'metadata_ready',
     *         'artifacts' => [
     *             'original' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/download/original',
     *             'thumbnail' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/thumbnail',
     *         ],
     *         'is_closed' => false,
     *         'signing_url' => 'https://app-sandbox.assinafy.com.br/sign/signing-token',
     *         'decline_reason' => null,
     *         'declined_by' => null,
     *         'tags' => [],
     *         'created_at' => '2026-09-01T12:00:00Z',
     *         'updated_at' => '2026-09-01T12:00:00Z',
     *         'assignment' => null,
     *         'pages' => [
     *             [
     *                 'id' => 'page-id',
     *                 'number' => 1,
     *                 'height' => 1651,
     *                 'width' => 1275,
     *                 'download_url' => 'https://sandbox.assinafy.com.br/v1/documents/document-id/pages/page-id/download',
     *             ],
     *         ],
     *     ],
     *     'assignment' => [
     *         'resource' => 'assignment',
     *         'id' => 'assignment-id',
     *         'sender_email' => 'person@example.com',
     *         'method' => 'virtual',
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
     *                 'page' => null,
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
     *                     'name' => 'Virtual',
     *                     'type' => 'virtual',
     *                     'regex' => null,
     *                     'is_pre_defined' => true,
     *                     'is_active' => true,
     *                     'is_required' => false,
     *                     'is_standard' => false,
     *                     'is_read_only' => false,
     *                     'is_visible' => true,
     *                 ],
     *                 'display_settings' => [],
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
     *     'signer_ids' => ['signer-id'],
     * ]
     * ```
     *
     * The returned document is the preparation snapshot, before assignment creation.
     * Fetch get() for its current signing state. A later failure does not roll back objects
     * created by earlier steps; use separate calls when each ID must be saved for recovery.
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

        if (!is_string($documentId) || trim($documentId) === '') {
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

        if (isset($signer['id']) && is_string($signer['id']) && trim($signer['id']) !== '') {
            $signerId = $signer['id'];
        } else {
            $fullName = (string) ($signer['full_name'] ?? $signer['name'] ?? '');
            $email = $signer['email'] ?? null;
            $phone = $signer['whatsapp_phone_number'] ?? $signer['phone'] ?? null;

            $signerId = null;
            if ($email !== null) {
                $existing = $this->signers()->findByEmail((string) $email);
                if (
                    $existing !== null
                    && isset($existing['id'])
                    && is_string($existing['id'])
                    && trim($existing['id']) !== ''
                ) {
                    $signerId = $existing['id'];
                }
            }

            if ($signerId === null) {
                $created = $this->signers()->create(
                    $fullName,
                    $email !== null ? (string) $email : null,
                    $phone !== null ? (string) $phone : null
                );

                if (!isset($created['id']) || !is_string($created['id']) || trim($created['id']) === '') {
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
        $stepCounts = [];
        $digitalCertificateSteps = [];
        foreach ($signers as $signer) {
            $reference = null;
            $step = 1;
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
                    $step = (int) $signer['step'];
                }
                if ($contactRequirements['digital_certificate']) {
                    $digitalCertificateSteps[] = $step;
                }

                if (!is_string($id) || trim($id) === '') {
                    if ($contactRequirements['digital_certificate']) {
                        throw new \InvalidArgumentException(
                            'Digital-certificate workflows require an existing signer ID; '
                            . 'set government_id with signers()->update() first'
                        );
                    }
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
            $stepCounts[$step] = ($stepCounts[$step] ?? 0) + 1;
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
        foreach ($digitalCertificateSteps as $step) {
            if ($stepCounts[$step] > 1) {
                throw new \InvalidArgumentException(
                    'A digital-certificate signer must be alone in its signing step'
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $signer
     * @return array{email: bool, phone: bool, digital_certificate: bool}
     */
    private function validateWorkflowSignerOptions(#[\SensitiveParameter] array $signer): array
    {
        $verification = $signer['verification_method'] ?? null;
        $notifications = $signer['notification_methods'] ?? null;

        if (
            $verification !== null
            && !in_array(
                $verification,
                AssignmentResource::VERIFICATION_METHODS,
                true
            )
        ) {
            throw new \InvalidArgumentException('Unknown signer verification method');
        }

        if ($notifications !== null) {
            if (!is_array($notifications)) {
                throw new \InvalidArgumentException('Signer notification methods must be an array');
            }
            if (count($notifications) > 1) {
                throw new \InvalidArgumentException('Only one signer notification method is allowed');
            }
            foreach ($notifications as $notification) {
                if (!in_array($notification, AssignmentResource::NOTIFICATION_METHODS, true)) {
                    throw new \InvalidArgumentException('Unknown signer notification method');
                }
            }
        }

        $notificationChannels = $notifications === null ? null : array_values($notifications);
        if (
            $verification !== null
            && $notificationChannels !== null
            && $notificationChannels !== []
            && $verification !== AssignmentResource::VERIFICATION_DIGITAL_CERTIFICATE
            && $verification !== $notificationChannels[0]
        ) {
            throw new \InvalidArgumentException(
                'Signer verification and notification methods must match'
            );
        }

        $verification ??= $notificationChannels[0] ?? AssignmentResource::VERIFICATION_EMAIL;
        $notificationChannels ??= [
            $verification === AssignmentResource::VERIFICATION_WHATSAPP
                ? AssignmentResource::NOTIFICATION_WHATSAPP
                : AssignmentResource::NOTIFICATION_EMAIL,
        ];

        if (array_key_exists('step', $signer) && (!is_int($signer['step']) || $signer['step'] < 1)) {
            throw new \InvalidArgumentException('Signer step must be a positive integer');
        }

        return [
            'email' => $verification === AssignmentResource::VERIFICATION_EMAIL
                || in_array(AssignmentResource::NOTIFICATION_EMAIL, $notificationChannels, true),
            'phone' => $verification === AssignmentResource::VERIFICATION_WHATSAPP
                || in_array(AssignmentResource::NOTIFICATION_WHATSAPP, $notificationChannels, true),
            'digital_certificate' => $verification
                === AssignmentResource::VERIFICATION_DIGITAL_CERTIFICATE,
        ];
    }

    private function validateExpiration(string $expiresAt): void
    {
        $reason = Iso8601::reasonInvalid($expiresAt);
        if ($reason !== null) {
            throw new \InvalidArgumentException('Expiration ' . $reason);
        }
    }

    /** The immutable configuration this client was built with. */
    public function getConfig(): Configuration
    {
        return $this->config;
    }

    /** The transport in use — the injected client, or the SDK's Guzzle default. */
    public function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient;
    }

    /** The PSR-3 logger currently receiving request and error diagnostics. */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Swap the logger after construction.
     *
     * Takes effect immediately on resources that were already created: they hold a shared
     * proxy rather than the logger itself.
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        $this->loggerProxy->setLogger($logger);

        return $this;
    }
}
