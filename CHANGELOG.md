# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-05-11

Full audit against `https://api.assinafy.com.br/v1/docs` verified against the live API.

### Added

- **`AuthResource`** (`$client->auth()`) covering every authentication endpoint:
  `POST /login`, `POST /authentication/social-login`, `POST/GET/DELETE /users/api-keys`,
  `PUT /authentication/change-password`, `PUT /authentication/request-password-reset`,
  `PUT /authentication/reset-password`.
- **`SignerSessionResource`** (`$client->signerSession()`) covering signer-facing endpoints
  authenticated with a `signer-access-code`: `GET /signers/self`, `PUT /signers/accept-terms`,
  `POST /verify`, `PUT /documents/{id}/signers/confirm-data`,
  `POST /signature`, `GET /signature/{type}`.
- **`DocumentResource`**:
  - `delete($documentId)` — `DELETE /documents/{id}`
  - `download($documentId, $artifact)` — now correctly hits
    `GET /documents/{id}/download/{artifact_name}` and validates the artifact name
  - `downloadThumbnail($documentId)` — `GET /documents/{id}/thumbnail`
  - `downloadPage($documentId, $pageId)` — `GET /documents/{id}/pages/{page_id}/download`
  - `activities($documentId)` — `GET /documents/{id}/activities`
  - `statuses()` — `GET /documents/statuses`
  - `publicInfo($documentId)` — `GET /public/documents/{id}`
  - `sendToken($documentId, $recipient, $channel)` — `PUT /public/documents/{id}/send-token`
  - Status / artifact-name constants for type safety (`STATUS_*`, `ARTIFACT_*`)
- **`AssignmentResource`**:
  - `METHOD_*` and `VERIFICATION_*` constants
  - `create()` now accepts either string signer IDs or full signer objects and serialises them
    to the documented `signers: [{ id, verification_method?, notification_methods? }]` shape
- **`HttpClientInterface::postRaw()`** for binary uploads (signature image bytes).
- Full **PHPUnit test suite** (`tests/Unit`, `tests/Integration`) — 66 unit tests + 6 live tests
  against the production API.

### Changed

- **Pagination param fix**: every `list()` method now sends `per-page` (with hyphen) as the
  API expects. Previously `per_page` was sent and silently ignored.
- **Upload size limit** lowered from a fictional 50 MB to the documented 25 MB.
- **`DocumentResource::waitUntilReady()`** now polls for the real status codes
  (`metadata_ready`, `pending_signature`, `certificated`) and fails fast on `failed`,
  `expired`, `rejected_by_signer`, `rejected_by_user`.
- **`DocumentResource::isFullySigned()`** now checks `status === 'certificated'` (was a
  fictional `'signed'`).
- **`DocumentResource::getSigningProgress()`** now reads progress from `document.assignment`.
- **`SignerResource::create()`** signature simplified to `(fullName, email?, whatsappPhoneNumber?)` —
  removed unsupported `cpf` and `metadata` fields.
- **`SignerResource`** phone numbers are now normalised to E.164 (the `+` prefix is preserved).
- **`GuzzleHttpClient`** ensures the `base_uri` ends with `/` so relative request paths resolve
  correctly per RFC 3986 (previously every request lost the `/v1` prefix and 404'd).
- **`GuzzleHttpClient::uploadFile()`** no longer overrides the multipart Content-Type header
  (which stripped the boundary).
- **`Configuration::getHeaders()`** no longer pins `Content-Type: application/json` globally —
  it's set per-request by JSON helpers, leaving uploads and binary calls free to set their own.
- **`AssinafyClient::uploadAndRequestSignatures()`** signature changed to
  `(filePath, signers, ?message, ?expiresAt, waitForReady)`. It now creates / reuses signers by
  email and uses the documented assignment payload.
- **`Configuration::SDK_VERSION`, `DEFAULT_BASE_URL`, `SANDBOX_BASE_URL`** constants.

### Removed

- **`AssignmentResource::cancel()`** — the underlying endpoint
  `POST /accounts/{id}/signature-requests/{id}/cancel` does not exist on the API (verified
  with a live 404).
- **`AssignmentResource::resendNotification()`** — the underlying endpoint
  `POST /accounts/{id}/signature-requests/resend` does not exist (verified with a live 404).
  Use `resend()` instead, which hits the documented path.
- **`AbstractResource::normalizeId()`** — alias hack adding `document_id` keys to API responses.
  Read the real `id` field instead.

### Fixed

- Upload no longer sends bogus `name` / `metadata` multipart fields (only `file`).
- Every `list()` URL now resolves correctly against the v1 base URL.

## [1.1.1] - 2026-05-06

### Fixed

- **`SignerResource::create`** — changed payload key from `phone` to `whatsapp_phone_number` to match the documented Assinafy API field name. The method signature (`?string $phone`) is unchanged for backward compatibility; callers pass a phone number as before and the SDK now sends it under the correct field.
- **`SignerResource::normalizeSignerResponse`** — the normalised response now maps the API's `whatsapp_phone_number` field instead of the legacy `phone` key.

## [1.1.0] - 2026-05-06

Full audit against the Assinafy REST API v1 docs (`https://api.assinafy.com.br/v1/docs`).
All new endpoints from the official API catalog added without breaking existing method signatures.

### Added

- **`TemplateResource`** (new class) with:
  - `list(int $page, int $perPage, array $filters)` — `GET /accounts/{accountId}/templates`
  - `get(string $templateId)` — `GET /accounts/{accountId}/templates/{templateId}`
- **`AssinafyClient::templates()`** accessor that lazily instantiates `TemplateResource`.
- **`DocumentResource`**:
  - `createFromTemplate(string $templateId, array $signers, array $options)` — `POST /accounts/{accountId}/templates/{templateId}/documents`
  - `estimateCostFromTemplate(string $templateId, array $signers)` — `POST /accounts/{accountId}/templates/{templateId}/documents/estimate-cost`
  - `verify(string $hash)` — `GET /documents/{hash}/verify`
- **`AssignmentResource`**:
  - `estimateCost(string $documentId, array $signers, string $method, ?array $entries)` — `POST /documents/{documentId}/assignments/estimate-cost`
  - `resend(string $documentId, string $assignmentId, string $signerId)` — `PUT /documents/{documentId}/assignments/{assignmentId}/signers/{signerId}/resend`
  - `estimateResendCost(string $documentId, string $assignmentId, string $signerId)` — `POST /documents/{documentId}/assignments/{assignmentId}/signers/{signerId}/estimate-resend-cost`
  - `resetExpiration(string $documentId, string $assignmentId, string $expiresAt)` — `PUT /documents/{documentId}/assignments/{assignmentId}/reset-expiration`
- **`SignerResource`**:
  - `update(string $signerId, array $data)` — `PUT /accounts/{accountId}/signers/{signerId}`
  - `delete(string $signerId)` — `DELETE /accounts/{accountId}/signers/{signerId}`

## [1.0.0] - 2024-12-22

### Added
- Initial release of framework-agnostic PHP SDK
- PSR-4 autoloading
- PSR-3 logger interface support
- PSR-18 HTTP client interface
- Document management (upload, download, status tracking)
- Signer management (create, list, search)
- Assignment management (create, cancel, resend)
- Webhook support (register, verify signatures)
- Comprehensive exception hierarchy
- Docker development environment
- Complete documentation and examples

### Fixed
- PHP 7.4 compatibility (replaced `str_contains()` and `str_ends_with()`)

### Security
- HMAC-SHA256 webhook signature verification
- Timing-safe signature comparison

## PHP Compatibility

- **PHP 7.4**: Full support with positional arguments
- **PHP 8.0+**: Full support with named arguments
- **PHP 8.1+**: Recommended for best developer experience

[1.2.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.2.0
[1.1.1]: https://github.com/assinafy/php-sdk/releases/tag/v1.1.1
[1.1.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.1.0
[1.0.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.0.0
