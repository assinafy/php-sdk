# Assinafy PHP SDK API reference

This reference maps the public resource methods in this SDK to the Assinafy API contract. It is based on the official OpenAPI document fetched on **2026-08-05** from:

- <https://api.assinafy.com.br/v1/docs>
- <https://api.assinafy.com.br/v1/docs/openapi.json>

It describes repository release `v2.0.0`. Packagist does not currently expose
`assinafy/php-sdk`. See [INSTALLATION.md](INSTALLATION.md) for tagged VCS/path installation.

The published document is OpenAPI 3.0.0, API version 1.0.0, and contains 89 operations on 68 paths; SDK coverage is 89/89. Its SHA-256 at the time of the audit was `7e5957082002e8e96c5abc2cadf7b4b463eaa5bd61b76e26f64b90a8b922088c`. Production and sandbox use the same versioned paths:

```text
https://api.assinafy.com.br/v1
https://sandbox.assinafy.com.br/v1
```

This file distinguishes the published contract from behavior verified against the running
sandbox. That distinction matters: some useful template operations are live but absent from
OpenAPI, the published `send-token` body and `/users/self` payload are currently stale, and the
two published statistics routes were not deployed in the sandbox during the 2026-08-05 audit.

## Conventions

### Authentication

| Name used below | Published request authentication |
|---|---|
| Workspace | Either `X-Api-Key: {api-key}` or `Authorization: Bearer {access-token}`. The API key is recommended for server integrations. |
| Signer | The OpenAPI security scheme is a query parameter named `signer-access-code`. The generated per-operation Markdown instead calls it `access_code`; see [Known contract divergences](#known-contract-divergences). The code is delivered to the assigned signer's inbox and is not exposed by assignment `signing_urls`. |
| Public | No authentication. |

The introductory documentation also says a user access token may be sent as `?access-token=...`, although that form is not represented by an OpenAPI security scheme or by operation parameters. The SDK uses the two formal workspace schemes: ordinary clients configure `X-Api-Key`, while `Configuration::forBearer()` / `AssinafyClient::forBearer()` configure `Authorization: Bearer ...` globally for every workspace resource. Bootstrap-capable methods also accept a nullable per-call Bearer token; `null` falls back to the client's configured authentication.

### Content types

- JSON requests use `Content-Type: application/json`.
- Document and logo uploads use `multipart/form-data` with a part named `file`.
- Signature uploads use a raw `image/png` body in the published contract.
- Download operations return raw PDF or image bytes, not a JSON envelope.

### JSON envelope and SDK return values

Ordinary JSON responses use:

```json
{
  "status": 200,
  "message": "",
  "data": {}
}
```

The SDK intentionally unwraps `data` for most single-resource methods. Tables below say **unwrapped** when the method returns only `data`, and **envelope** when it returns `status`, `message`, and `data`. Methods returning an endpoint whose success envelope has no `data` retain the available `{status, message}` object.

Every non-2xx response from the default HTTP client throws `Assinafy\SDK\Exceptions\ApiException`. Network failures throw `NetworkException`; local argument checks may throw `ValidationException`, `InvalidArgumentException`, or `RuntimeException` before a request is made.

### Pagination

Published paginated operations accept `page` (minimum 1) and `per-page` (maximum 100) and return metadata only in these response headers:

- `X-Pagination-Current-Page`
- `X-Pagination-Total-Count`
- `X-Pagination-Page-Count`
- `X-Pagination-Per-Page`

There is no documented `meta` object in the response body. Every paginated SDK list method lifts all four headers into a normalized `pagination` key alongside the original envelope.

### Status and error notation

All published operations return either `200`, except the browser OAuth start operation, which returns `302`. The compact status lists below put the success code first. For example, `200; 400, 401, 404, 500` means success is `200` and the documented errors are `400`, `401`, `404`, and `500`.

The introduction additionally lists `403`, `415`, and `429` as possible global errors, but no individual operation declares them. In particular, callers should still handle `429` and respect `Retry-After` if present.

## Known contract divergences

| Area | Published contract | Verified/runtime or SDK behavior |
|---|---|---|
| Document tags versus templates | Four document-tag operations are published, but the tag-body property description calls the strings tag IDs. Template management publishes list, while document creation from a template is a separate published document operation. | The sandbox and SDK use tag **names** for replace/append and create missing names automatically. `listTags()`, `replaceTags()`, `appendTags()`, and `detachTag()` still map directly to published operations; do not group them with the five live template-management routes absent from OpenAPI. |
| Authenticated-user payload | `GET /users/self` declares `data: AuthUser`. | The sandbox returns `data: {user: AuthUser, accounts: AuthAccount[]}`. `UserResource::get()` accepts both shapes and returns the nested `user` for the sandbox shape, preserving its `AuthUser` contract. Use `AccountResource::list()` for account discovery. |
| Statistics deployment | `GET /accounts/{accountId}/stats` and `GET /users/self/stats` are published with `DocumentStatsRow[]` success schemas. | On 2026-08-05, both sandbox requests returned an application-level `404` route-not-deployed response. Their SDK methods remain because the operations are published and are part of 89/89 coverage, but current sandbox availability is not claimed. |
| Public send-token body and recipient | `PUT /public/documents/{documentId}/send-token` documents `{ "email": "..." }`. | The sandbox requires `{ "recipient": "...", "channel": "email" }`, which the SDK sends. The recipient must identify a signer already assigned to the target document; a syntactically valid but unassigned address is rejected. |
| Assignment account context | `GET /assignments` documents only `page` and `per-page`. | The sandbox requires an additional camelCase `accountId` query parameter. The SDK supplies it from `Configuration`. |
| Signer access-code name | OpenAPI declares query parameter `signer-access-code`; generated endpoint Markdown says `access_code`. | The SDK consistently follows the OpenAPI name and sends `signer-access-code` in the query. Test a real signing flow before changing the name. |
| Signer access-code acquisition | Assignment responses include `signing_urls: [{signer_id, url}]`; no response schema exposes an access-code field. | The one-time code is delivered to the assigned signer's inbox through `send-token`. A sandbox attempt to treat a signing-URL path segment as the code returned `401`; do not derive or scrape it from the URL. Authenticated signer-read live tests require an explicitly supplied signer ID and code. |
| Templates | OpenAPI documents only template listing and document creation from a template. | Five management operations in `TemplateResource` are absent from OpenAPI but have been exercised by the integration suite: create, get, update, delete, and page download. They are retained and marked **undocumented** below. |
| OAuth start | The current contract documents a `302` browser redirect. | A read-only sandbox check on 2026-08-05 returned `302` to Google. Older README notes claiming a 404 are stale. |
| Generated error examples | Operation headings distinguish 400/401/500. | The generated Markdown often renders the same `{status: 400, message: "Bad request."}` example under 401 and 500. Treat the HTTP status as authoritative. |
| Validation error component | Operations attach the component at HTTP 400. | The reusable component's example body says `status: 422`. The published contract is internally inconsistent. |
| Quick Start assignment body | The Quick Start prose uses `signerIds: ["..."]`. | The operation schema and named examples use `signers: [{"id":"..."}]`, which is what the SDK sends. |
| Signer document download | The operation is marked public with no security scheme. | The SDK requires and sends a signer access code. Preserve this restriction until a real download is tested without it. |

## Core, transport, and support API catalog

The endpoint tables below cover every public method declared by the concrete resource classes.
This section catalogs the remaining SDK-declared public surface. Every resource also inherits
`AbstractResource::__construct(HttpClientInterface $httpClient, Configuration $config,
?LoggerInterface $logger = null)` for dependency injection; applications normally obtain
resources through `AssinafyClient` instead.

### `AssinafyClient`

| Public method | Purpose / return |
|---|---|
| `__construct(Configuration $config, ?HttpClientInterface $httpClient = null, ?LoggerInterface $logger = null)` | Builds a client; omitted transport/logger become `GuzzleHttpClient` and `NullLogger`. |
| `create(string $apiKey, string $accountId, string $baseUrl = Configuration::DEFAULT_BASE_URL): self` | API-key convenience factory. |
| `fromArray(array $config): self` | Accepts the same keys documented for `Configuration::fromArray()`. |
| `forAuth(string $baseUrl = Configuration::DEFAULT_BASE_URL): self` | Public/bootstrap client that sends no workspace credential. |
| `forBearer(string $accessToken, string $accountId, string $baseUrl = Configuration::DEFAULT_BASE_URL): self` | Globally Bearer-authenticated workspace client. |
| `accounts(): AccountResource` | Lazy, cached account resource. |
| `documents(): DocumentResource` | Lazy, cached document resource. |
| `signers(): SignerResource` | Lazy, cached signer resource. |
| `assignments(): AssignmentResource` | Lazy, cached assignment resource. |
| `templates(): TemplateResource` | Lazy, cached template resource. |
| `tags(): TagResource` | Lazy, cached workspace-tag resource. |
| `fields(): FieldResource` | Lazy, cached field resource. |
| `webhooks(): WebhookResource` | Lazy, cached webhook-management resource. |
| `auth(): AuthResource` | Lazy, cached authentication resource. |
| `signerSession(): SignerSessionResource` | Lazy, cached signer-session resource. |
| `signerDocuments(): SignerDocumentResource` | Lazy, cached signer-document resource. |
| `users(): UserResource` | Lazy, cached authenticated-user resource. |
| `webhookEvents(): WebhookEventParser` | Lazy, cached inbound webhook parser. |
| `uploadAndRequestSignatures(string $filePath, array $signers, ?string $message = null, ?string $expiresAt = null, bool $waitForReady = true): array` | Composite upload → optional readiness wait → signer resolution/creation → virtual assignment. Returns `{document, assignment, signer_ids}`; completed remote steps are not rolled back if a later step fails. |
| `getConfig(): Configuration` | Returns the immutable-by-interface client configuration. |
| `getHttpClient(): HttpClientInterface` | Returns the injected/default transport. |
| `getLogger(): LoggerInterface` | Returns the current application logger. |
| `setLogger(LoggerInterface $logger): self` | Replaces the logger and propagates it to resources already created and the default transport proxy. |

### `Configuration`

Public constants are `SDK_VERSION`, `DEFAULT_BASE_URL`, and `SANDBOX_BASE_URL`.

| Public method | Purpose / return |
|---|---|
| `__construct(string $apiKey, string $accountId, string $baseUrl = self::DEFAULT_BASE_URL, int $timeout = 30, int $connectTimeout = 10, ?string $accessToken = null)` | Validates credentials, account/base URL, and positive timeouts. Supply an API key or an access token, not both. Remote URLs require HTTPS; HTTP is loopback-only (`localhost`/`*.localhost`, `127.0.0.1`, `::1`). Credentials, query strings, and fragments are forbidden in the base URL. |
| `fromArray(array $config): self` | Keys: `api_key`/`apiKey`, `account_id`/`accountId`, `access_token`/`accessToken`, `base_url`/`baseUrl`, `timeout`, `connect_timeout`/`connectTimeout`; legacy `webhook_secret` is ignored. |
| `forPublic(string $baseUrl = self::DEFAULT_BASE_URL): self` | Creates a no-credential public configuration. |
| `forBearer(string $accessToken, string $accountId, string $baseUrl = self::DEFAULT_BASE_URL, int $timeout = 30, int $connectTimeout = 10): self` | Creates a global Bearer configuration. |
| `isPublic(): bool` | Whether this is the public sentinel configuration. |
| `isBearerAuthenticated(): bool` | Whether a global access token is configured. |
| `getBaseUrl(): string` | Validated base URL normalized without a trailing slash. |
| `getApiKey(): string` | Configured API key; empty for Bearer clients. |
| `getAccessToken(): ?string` | Configured global Bearer token, if any. |
| `getAccountId(): string` | Configured workspace ID. |
| `getTimeout(): int` | Total request timeout in seconds. |
| `getConnectTimeout(): int` | Connection timeout in seconds. |
| `getHeaders(): array` | Default `Accept`/`User-Agent` plus exactly one configured workspace credential; public clients receive neither credential header. |

### Transport

`HttpClientInterface` is the SDK's injectable transport contract; it is not PSR-18. The shipped
`GuzzleHttpClient` implements every method below with the same signature. For `post()`, `put()`,
and `patch()`, `null` omits the request body while an explicit array, including `[]`, sends JSON.

| Public method | Behavior / return |
|---|---|
| `get(string $uri, array $params = [], array $headers = []): Response` | GET with query parameters and per-request headers. |
| `post(string $uri, ?array $data = null, array $headers = [], array $query = []): Response` | POST with optional JSON body and query. |
| `put(string $uri, ?array $data = null, array $headers = [], array $query = []): Response` | PUT with optional JSON body and query. |
| `patch(string $uri, ?array $data = null, array $headers = [], array $query = []): Response` | PATCH with optional JSON body and query. |
| `delete(string $uri, array $headers = [], array $query = [], array $data = []): Response` | DELETE with optional query and JSON body. An empty `$data` omits the body. |
| `uploadFile(string $uri, string $filePath, array $data = [], array $headers = []): Response` | Multipart POST with binary `file` plus optional form parts. |
| `postRaw(string $uri, string $body, string $contentType, array $query = [], array $headers = []): Response` | POST raw bytes with the supplied media type. |

`GuzzleHttpClient::__construct(Configuration $config, ?LoggerInterface $logger = null,
?GuzzleHttp\ClientInterface $client = null)` builds the production Guzzle client or accepts an
injected one. It disables redirects, applies configured timeouts/headers, redacts diagnostics,
wraps network failures in `NetworkException`, and converts non-2xx responses to `ApiException`.

### Response and redaction helpers

| Class / public method | Behavior / return |
|---|---|
| `Response::__construct(int $statusCode, array $headers, string $body)` | Captures transport output and parses JSON objects/arrays once. |
| `Response::getStatusCode(): int` | HTTP status. |
| `Response::getHeaders(): array` | Response headers in their captured shape. |
| `Response::getBody(): string` | Raw body bytes/text. |
| `Response::getData(): ?array` | Parsed JSON array/object, or `null` for empty, invalid, scalar, or binary content. |
| `Response::isSuccess(): bool` | Status in 200–299. |
| `Response::isClientError(): bool` | Status in 400–499. |
| `Response::isServerError(): bool` | Status 500 or greater. |
| `LogRedactor::redact(array $data): array` | Recursively masks recognized credential keys and credential-bearing text. |
| `LogRedactor::redactRequestOptions(array $options): array` | Redacts Guzzle options, raw bodies, streams, and multipart contents. |
| `LogRedactor::summarizeRequestOptions(array $options): array` | Returns payload-free query/header/body metadata for diagnostics. |
| `LogRedactor::redactBody(string $body): string` | Redacts a JSON body or replaces non-JSON with a byte-count placeholder. |
| `LogRedactor::redactText(string $text): string` | Masks credentials in URLs, headers, and exception-style text. |

`LogRedactor::PLACEHOLDER` is the public replacement string `[redacted]`.

### Webhook, logger, and exception support

| Class / public method | Behavior / return |
|---|---|
| `WebhookEventParser::extractEvent(string $payload): ?array` | Decodes a JSON object/array-shaped webhook body or returns `null`. |
| `WebhookEventParser::getEventType(?array $event): ?string` | Returns `event`. |
| `WebhookEventParser::getEventData(?array $event): array` | Returns the polymorphic `object` entity. |
| `WebhookEventParser::getEventPayload(?array $event): array` | Returns event-specific `payload`. |
| `WebhookEventParser::getAccountId(?array $event): ?string` | Returns `account_id`. |
| `MutableLogger::__construct(LoggerInterface $logger)` | Creates the internal logger proxy shared by existing resources and transport. |
| `MutableLogger::setLogger(LoggerInterface $logger): void` | Replaces the proxy target. |
| `MutableLogger::getLogger(): LoggerInterface` | Returns the proxy target. |
| `MutableLogger::log($level, $message, array $context = []): void` | Delegates PSR-3 logging; inherited `emergency()` through `debug()` convenience methods call it. |
| `AssinafyException::__construct(string $message = '', int $code = 0, ?Throwable $previous = null, array $context = [])` | Base SDK exception with diagnostic context. |
| `AssinafyException::getContext(): array` | Returns context. |
| `AssinafyException::setContext(array $context): self` | Replaces context and returns the exception. |
| `ApiException::__construct(string $message, int $statusCode, ?array $responseData = null, ?Throwable $previous = null, array $responseHeaders = [])` | Represents a non-success HTTP response. |
| `ApiException::getStatusCode(): int` | HTTP status. |
| `ApiException::getResponseData(): ?array` | Parsed error payload. |
| `ApiException::getResponseHeaders(): array` | Normalized response headers. |
| `ApiException::getResponseHeaderLine(string $name): string` | Case-insensitive comma-joined header lookup. |
| `ApiException::fromResponse(int $statusCode, array $responseData, ?Throwable $previous = null, array $responseHeaders = []): self` | Factory using `message`, then `error`, then a safe fallback. |
| `ValidationException::__construct(string $message = 'Validation failed', array $errors = [], int $code = 422)` | Local structured validation failure. |
| `ValidationException::getErrors(): array` | Structured input errors. |
| `ValidationException::fromArray(array $errors): self` | Factory with the default message/code. |

`NetworkException` adds no methods; it inherits `AssinafyException`. Standard methods inherited
from PHP's `Exception` and PSR-3's `AbstractLogger` retain their upstream contracts and are not
redeclared by this SDK.

## Accounts (`AccountResource`)

Workspace-authenticated operations accept either API-key or Bearer authentication in the published contract. A globally Bearer-authenticated client needs no per-call token; a public bootstrap client must pass one to `list()` or `create()`.

| SDK method | Official operation | Auth | Request | SDK success return | Statuses |
|---|---|---|---|---|---|
| `list(?string $accessToken = null)` | [`GET /v1/accounts`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts) | Workspace | No parameters; optional Bearer token lets a bootstrap client discover accounts. `null` uses configured authentication. | Envelope with `data: Account[]`; this endpoint is not declared paginated. | `200; 401, 500` |
| `get()` | [`GET /v1/accounts/{accountId}`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D) | Workspace | Configured `accountId` path parameter. | Unwrapped `Account`. | `200; 401, 404, 500` |
| `create(string $name, ?string $notificationSenderType = null, ?string $accessToken = null)` | [`POST /v1/accounts`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Faccounts) | Workspace | Required JSON `name`; optional `notification_sender_type: "User" \| "Account"`; optional Bearer token. `null` uses configured authentication. | Unwrapped `Account`. | `200; 400, 401, 500` |
| `update($name, $notificationSenderType)` | [`PUT /v1/accounts/{accountId}`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Faccounts%2F%7BaccountId%7D) | Workspace | Required JSON object containing either optional `name` and/or `notification_sender_type`. The SDK refuses an empty update. | Unwrapped `Account`. | `200; 400, 401, 500` |
| `delete($force)` | [`DELETE /v1/accounts/{accountId}`](https://api.assinafy.com.br/v1/docs/markdown?method=delete&path=%2Fv1%2Faccounts%2F%7BaccountId%7D) | Workspace | Optional JSON body `{force: boolean}`. This is a body field, not a query parameter. | Envelope with `data: []`. A 400 restriction response may include `restrictions[]` with `code`, `message`, and `account_ids[]`. | `200; 400, 401, 404, 500` |
| `theme()` | [`GET /v1/accounts/{accountId}/theme`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ftheme) | Workspace | Configured account path only. | Unwrapped `AccountTheme`. | `200; 401, 500` |
| `downloadLogo()` | [`GET /v1/accounts/{accountId}/logo`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Flogo) | Workspace | No body. | Raw image bytes (`image/*`). | `200; 401, 404, 500` |
| `uploadLogo($filePath)` | [`POST /v1/accounts/{accountId}/logo`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Flogo) | Workspace | Required multipart `file` binary part. | Success envelope fields (`status`, `message`). | `200; 400, 401, 500` |
| `deleteLogo()` | [`DELETE /v1/accounts/{accountId}/logo`](https://api.assinafy.com.br/v1/docs/markdown?method=delete&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Flogo) | Workspace | No body. | Success envelope fields. | `200; 401, 500` |
| `stats($granularity, $month)` | [`GET /v1/accounts/{accountId}/stats`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fstats) | Workspace | Optional `granularity: monthly\|daily`; `month: YYYY-MM` is required by the SDK for daily. Published, but the sandbox route was not deployed on 2026-08-05. | Unwrapped `DocumentStatsRow[]` when deployed. | Published: `200; 400, 401, 500`. Audited sandbox: application-level `404` route-not-deployed. |

The published contract says `stats()` returns the last 12 months in monthly mode or every
zero-filled day of the selected month in daily mode. This describes the contract, not a successful
call against the audited sandbox deployment.

## Assignments (`AssignmentResource`)

The assignment request uses `signers`, not the stale Quick Start field `signerIds`.

### Assignment creation body

```text
method*                 "virtual" | "collect"
signers*[]
  id*                   signer ID
  verification_method   "Email" | "Whatsapp"
  notification_methods  empty array or Email and/or Whatsapp
  step                  positive sequential-signing step
entries[]               required in practice for collect
  page_id
  fields[]
    signer_id
    field_id
    display_settings    rendering object (left, top, font settings, colors, ...)
message                 invitation text
expires_at              ISO-8601 date-time
copy_receivers[]        signer IDs
```

When `step` is supplied, every signer must have one and steps must be contiguous from 1.
`verification_method` is `Email` or `Whatsapp`; `notification_methods` may independently be
empty or contain Email, Whatsapp, or both. Omitting both defaults both fields to Email.

| SDK method | Official operation | Auth | Request | SDK success return | Statuses |
|---|---|---|---|---|---|
| `create($documentId, $signers, $method, $options)` | [`POST /v1/documents/{documentId}/assignments`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D%2Fassignments) | Workspace | Required JSON described above. String signer IDs are normalized to `{id}`. | Unwrapped `Assignment`. | `200; 400, 401, 500` |
| `list($page, $perPage, $filters)` | [`GET /v1/assignments`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fassignments) | Workspace | Published: `page`, `per-page`. Runtime-required: `accountId`. | Envelope with `data: Assignment[]` and normalized `pagination`. | `200; 401, 500` |
| `estimateCost($documentId, $signers, $method, $options)` | [`POST /v1/documents/{documentId}/assignments/estimate-cost`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D%2Fassignments%2Festimate-cost) | Workspace | JSON `method`, `signers[]` (`verification_method`, `notification_methods`; IDs not required), and `entries[]` for collect. | Unwrapped `CostEstimate`. | `200; 400, 401, 500` |
| `resend($documentId, $assignmentId, $signerId)` | [`PUT /v1/documents/{documentId}/assignments/{assignmentId}/signers/{signerId}/resend`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D%2Fassignments%2F%7BassignmentId%7D%2Fsigners%2F%7BsignerId%7D%2Fresend) | Workspace | Path parameters only. | Unwrapped `{is_sent, document_id, signer_id}`. | `200; 401, 500` |
| `estimateResendCost($documentId, $assignmentId, $signerId)` | [`POST /v1/documents/{documentId}/assignments/{assignmentId}/signers/{signerId}/estimate-resend-cost`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D%2Fassignments%2F%7BassignmentId%7D%2Fsigners%2F%7BsignerId%7D%2Festimate-resend-cost) | Workspace | Path parameters only. | Unwrapped `CostEstimate`. | `200; 401, 500` |
| `resetExpiration($documentId, $assignmentId, $expiresAt)` | [`PUT /v1/documents/{documentId}/assignments/{assignmentId}/reset-expiration`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D%2Fassignments%2F%7BassignmentId%7D%2Freset-expiration) | Workspace | Required JSON body with `expires_at` ISO-8601 date-time. The property is not marked required in OpenAPI, although the endpoint's purpose implies it. | Unwrapped `Assignment`. | `200; 400, 401, 404, 500` |
| `whatsappNotifications($documentId, $assignmentId)` | [`GET /v1/documents/{documentId}/assignments/{assignmentId}/whatsapp-notifications`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D%2Fassignments%2F%7BassignmentId%7D%2Fwhatsapp-notifications) | Workspace | Path parameters only. | Unwrapped `WhatsappNotification[]`. Sandbox messages are simulated and may expose test signing codes in button URLs. | `200; 401, 500` |

## Authentication (`AuthResource`)

Use `AssinafyClient::forAuth()` for public bootstrap operations and pass the login token explicitly when a protected bootstrap method needs it. Use `AssinafyClient::forBearer()` after an account ID is known to apply the token globally. Nullable token arguments fall back to configured API-key or global-Bearer authentication; they throw locally when both the argument and a public client's authentication are absent.

| SDK method | Official operation | Auth | Request | SDK success return | Statuses |
|---|---|---|---|---|---|
| `login($email, $password)` | [`POST /v1/login`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Flogin) | Public | Required JSON `{email, password}`. | Unwrapped `AuthSession`. | `200; 400, 500` |
| `socialLogin($provider, $token, $hasAcceptedTerms)` | [`POST /v1/authentication/social-login`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Fauthentication%2Fsocial-login) | Public | Required JSON `{provider: "google", token, has_accepted_terms}`. | Unwrapped `AuthSession`. | `200; 400, 500` |
| `linkSocialLogin($provider, $token, $accessToken)` | [`POST /v1/auth/link-social-login`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Fauth%2Flink-social-login) | Workspace | Required JSON `{provider: "google", token}`; optional Bearer token, otherwise configured API key. | Success envelope fields. | `200; 400, 401, 500` |
| `socialLoginUrl($provider)` | [`GET /v1/auth/authenticate`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fauth%2Fauthenticate) | Public | Builds `?authclient={provider}`. It does not issue the browser redirect through the HTTP client. | Absolute URL string; navigating to it produces the documented `302`. | API: `302; 500` |
| `socialLoginCallbackUrl()` | [`GET /v1/login-callback`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Flogin-callback) | Public | No parameters. It builds rather than requests the callback URL. | Absolute URL string. | API: `200; 500` |
| `generateApiKey(?string $accessToken, string $password)` | [`POST /v1/users/api-keys`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Fusers%2Fapi-keys) | Workspace | Required JSON `{password}`. A non-null token overrides configured auth; `null` uses it. The nullable token has no default because it precedes required `$password`. | Unwrapped `ApiKey`. The full key is shown only when generated. | `200; 401, 500` |
| `getApiKey(?string $accessToken = null)` | [`GET /v1/users/api-keys`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fusers%2Fapi-keys) | Workspace | No body. A non-null token overrides configured auth; `null` uses it. | Unwrapped `ApiKey`; `api_key` may be null and is otherwise masked. | `200; 401, 500` |
| `deleteApiKey(?string $accessToken = null)` | [`DELETE /v1/users/api-keys`](https://api.assinafy.com.br/v1/docs/markdown?method=delete&path=%2Fv1%2Fusers%2Fapi-keys) | Workspace | No body. A non-null token overrides configured auth; `null` uses it. | Envelope with `data: []`. | `200; 401, 500` |
| `changePassword(?string $accessToken, string $email, string $password, string $newPassword)` | [`PUT /v1/authentication/change-password`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Fauthentication%2Fchange-password) | Workspace | Required JSON `{email, password, new_password}`. A non-null token overrides configured auth; `null` uses it. | Unwrapped `{email}`. | `200; 400, 401, 500` |
| `requestPasswordReset($email)` | [`PUT /v1/authentication/request-password-reset`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Fauthentication%2Frequest-password-reset) | Public | Required JSON `{email}`. | Unwrapped `{email}`. | `200; 500` |
| `resetPassword($email, $token, $newPassword)` | [`PUT /v1/authentication/reset-password`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Fauthentication%2Freset-password) | Public | Required body; schema requires `email` and `new_password`, while `token` is documented but not marked required. The SDK requires all three. | Unwrapped `{email}`. | `200; 400, 500` |

The two browser-facing GET operations are represented as URL builders so redirects and HTML are handled by the caller's browser rather than by the JSON transport.

## Authenticated user (`UserResource`)

| SDK method | Official operation | Auth | Request | SDK success return | Statuses |
|---|---|---|---|---|---|
| `get(?string $accessToken = null)` | [`GET /v1/users/self`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fusers%2Fself) | Workspace | No parameters; optional Bearer override, otherwise configured API-key or global-Bearer authentication. | Unwrapped `AuthUser`. OpenAPI sends it directly in `data`; sandbox nests it at `data.user` beside `data.accounts`. The SDK normalizes both. | `200; 401, 500` |
| `stats(string $granularity = "monthly", ?string $month = null, ?string $accessToken = null)` | [`GET /v1/users/self/stats`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fusers%2Fself%2Fstats) | Workspace | Optional `granularity: monthly\|daily`; SDK requires valid `month: YYYY-MM` for daily; optional Bearer override. Published, but the sandbox route was not deployed on 2026-08-05. | Unwrapped `DocumentStatsRow[]`, summed across the user's accounts, when deployed. | Published: `200; 400, 401, 500`. Audited sandbox: application-level `404` route-not-deployed. |

## Documents (`DocumentResource`)

Document uploads accept PDF files up to 25 MB and 2,000 pages. The SDK checks existence, the `.pdf` extension, and the 25 MB limit before upload.

| SDK method | Official operation | Auth | Request | SDK success return | Statuses |
|---|---|---|---|---|---|
| `upload($filePath)` | [`POST /v1/accounts/{accountId}/documents`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fdocuments) | Workspace | Required multipart `file` PDF. | Unwrapped `Document`. | `200; 400, 401, 500` |
| `get($documentId)` | [`GET /v1/documents/{documentId}`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D) | Workspace | Path ID only. | Unwrapped `Document`. | `200; 401, 404, 500` |
| `list($page, $perPage, $filters)` | [`GET /v1/accounts/{accountId}/documents`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fdocuments) | Workspace | Query: `status`, `method: virtual\|collect`, `search`, comma-separated `tags`, `sort`, `page`, `per-page`. | Envelope with `data: Document[]` and normalized `pagination`. | `200; 401, 500` |
| `search($term, $page, $perPage, $filters)` | [`GET /v1/accounts/{accountId}/documents/search`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fdocuments%2Fsearch) | Workspace | Query: `search`, `status`, `page`, `per-page`. | Envelope with lightweight `Document[]` and normalized `pagination`. | `200; 401, 500` |
| `rename($documentId, $name)` | [`PATCH /v1/documents/{documentId}`](https://api.assinafy.com.br/v1/docs/markdown?method=patch&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D) | Workspace | Required JSON `{name}`. Only valid before signing starts. | Unwrapped `Document`. | `200; 400, 401, 404, 500` |
| `delete($documentId)` | [`DELETE /v1/documents/{documentId}`](https://api.assinafy.com.br/v1/docs/markdown?method=delete&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D) | Workspace | Path ID only. | Envelope with `data: []`. | `200; 401, 404, 500` |
| `download($documentId, $artifact)` | [`GET /v1/documents/{documentId}/download/{artifactName}`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D%2Fdownload%2F%7BartifactName%7D) | Workspace | `artifactName`: `original`, `certificated`, `certificate-page`, or `bundle`. | Raw `application/pdf` bytes. | `200; 401, 404, 500` |
| `downloadThumbnail($documentId)` | [`GET /v1/documents/{documentId}/thumbnail`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D%2Fthumbnail) | Workspace | Path ID only. | Raw image bytes. | `200; 401, 404, 500` |
| `downloadPage($documentId, $pageId)` | [`GET /v1/documents/{documentId}/pages/{pageId}/download`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D%2Fpages%2F%7BpageId%7D%2Fdownload) | Workspace | Document and page path IDs. | Raw image bytes. | `200; 401, 404, 500` |
| `activities($documentId)` | [`GET /v1/documents/{documentId}/activities`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D%2Factivities) | Workspace | Path ID only. | Unwrapped `DocumentActivity[]`. | `200; 401, 500` |
| `statuses()` | [`GET /v1/documents/statuses`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fdocuments%2Fstatuses) | Workspace | No parameters. | Unwrapped `DocumentStatus[]`. | `200; 401, 500` |
| `verify($signatureHash)` | [`GET /v1/documents/{documentSignatureHash}/verify`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fdocuments%2F%7BdocumentSignatureHash%7D%2Fverify) | Public | Signature hash path value. | Unwrapped `DocumentVerification`. | `200; 500` |
| `publicInfo($documentId)` | [`GET /v1/public/documents/{documentId}`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fpublic%2Fdocuments%2F%7BdocumentId%7D) | Public | Document path ID. | Unwrapped `Document`. | `200; 404, 500` |
| `sendToken($documentId, $recipient, $channel)` | [`PUT /v1/public/documents/{documentId}/send-token`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Fpublic%2Fdocuments%2F%7BdocumentId%7D%2Fsend-token) | Public | **Runtime:** `{recipient, channel: "email"}`. Published schema incorrectly shows optional `{email}`. `recipient` must belong to a signer already assigned to this document. | Success envelope fields. | `200; 500` in spec; sandbox validation may return `400`, missing document `404`. |
| `listTags($documentId)` | [`GET /v1/accounts/{accountId}/documents/{documentId}/tags`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fdocuments%2F%7BdocumentId%7D%2Ftags) | Workspace | Account/document path IDs. | Unwrapped `Tag[]`. | `200; 401, 500` |
| `replaceTags($documentId, $tagNames)` | [`PUT /v1/accounts/{accountId}/documents/{documentId}/tags`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fdocuments%2F%7BdocumentId%7D%2Ftags) | Workspace | Required JSON `{tags: string[]}`. Despite an upstream description saying IDs, runtime values are names and missing names are auto-created. Empty replaces the set with none. | Unwrapped `Tag[]`. | `200; 401, 500` |
| `appendTags($documentId, $tagNames)` | [`POST /v1/accounts/{accountId}/documents/{documentId}/tags`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fdocuments%2F%7BdocumentId%7D%2Ftags) | Workspace | Required JSON `{tags: string[]}` using names; missing names are auto-created. SDK rejects an empty list. | Unwrapped `Tag[]`. | `200; 401, 500` |
| `detachTag($documentId, $tagId)` | [`DELETE /v1/accounts/{accountId}/documents/{documentId}/tags/{tagId}`](https://api.assinafy.com.br/v1/docs/markdown?method=delete&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fdocuments%2F%7BdocumentId%7D%2Ftags%2F%7BtagId%7D) | Workspace | Account, document, and tag path IDs. | Unwrapped `{detached: boolean}`. | `200; 401, 500` |
| `createFromTemplate($templateId, $signers, $options)` | [`POST /v1/accounts/{accountId}/templates/{templateId}/documents`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ftemplates%2F%7BtemplateId%7D%2Fdocuments) | Workspace | See [Create from template body](#create-from-template-body). | Unwrapped `Document`. | `200; 400, 401, 500` |
| `estimateCostFromTemplate($templateId, $signers)` | [`POST /v1/accounts/{accountId}/templates/{templateId}/documents/estimate-cost`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ftemplates%2F%7BtemplateId%7D%2Fdocuments%2Festimate-cost) | Workspace | Required `{signers: [{role_id, verification_method?, notification_methods?}]}`; signer ID is not required for estimation. | Unwrapped `CostEstimate`. | `200; 401, 500` |

### Create from template body

```text
signers*[]
  role_id*               template role ID
  id*                    existing signer ID
  verification_method
  notification_methods[]
  step                   positive sequential-signing step
editor_fields[]
  field_id*
  value*
name                     generated document name
message                  signer invitation message
expires_at               ISO-8601 date-time
tags[]                   tag names; missing names are created
```

The template's `default_document_tags` are always merged into the generated document's tags.

### Document helper methods

These public methods perform local/composite behavior rather than map one-to-one to an additional API operation.

| SDK method | Behavior |
|---|---|
| `waitUntilReady($documentId, $maxWaitSeconds, $pollIntervalSeconds)` | Polls [`GET /v1/documents/{documentId}`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D) until status is `metadata_ready`, `pending_signature`, `ready`, `certificating`, or `certificated`; throws immediately on `failed`, `expired`, `rejected_by_signer`, or `rejected_by_user`, and otherwise times out. |
| `isFullySigned($documentId)` | Calls [`GET /v1/documents/{documentId}`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D) and returns true once the last signer has signed (`ready`) and throughout `certificating` and `certificated`. The webhook catalog uses `ready`, although the published status catalog omits it. |
| `getSigningProgress($documentId)` | Calls [`GET /v1/documents/{documentId}`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D) and derives signed/total/pending/percentage from assignment items. |
| `assertUploadable($filePath)` | Public static validator shared by document/template uploads; requires an existing `.pdf` file no larger than 25 MB and returns `void`. The transport separately enforces readability. |
| `assertArtifact($artifact)` | Public static validator shared by workspace/signer downloads; accepts `original`, `certificated`, `certificate-page`, or `bundle` and returns `void`. |

## Fields (`FieldResource`)

Field definitions are workspace resources. Despite the SDK's optional signer-code argument on
validation methods, the current OpenAPI document declares Workspace authentication for both
validation endpoints. A signer code adds signer context; it does not replace the configured API
key or global Bearer credential.

| SDK method | Official operation | Auth | Request | SDK success return | Statuses |
|---|---|---|---|---|---|
| `list($includeInactive, $includeStandard)` | [`GET /v1/accounts/{accountId}/fields`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ffields) | Workspace | Optional boolean query `include_inactive`, `include_standard`. | Unwrapped `Field[]`; not documented as paginated. | `200; 401, 500` |
| `create($type, $name, $options)` | [`POST /v1/accounts/{accountId}/fields`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ffields) | Workspace | Required JSON `name`, `type`; optional `regex` (nullable), `is_required`. The SDK forwards extra options, but `is_active` is not in the current create schema. | Unwrapped `Field`. | `200; 400, 401, 500` |
| `get($fieldId)` | [`GET /v1/accounts/{accountId}/fields/{fieldId}`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ffields%2F%7BfieldId%7D) | Workspace | Field path ID. | Unwrapped `Field`. | `200; 401, 404, 500` |
| `update($fieldId, $data)` | [`PUT /v1/accounts/{accountId}/fields/{fieldId}`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ffields%2F%7BfieldId%7D) | Workspace | Required JSON object; published editable fields are `name`, nullable `regex`, and `is_active`. | Unwrapped `Field`. | `200; 401, 404, 500` |
| `delete($fieldId)` | [`DELETE /v1/accounts/{accountId}/fields/{fieldId}`](https://api.assinafy.com.br/v1/docs/markdown?method=delete&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ffields%2F%7BfieldId%7D) | Workspace | Field path ID. | Unwrapped empty list. | `200; 401, 404, 500` |
| `validate($fieldId, $value, $signerAccessCode)` | [`POST /v1/accounts/{accountId}/fields/{fieldId}/validate`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ffields%2F%7BfieldId%7D%2Fvalidate) | Workspace in spec | Required JSON `{value}`. SDK can additionally send a signer code query, which is undocumented. | Unwrapped `FieldValidation`. | `200; 401, 500` |
| `validateMultiple($values, $signerAccessCode)` | [`POST /v1/accounts/{accountId}/fields/validate-multiple`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ffields%2Fvalidate-multiple) | Workspace in spec | The body is directly a JSON array of required `{field_id, value}` objects; it is not wrapped in another property. Optional SDK signer code is undocumented. | Unwrapped `FieldValidationResult[]`. | `200; 401, 500` |
| `types()` | [`GET /v1/field-types`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Ffield-types) | Workspace | No parameters. | Unwrapped `FieldType[]`. | `200; 401, 500` |

## Signers (`SignerResource`)

These are account-owner operations, not signer-session operations.

| SDK method | Official operation | Auth | Request | SDK success return | Statuses |
|---|---|---|---|---|---|
| `list($page, $perPage, $search)` | [`GET /v1/accounts/{accountId}/signers`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fsigners) | Workspace | Query `search`, `page`, `per-page`. | Envelope with `data: Signer[]` and normalized `pagination`. | `200; 401, 500` |
| `create($fullName, $email, $whatsappPhoneNumber)` | [`POST /v1/accounts/{accountId}/signers`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fsigners) | Workspace | Required `full_name`; optional `email`; optional `whatsapp_phone_number` normalized to E.164 and required to include `+` plus country code. | Unwrapped `Signer`. | `200; 400, 401, 500` |
| `get($signerId)` | [`GET /v1/accounts/{accountId}/signers/{signerId}`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fsigners%2F%7BsignerId%7D) | Workspace | Signer path ID. | Unwrapped `Signer`. | `200; 401, 404, 500` |
| `update($signerId, $data)` | [`PUT /v1/accounts/{accountId}/signers/{signerId}`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fsigners%2F%7BsignerId%7D) | Workspace | Required JSON object containing any of `full_name`, `email`, `whatsapp_phone_number`. | Unwrapped `Signer`. | `200; 400, 401, 404, 500` |
| `delete($signerId)` | [`DELETE /v1/accounts/{accountId}/signers/{signerId}`](https://api.assinafy.com.br/v1/docs/markdown?method=delete&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fsigners%2F%7BsignerId%7D) | Workspace | Signer path ID. | Envelope with `data: []`. | `200; 401, 404, 500` |
| `findByEmail($email)` | Composite over [`GET /v1/accounts/{accountId}/signers`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fsigners) | Workspace | Sends exact email as `search` with `per-page=100`, follows every response page, then matches case-insensitively client-side. | First exact `Signer`, or null. | Same as signer list. |
| `normalizePhoneNumber(string $phone)` | Local static helper | None | Requires an explicit leading `+` and country code; permits spaces, parentheses, periods, and hyphens; resulting number must contain 8–15 digits and start nonzero. | Canonical E.164-style `+{digits}` string. | Throws `ValidationException` locally on ambiguous/invalid input. |

## Signer session (`SignerSessionResource`)

These methods act as the end signer and must not rely on the workspace API key. The SDK follows
the OpenAPI security scheme by putting `signer-access-code` in the query string; generated
Markdown's competing `access_code` name remains an upstream ambiguity. The audited assignment
`signing_urls` did not reveal this code: obtain it from the assigned signer's controlled inbox
after `sendToken()`. Without an explicitly supplied real code, a signer-read call has not been
live-verified and returns `401` for the invalid URL-path heuristic.

| SDK method | Official operation | Auth | Request | SDK success return | Statuses |
|---|---|---|---|---|---|
| `self($accessCode)` | [`GET /v1/signers/self`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fsigners%2Fself) | Signer | Access code query. | Unwrapped `SignerSelf`. | `200; 401, 500` |
| `acceptTerms($accessCode)` | [`PUT /v1/signers/accept-terms`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Fsigners%2Faccept-terms) | Signer | `signer-access-code` query; no request body. | Success envelope fields. | `200; 401, 500` |
| `verifyCode($accessCode, $verificationCode)` | [`POST /v1/verify`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Fverify) | Signer | Access code query plus required JSON `{ "verification-code": string }`. | Success envelope fields. | `200; 400, 401, 500` |
| `confirmData($documentId, $accessCode, $data)` | [`PUT /v1/documents/{documentId}/signers/confirm-data`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D%2Fsigners%2Fconfirm-data) | Signer | Required JSON object with published fields `full_name`, `email`, `government_id`; access code query. All body properties are optional in the schema. | Unwrapped `Signer`. | `200; 401, 500` |
| `uploadSignature($accessCode, $type, $imageBytes, $mimeType, $reuse)` | [`POST /v1/signature`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Fsignature) | Signer | Access code query; `type` (`signature` or `initial`) and optional `reuse: boolean`; required raw image body. OpenAPI lists PNG; SDK also accepts JPEG for runtime compatibility. | Success envelope fields. | `200; 401, 500` |
| `downloadSignature($accessCode, $type)` | [`GET /v1/signature/{signatureType}`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fsignature%2F%7BsignatureType%7D) | Signer | Signature type path plus access code query. | Raw image bytes. | `200; 401, 404, 500` |
| `currentDocument($accessCode, $hasAcceptedTerms)` | [`GET /v1/sign`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fsign) | Signer | Access code query and optional `has_accepted_terms: boolean`. | Unwrapped `Document`. | `200; 401, 409, 500` |
| `sign($documentId, $assignmentId, $accessCode, $fields)` | [`POST /v1/documents/{documentId}/assignments/{assignmentId}`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D%2Fassignments%2F%7BassignmentId%7D) | Signer | Access code query; body is directly an array of `{itemId, fieldId, pageId, value}` objects. The SDK permits `[]`, matching the absence of `minItems`. | Unwrapped result object. | `200; 400, 401, 409, 500` |
| `decline($documentId, $assignmentId, $accessCode, $reason)` | [`PUT /v1/documents/{documentId}/assignments/{assignmentId}/reject`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Fdocuments%2F%7BdocumentId%7D%2Fassignments%2F%7BassignmentId%7D%2Freject) | Signer | Access code query; required JSON `{decline_reason}`. | Unwrapped empty list. | `200; 401, 500` |

For virtual assignments, the sign operation description says signer data must first be confirmed. It does not clearly say whether `confirm-data` alone completes signing or whether the sign endpoint must then receive an empty array. The SDK allows an empty list so it does not impose a `minItems` rule absent from OpenAPI.

## Signer documents (`SignerDocumentResource`)

| SDK method | Official operation | Auth | Request | SDK success return | Statuses |
|---|---|---|---|---|---|
| `current($signerId, $accessCode)` | [`GET /v1/signers/{signerId}/document`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fsigners%2F%7BsignerId%7D%2Fdocument) | Signer | Signer path ID plus access code query. | Unwrapped `Document`. | `200; 401, 404, 500` |
| `list($signerId, $accessCode, $filters)` | [`GET /v1/signers/{signerId}/documents`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fsigners%2F%7BsignerId%7D%2Fdocuments) | Signer | Published query `page`, `per-page`; SDK accepts those filters and adds access code. | Envelope with `data: Document[]` and normalized `pagination`. | `200; 401, 500` |
| `search($signerId, $accessCode, $term)` | [`GET /v1/signers/{signerId}/documents/search`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fsigners%2F%7BsignerId%7D%2Fdocuments%2Fsearch) | Signer | Signer path ID, access code query, `search` query. | Unwrapped lightweight `Document[]`. | `200; 401, 500` |
| `signMultiple($accessCode, $documentIds)` | [`PUT /v1/signers/documents/sign-multiple`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Fsigners%2Fdocuments%2Fsign-multiple) | Signer | Access code query; required JSON `{document_ids: string[]}`. SDK rejects an empty list. | Unwrapped empty list. | `200; 401, 500` |
| `declineMultiple($accessCode, $documentIds, $reason)` | [`PUT /v1/signers/documents/decline-multiple`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Fsigners%2Fdocuments%2Fdecline-multiple) | Signer | Access code query; required JSON `{document_ids: string[], decline_reason: string}`. | Unwrapped empty list. | `200; 401, 500` |
| `download($signerId, $documentId, $accessCode, $artifact)` | [`GET /v1/signers/{signerId}/documents/{documentId}/download/{artifactName}`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fsigners%2F%7BsignerId%7D%2Fdocuments%2F%7BdocumentId%7D%2Fdownload%2F%7BartifactName%7D) | Public in spec; SDK requires signer code | Artifact is `original`, `certificated`, `certificate-page`, or `bundle`. | Raw PDF bytes. | `200; 404, 500` |

## Tags (`TagResource`)

| SDK method | Official operation | Auth | Request | SDK success return | Statuses |
|---|---|---|---|---|---|
| `list($search)` | [`GET /v1/accounts/{accountId}/tags`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ftags) | Workspace | Optional `search` query. This endpoint is not paginated. | Unwrapped `Tag[]`. | `200; 401, 500` |
| `create($name, $color)` | [`POST /v1/accounts/{accountId}/tags`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ftags) | Workspace | Required `name` (normalized, maximum 64 chars); optional nullable six-character hex `color`, with or without `#`. | Unwrapped `Tag`. Name collision returns 409. | `200; 400, 401, 409, 500` |
| `update($tagId, $data)` | [`PUT /v1/accounts/{accountId}/tags/{tagId}`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ftags%2F%7BtagId%7D) | Workspace | Required JSON object with optional `name`, nullable `color`. SDK rejects an empty update. | Unwrapped `Tag`. | `200; 400, 401, 404, 500` |
| `delete($tagId, $force)` | [`DELETE /v1/accounts/{accountId}/tags/{tagId}`](https://api.assinafy.com.br/v1/docs/markdown?method=delete&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ftags%2F%7BtagId%7D) | Workspace | Optional `force: boolean` query to detach before deletion. | Unwrapped `{deleted: boolean}`. | `200; 401, 404, 500` |

## Templates (`TemplateResource`)

Only `list()` has a published template-management operation. The remaining methods are retained because they have been live-verified, but callers should understand that undocumented routes can change without an OpenAPI diff.

| SDK method | Operation | Auth | Request | SDK success return | Statuses/contract |
|---|---|---|---|---|---|
| `list($page, $perPage, $filters)` | [`GET /v1/accounts/{accountId}/templates`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Ftemplates) | Workspace | Published query `search`, `page`, `per-page`. SDK also forwards undocumented filters such as `status` and `sort`. | Envelope with `data: Template[]` and normalized `pagination`. | `200; 401, 500` |
| `create($filePath)` | `POST /v1/accounts/{accountId}/templates` | Workspace | Undocumented multipart PDF `file`; SDK enforces PDF and 25 MB. | Unwrapped `Template`. | **Live-verified, absent from OpenAPI.** |
| `get($templateId)` | `GET /v1/accounts/{accountId}/templates/{templateId}` | Workspace | Template path ID. | Unwrapped `Template`, including roles, pages, and `default_document_tags`. | **Live-verified, absent from OpenAPI.** |
| `update($templateId, $data)` | `PUT /v1/accounts/{accountId}/templates/{templateId}` | Workspace | Undocumented editable subset `{name, document_name, message}`. | Unwrapped `Template`. | **Live-verified, absent from OpenAPI.** |
| `delete($templateId)` | `DELETE /v1/accounts/{accountId}/templates/{templateId}` | Workspace | Template path ID. | Unwrapped success data. | **Live-verified, absent from OpenAPI.** |
| `downloadPage($templateId, $pageId)` | `GET /v1/accounts/{accountId}/templates/{templateId}/pages/{pageId}/download` | Workspace | Template/page path IDs. | Raw rendered image bytes. | **Live-verified, absent from OpenAPI.** |
| `waitUntilReady($templateId, $maxWaitSeconds, $pollIntervalSeconds)` | Composite over undocumented template `get()` | Workspace | Polls the template ID until `ready`, `failed`/`processing_failed`, or timeout. | Unwrapped `Template` when ready. | Local/composite helper. |

The published `Template` schema says `default_document_tags` appears only in a single-template response even though the corresponding single-template operation is not in OpenAPI. This is further evidence that the published template path inventory is incomplete.

## Webhooks (`WebhookResource`)

| SDK method | Official operation | Auth | Request | SDK success return | Statuses |
|---|---|---|---|---|---|
| `register($url, $email, $events, $isActive)` | [`PUT /v1/accounts/{accountId}/webhooks/subscriptions`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fwebhooks%2Fsubscriptions) | Workspace | Required JSON `{events: string[], is_active: boolean, url: URI, email: email}`. Empty SDK events selects `DEFAULT_EVENTS`. | Unwrapped `WebhookSubscription`. | `200; 400, 401, 500` |
| `get()` | [`GET /v1/accounts/{accountId}/webhooks/subscriptions`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fwebhooks%2Fsubscriptions) | Workspace | Account path only. | Unwrapped `WebhookSubscription`, or null when the returned data is empty. | `200; 401, 500` |
| `deactivate()` | [`PUT /v1/accounts/{accountId}/webhooks/inactivate`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fwebhooks%2Finactivate) | Workspace | No body. | Unwrapped `WebhookSubscription`. | `200; 401, 500` |
| `activate()` | Composite over [`GET`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fwebhooks%2Fsubscriptions) and [`PUT /v1/accounts/{accountId}/webhooks/subscriptions`](https://api.assinafy.com.br/v1/docs/markdown?method=put&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fwebhooks%2Fsubscriptions) | Workspace | Reads the stored subscription and re-sends it with `is_active=true`; throws when no URL is configured. | Unwrapped `WebhookSubscription`. | Combined get/update behavior. |
| `eventTypes()` | [`GET /v1/webhooks/event-types`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fwebhooks%2Fevent-types) | Workspace | No parameters. | Unwrapped `WebhookEventType[]`. | `200; 401, 500` |
| `dispatches($filters)` | [`GET /v1/accounts/{accountId}/webhooks`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fwebhooks) | Workspace | Query `event`, `delivered: "true"\|"false"`, Unix `from`, Unix `to`, `page`, `per-page`. | Envelope with `data: WebhookDispatch[]` and normalized `pagination`. | `200; 401, 500` |
| `retryDispatch($dispatchId)` | [`POST /v1/accounts/{accountId}/webhooks/{historyId}/retry`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fwebhooks%2F%7BhistoryId%7D%2Fretry) | Workspace | Dispatch history path ID. | Unwrapped new `WebhookDispatch`. | `200; 400, 401, 404, 500` |

## Seven published mappings added by the audit

The initial contract comparison found seven published operations without an SDK mapping. They are now represented as follows. The two browser-facing operations intentionally return URLs instead of forcing redirects or HTML through the JSON transport.

| Official operation | Auth | Exact request | Success response | Statuses | Current SDK mapping |
|---|---|---|---|---|---|
| [`GET /v1/auth/authenticate`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fauth%2Fauthenticate) | Public | Optional query `authclient`, currently e.g. `google`. | `302` redirect to provider; no JSON schema. | `302; 500` | `auth()->socialLoginUrl()` builds the live browser URL. |
| [`GET /v1/login-callback`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Flogin-callback) | Public | No documented parameters. | `200`; callback payload schema is unspecified. Sandbox returns HTML. | `200; 500` | `auth()->socialLoginCallbackUrl()` builds the callback URL. |
| [`POST /v1/auth/link-social-login`](https://api.assinafy.com.br/v1/docs/markdown?method=post&path=%2Fv1%2Fauth%2Flink-social-login) | Workspace | Required JSON `{provider: "google", token: string}`. | Success `Envelope` with no documented data. | `200; 400, 401, 500` | `auth()->linkSocialLogin()`. |
| [`GET /v1/accounts/{accountId}/stats`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Faccounts%2F%7BaccountId%7D%2Fstats) | Workspace | Optional `granularity: monthly\|daily`; `month: YYYY-MM` for daily. | Published: envelope `data: DocumentStatsRow[]`. Sandbox 2026-08-05: application-level `404` route-not-deployed. | `200; 400, 401, 500` | `accounts()->stats()` is retained for the published operation; sandbox execution is not currently claimed. |
| [`GET /v1/users/self/stats`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fusers%2Fself%2Fstats) | Workspace | Same `granularity` and `month` query as account stats. | Published: envelope `data: DocumentStatsRow[]`, summed across the user's accounts. Sandbox 2026-08-05: application-level `404` route-not-deployed. | `200; 400, 401, 500` | `users()->stats()` is retained for the published operation; sandbox execution is not currently claimed. |
| [`GET /v1/users/self`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fusers%2Fself) | Workspace | No parameters. | Published: envelope `data: AuthUser`. Sandbox: `data: {user: AuthUser, accounts: AuthAccount[]}`. | `200; 401, 500` | `users()->get()` normalizes `data.user` to `AuthUser` and also accepts the published shape. |
| [`GET /v1/signers/{signerId}/documents/search`](https://api.assinafy.com.br/v1/docs/markdown?method=get&path=%2Fv1%2Fsigners%2F%7BsignerId%7D%2Fdocuments%2Fsearch) | Signer | Signer path ID, access-code authentication, optional `search` query. | Envelope `data: Document[]`. | `200; 401, 500` | `signerDocuments()->search()`. |

## Response schema dictionary

Operations above refer to the shared response schemas below. `?` means nullable. Open objects are intentionally shown as `object`: the official schema does not constrain their inner properties.

### Transport and authentication schemas

| Schema | Fields |
|---|---|
| `Envelope` | `status: integer`, `message: string`. Operations add their own `data` property; some success envelopes have no data. |
| `ErrorEnvelope` | `status: integer`, `message: string`, `data: object?`. |
| `ApiKey` | `api_key: string?`. It is full only immediately after generation and otherwise masked. |
| `AuthSession` | `access_token: string`, `user: AuthUser`, `accounts: AuthAccount[]`. |
| `AuthUser` | `id`, `name`, `email`, `telephone?`, `government_id?`, `is_email_verified`, `has_accepted_terms`, `created_at`, `to_be_deleted_at?`. Dates are ISO-8601 date-times. |
| `AuthAccount` | `id`, `name`, `roles: string[]`, `is_delete_allowed: boolean`, `created_at`. |

### Account, signer, and tag schemas

| Schema | Fields |
|---|---|
| `Account` | `resource`, `id`, `name`, `primary_color?`, `secondary_color?`, `notification_sender_type: "User"\|"Account"`, `roles: string[]`, `is_delete_allowed`, `created_at`. |
| `AccountTheme` | `account_name`, `primary_color`, `secondary_color?`, `logo`. Colors omit the leading `#`; logo is a URL. |
| `Signer` | `resource`, `id`, `full_name`, `email?`, `whatsapp_phone_number?`, `has_accepted_terms`. |
| `SignerSelf` | Every `Signer` field plus `has_signature`, `has_initial`, `is_signature_reusable`. |
| `Tag` | `resource`, `id`, `name`, `color?`, `created_at`, `updated_at`. Color is six-character hex without `#`. |

### Document schemas

| Schema | Fields |
|---|---|
| `Document` | `resource`, `id`, `account_id`, `template_id?`, `name`, `status`, `artifacts: object`, `is_closed`, `signing_url`, `decline_reason?`, `declined_by: Signer?`, `tags: {id,name}[]`, `assignment: Assignment?`, `pages: DocumentPage[]`, `created_at`, `updated_at`. |
| `DocumentPage` | `id`, `number: integer`, `height: integer`, `width: integer`, `download_url`. |
| `DocumentStatus` | `code`, `deletable: boolean`. Published codes: `uploading`, `uploaded`, `metadata_processing`, `metadata_ready`, `expired`, `certificating`, `certificated`, `rejected_by_signer`, `pending_signature`, `rejected_by_user`, `failed`. |
| `DocumentVerification` | `hash`, `id?`, `status?`, `page_count: string?`, `signer_count: string?`, `completed_count: integer?`, `completed_at?`, `verified_at`, `is_valid`, `message`. Note that the published types of page and signer count are strings. |
| `DocumentActivity` | `id: integer`, `event`, `message`, `payload: object?`, `origin: {ip, user-agent}?`, `created_at` ISO-8601 date-time. |
| `DocumentStatsRow` | `period`, `documents_uploaded`, `documents_sent`, `signature_requests`, `signature_requests_email`, `signature_requests_whatsapp`, `signature_requests_viewed`, `signature_requests_completed`, `documents_certified`; all metrics are integers. |

### Assignment schemas

| Schema | Fields |
|---|---|
| `Assignment` | `resource`, `id`, `sender_email`, `method: "virtual"\|"collect"`, `expires_at?`, `message?`, `signers: AssignmentSigner[]`, `copy_receivers: object[]`, `items: AssignmentItem[]`, `summary: AssignmentSummary`, `signing_urls: SigningUrl[]`. |
| `AssignmentSigner` | Every `Signer` field plus `verification_method?`, `notification_methods: string[]?`, `step: integer?`, `notified: boolean?`, `completed: boolean?`, `notification_history: NotificationHistoryEntry[]?`. |
| `NotificationHistoryEntry` | `event`, `status: "sent"\|"failed"`, `error_code?`, `error_message?`, `sent_at?`, `failed_at?`. |
| `AssignmentItem` | `id`, `page: DocumentPage?`, `signer: object`, `field: object?`, `display_settings: object`, `value: object?`, `completed: boolean`. |
| `AssignmentSummary` | `signer_count: integer`, `completed_count: integer`, `signers: object[]`. |
| `SigningUrl` | `signer_id`, `url`. It has no access-code field, and the URL must not be parsed as if one were present. |
| `CostEstimate` | `documents: integer`, `credits: number`, `needs_extra_document`, `extra_document_cost: number`, `total_credits: number`, `breakdown: CostEstimateBreakdownItem[]`, `document_balance: number`, `credit_balance: number`, `has_sufficient_resources`, `blocking_reason?`, `message?`. |
| `CostEstimateBreakdownItem` | `code`, `name`, `cost: number`, `quantity: integer`, `unit_cost: number`. |

`CostEstimate.blocking_reason` is one of `PendingPayment`, `InsufficientDocuments`, or `InsufficientCredits`. Published pricing is one document per assignment, one credit for an extra document, zero credits for Email notification, and 0.45 credits for WhatsApp notification.

### Field schemas

| Schema | Fields |
|---|---|
| `Field` | `resource`, `id`, `name`, `type`, `regex?`, `is_pre_defined`, `is_active`, `is_required`, `is_standard`, `is_read_only`, `is_visible`. |
| `FieldType` | `type`, `name`. |
| `FieldValidation` | `type`, `success: boolean`, `error_message`. |
| `FieldValidationResult` | `field_id`, `type`, `success: boolean`, `error_message`. |

### Template schemas

| Schema | Fields |
|---|---|
| `Template` | `resource`, `id`, `name`, `document_name?`, `message?`, `status`, `pages: TemplatePage[]`, `roles: TemplateRole[]`, `tags: {id,name}[]`, `default_document_tags: {id,name}[]`, `created_at`, `updated_at`. |
| `TemplatePage` | `id`, `number`, `height`, `width`, `download_url`, `fields: TemplateFieldPlacement[]`. |
| `TemplateFieldPlacement` | `id`, `field_id`, `role_id`, `label`, `display_settings: object`, `created_at`, `updated_at`. |
| `TemplateRole` | `id`, `name`, `assignment_type`, `created_at`, `updated_at`. |

Template status is one of `uploading`, `uploaded`, `processing`, `ready`, or `failed`.

### Webhook schemas

| Schema | Fields |
|---|---|
| `WebhookSubscription` | `events: string[]`, `is_active`, `url?`, `email?`, `updated_at?`. |
| `WebhookDispatch` | `resource`, `id`, `event`, `activity_id: integer`, `endpoint?`, `payload: object?`, `delivered`, `http_status: integer?`, `response_body?`, `error?`, `created_at`, `updated_at`. Stored response body is truncated to 2,000 characters. |
| `WebhookEventType` | `id`, `description`. |
| `WhatsappNotification` | `sent_at: integer` Unix timestamp, `header`, `body`, `buttons: {text}[]`, `phone_number`, `signer_id`. |

### Inline success and restriction shapes

These response data objects are defined directly on operations rather than as reusable components:

- Resend result: `{is_sent: boolean, document_id: string, signer_id: string}`.
- Document tag detach: `{detached: boolean}`.
- Tag deletion: `{deleted: boolean}`.
- Password change/reset responses: `{email: string}`.
- Sign-assignment response: unconstrained `object`.
- Successful deletion responses: empty array `[]`.
- Account deletion restriction error: `restrictions[]` containing `code`, `message`, and `account_ids[]`; code is `ActivePaidSubscription` or `PendingDocuments`.

## Incoming webhook delivery contract

Webhook deliveries are outbound requests from Assinafy to the configured subscription URL. They are separate from the webhook-management operations above.

| Property | Published behavior |
|---|---|
| Method and media type | `POST`, `Content-Type: application/json`, `Connection: close`. |
| Success | Any 2xx response. |
| Attempts | Initial attempt plus one retry (two total), with three seconds between attempts. |
| Circuit breaker | After ten consecutive failed events, delivery pauses and approximately 5% of events are probed until one succeeds. Manual retry forces another delivery. |
| Response capture | First 2,000 response-body characters are stored in dispatch history. |
| Signing | No webhook signature or registration secret is documented. Do not claim HMAC verification unless the platform adds a real signing contract. |

### Incoming body

```text
id          integer activity ID; useful as a deduplication key
event       event code
message     string|null
payload     object|null, event-specific
origin      {ip, user-agent}|null
created_at  integer Unix timestamp in seconds
subject     polymorphic resource object
object      polymorphic resource object with expanded relationships
account_id  owning account ID
```

`subject` and `object` include a `type` of `User`, `Signer`, `Account`, `Document`, or `Template`. An Account's `integration` relationship is removed. The current `WebhookEventParser` accepts these objects because it is shape-tolerant, but older examples that show string `subject`, string `origin`, or ISO-string `created_at` do not match the current published contract.

### Event catalog

| Event | Subject → object | Published payload keys |
|---|---|---|
| `document_uploaded` | User → Document | none |
| `document_metadata_ready` | User → Document | none |
| `document_prepared` | User → Document | none |
| `assignment_created` | User → Document | `user_name`, `user_email`, `user_telephone` |
| `document_ready` | Account → Document | none |
| `document_processing_failed` | Account → Document | `error_message` |
| `signature_requested` | User → Document | `signer_email`, `signer_full_name`, or `signer_whatsapp_phone_number`, depending on method |
| `signer_created` | User → Signer | `signer_full_name` |
| `signer_email_verified` | Signer → Document | `signer_email` |
| `signer_whatsapp_verified` | Signer → Document | `signer_whatsapp_phone_number` |
| `signer_data_confirmed` | Signer → Document | `signer_email` |
| `signer_viewed_document` | Signer → Document | `signer_full_name` |
| `signer_signed_document` | Signer → Document | `signer_full_name` |
| `signer_rejected_document` | Signer → Document | `signer_full_name` |
| `user_rejected_document` | User → Document | `user_name` |
| `template_created` | User → Template | none |
| `template_processed` | User → Template | none |
| `template_processing_failed` | Account → Template | `error_message` |

`assignment_created` and `document_metadata_ready` have no guaranteed order in the virtual pre-metadata flow. Consumers must tolerate new event fields and unknown event codes. The SDK exposes constants for all 18 documented events; `eventTypes()` remains the authoritative runtime catalog for future additions.

The event prose says `document_ready` leaves a document in status `ready`, while the published document-status catalog has no `ready` status and instead ends with `certificating`/`certificated`. Treat this as a documentation ambiguity and use the actual `Document.status` value delivered.

## Named OpenAPI examples

The current OpenAPI document defines six named examples, all on assignment creation:

| Example | Purpose |
|---|---|
| `AssignmentCreateVirtual` | Virtual request with signer IDs, sequential steps, and expiration. |
| `AssignmentCreateVirtualFull` | Same, with explicit Email and Whatsapp verification/notification methods. |
| `AssignmentCreateCollect` | Collect request with page/field placements and display settings. |
| `AssignmentCreateCollectFull` | Same, with explicit verification/notification methods. |
| `AssignmentCreatedVirtual` | Full success envelope containing assignment, signers, virtual item, summary, and signing URLs. |
| `AssignmentCreatedCollect` | Full success envelope containing assigned page fields, summary, and signing URLs. |

Representative request shapes, with identifiers replaced by placeholders:

```json
{
  "method": "virtual",
  "signers": [
    {
      "id": "signer-id",
      "verification_method": "Email",
      "notification_methods": ["Email"],
      "step": 1
    }
  ],
  "expires_at": "2026-09-30T21:00:00Z"
}
```

```json
{
  "method": "collect",
  "signers": [{"id": "signer-id", "step": 1}],
  "entries": [
    {
      "page_id": "page-id",
      "fields": [
        {
          "signer_id": "signer-id",
          "field_id": "field-id",
          "display_settings": {
            "left": 69,
            "top": 282,
            "fontFamily": "Arial",
            "fontSize": 18,
            "backgroundColor": "rgb(185, 218, 255)"
          }
        }
      ]
    }
  ]
}
```

Property-level examples also appear throughout the schemas. They illustrate values but do not override field types, required lists, enumerations, or verified sandbox behavior.

## Maintenance checklist

When the upstream documentation changes:

1. Diff `GET https://api.assinafy.com.br/v1/docs/openapi.json` by method/path, security, parameters, bodies, responses, and shared schemas.
2. Re-run non-destructive sandbox integration coverage before deleting an undocumented method or changing a known divergence.
3. Test a complete signer flow before changing access-code placement or the virtual empty-array behavior. Live authenticated signer reads require `ASSINAFY_SIGNER_ID` plus an inbox-delivered `ASSINAFY_SIGNER_ACCESS_CODE`; notification success alone is not signer-read coverage.
4. Verify all paginated SDK methods preserve the four `X-Pagination-*` headers.
5. Refresh this file, unit tests, live tests, README tables, and examples together.
6. Never put API keys, signer access codes, reset tokens, or live personal/account identifiers into fixtures or documentation.
