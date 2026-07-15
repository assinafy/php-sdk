# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-07-15

Full audit against the live API. Assinafy has replaced the hand-written HTML docs with a
[Scalar](https://scalar.com) reference backed by a machine-readable OpenAPI spec at
`https://api.assinafy.com.br/v1/docs/openapi.json` (86 operations across 10 tags), so this
pass compared the SDK to that spec operation-by-operation and parameter-by-parameter — then
verified every finding against the live sandbox before acting on it.

That last step mattered. The spec and the running API disagree in several places, in both
directions, and two "obvious" spec-derived fixes turned out to be wrong (see *Deliberately
unchanged*). Coverage went from 70/86 to 84/86; the two remaining operations are browser
OAuth redirect targets, one of which does not exist on the live API at all.

See [UPGRADING.md](UPGRADING.md) for migration steps.

### Security

- **Credentials no longer leak into logs.** `GuzzleHttpClient::request()` logged the entire
  request options array at debug level, writing plaintext passwords (`login()`,
  `generateApiKey()`, `changePassword()`, `resetPassword()`), `Authorization` bearer tokens,
  and response `access_token`s to wherever the host application ships its logs. A new
  `Http\LogRedactor` masks them; a regression test asserts a real `login()` call leaks
  nothing. **Rotate any credential that may have been captured in existing logs.**
- **Dependency advisories cleared** (6 across 3 packages), notably `CVE-2026-55568`
  (Guzzle: silent HTTPS proxy downgrade to cleartext) and `CVE-2026-55766` (psr7: CRLF
  injection in start-line serialization). Guzzle's floor is now `^7.12.1` in `require-dev`
  and `suggest`.

### Removed (breaking)

- **Webhook signature verification.** `WebhookVerifier::verify()` did HMAC-SHA256 against a
  configured `webhook_secret`, but the API implements no signing: `secret` appears 0× in the
  spec, the subscription endpoint has no field to register one, and real deliveries carry no
  signature header. It could never return `true` — and the README told callers to use it as a
  rejection guard, which dropped every event. Removed rather than left as a trap.
  `WebhookVerifier` → `WebhookEventParser`; `webhookVerifier()` → `webhookEvents()`.
- **`Configuration::$webhookSecret`** and `getWebhookSecret()`, along with the
  `AssinafyClient::create()` parameter — they existed only to feed the verifier. A legacy
  `webhook_secret` key passed to `fromArray()` is accepted and ignored.
- **PHP 7.4 / 8.0 / 8.1 support.** All three are EOL. Minimum is now `^8.2`; CI covers
  8.2–8.5.

### Added

- **`AccountResource`** (`$client->accounts()`) — the `Accounts` tag was entirely
  unimplemented (0 of 9 operations): `list`, `create`, `get`, `update`, `delete`, `theme`,
  `downloadLogo`, `uploadLogo`, `deleteLogo`. `list()` and `create()` are not account-scoped
  and work on a `forAuth()` client, which matters because `GET /accounts` is the only
  documented way to discover the account ID every other resource requires.
- **`DocumentResource::rename()`** — `PATCH /documents/{id}`. The SDK had no `PATCH` verb at
  all, so this was unreachable even through the raw client. Only legal while the document is
  `uploaded`/`metadata_ready`; the API normalises the name (diacritics stripped, max 255).
- **`DocumentResource::search()`** — `GET /accounts/{id}/documents/search`.
- **`AssignmentResource::list()`** — `GET /assignments`. Requires an `accountId` query
  parameter that is **not in the spec** (camelCase; `account-id` and `account_id` are both
  rejected with `400 "Um contexto de conta é necessário"`).
- **`WebhookEventParser::getEventPayload()`** and `getAccountId()` — the envelope's `payload`
  key was unreachable from any helper.
- **`HttpClientInterface::patch()`**; `delete()` accepts an optional JSON body (a few
  endpoints document one).
- `GuzzleHttpClient` accepts an injected `ClientInterface`, making the transport unit-testable
  for the first time (13 new tests via `MockHandler`).
- `.github/dependabot.yml`, and a `composer validate --strict` CI job.

### Fixed

- **Pagination is reachable at last.** The API sends none in the body — there is no `meta` key
  on any endpoint and never was — but `AbstractResource` claimed the envelope was
  `{status, message, data, meta?}` and justified `list()` returning the raw envelope "to keep
  access to `meta`". Real pagination arrives in `X-Pagination-*` response headers, which
  `Response` captured and the resource layer then discarded. `list()` now returns a
  `pagination` key built from those headers. Additive: `data` is untouched.
- **`estimateCost()` accepts signers without IDs.** The docs state IDs "are not required —
  only the verification/notification method affects cost", and the API agrees (verified: HTTP
  200 with a full breakdown), but `normalizeSigners()` threw `ValidationException` before any
  request was made, so the documented "price it before the signers exist" flow was impossible.
- `WebhookEventParser::getEventData()` drops its dead `data`/`type` fallbacks — confirmed
  absent from real deliveries. Behaviour is unchanged (it always fell through to `object`).
  The 1.x unit test asserted on a fabricated `data` key, so the bug tested green.
- PHPStan crashed at PHP's default 128M limit; CI and the Makefile now pass
  `--memory-limit=512M`.

### Changed

- GitHub Actions pinned to immutable commit SHAs with `persist-credentials: false`;
  Dependabot keeps them current.
- Docblocks now carry full request and response payloads. Several were flatly wrong —
  `SignerDocumentResource::list()` advertised `status`/`method` filters the endpoint doesn't
  declare, `TemplateResource::list()` advertised `sort` (declared on exactly one operation in
  the whole spec), and `FieldResource::update()` listed fields the `PUT` doesn't accept.
- README documents where the spec and the live API disagree, so the next audit doesn't have to
  rediscover it.

### Deliberately unchanged

Both of these were flagged as defects by a spec-only reading and **refuted by live testing**.
Recorded here because they look like bugs:

- **`signer-access-code` stays in the request body.** `securitySchemes` declares it
  `in: query`, which implies `acceptTerms()` and `verifyCode()` send the credential where the
  server never reads it — i.e. broken auth. A differential test says otherwise: with
  everything else held constant, no code → `400 "parâmetro … está faltando"`, code in the body
  → `401 "Credenciais inválidas"`. The server found and rejected it, so the body *is* read.
  The live suite now guards this: if it ever returns 400, the server stopped reading the body.
- **`GET /v1/auth/authenticate` and `GET /v1/login-callback` remain unimplemented.** The
  former is documented but returns a framework-level 404 — the route does not exist. The
  latter returns HTML; it is a browser redirect target, not a JSON endpoint.

## [1.4.1] - 2026-06-05

Audit pass against `https://api.assinafy.com.br/v1/docs`, verified end-to-end against the
live sandbox (`https://sandbox.assinafy.com.br/v1`). The docs describe the Template service
as "create, list, download and delete templates", but only the read endpoints were exposed.
Probing the live API confirmed the four management routes exist (a `406`/app-level `404`
response distinguishes a real route from a framework `Página não encontrada` routing miss),
so they are now covered. No functionality was removed — `DELETE /webhooks/subscriptions`
remains absent because the live API returns a routing 404 for it (it does not exist despite
appearing in the docs).

### Added

- **`TemplateResource` management endpoints** — the SDK now covers the full documented
  template surface:
  - `create(string $filePath)` — `POST /accounts/{id}/templates` (multipart PDF upload;
    the template renders asynchronously, poll `get()` until `status` is `Ready`).
  - `update(string $templateId, array $data)` — `PUT /accounts/{id}/templates/{id}`
    (editable `name`, `document_name`, `message`).
  - `delete(string $templateId)` — `DELETE /accounts/{id}/templates/{id}`.
  - `downloadPage(string $templateId, string $pageId)` — `GET /accounts/{id}/templates/{id}/pages/{page_id}/download`
    (raw JPEG body).
- **`DocumentResource::assertUploadable()`** — the document-upload validation (PDF +
  25 MB limit) is now a shared static helper reused by `TemplateResource::create()` (DRY).
- **Live `testTemplateManagementLifecycle`** — create → poll Ready → update → page
  download → delete → confirm-gone, exercised against the sandbox.

### Fixed

- **`TemplateResource` docblocks** no longer claim template creation/editing is web-app
  only — that statement contradicted both the docs and the live API.

## [1.4.0] - 2026-05-27

Complete coverage pass against `https://api.assinafy.com.br/v1/docs`. A full re-read of
the live documentation surfaced several whole resource families the SDK had never exposed;
each new endpoint below was verified end-to-end against the production API before release.

### Added

- **`TagResource`** (`$client->tags()`) — workspace tag management:
  `GET/POST /accounts/{id}/tags`, `PUT/DELETE /accounts/{id}/tags/{tag_id}` (with `force`
  detach-and-delete).
- **`FieldResource`** (`$client->fields()`) — field-definition management and validation:
  `POST/GET /accounts/{id}/fields`, `GET/PUT/DELETE /accounts/{id}/fields/{field_id}`,
  `POST …/fields/{id}/validate`, `POST …/fields/validate-multiple` (both usable as an
  authenticated user or, with a `signer-access-code`, as a signer), and the global
  `GET /field-types` catalog.
- **`SignerDocumentResource`** (`$client->signerDocuments()`) — signer-facing document
  endpoints authenticated by `signer-access-code`: `GET /signers/{id}/document`,
  `GET /signers/{id}/documents`, `PUT /signers/documents/sign-multiple`,
  `PUT /signers/documents/decline-multiple`, and
  `GET /signers/{id}/documents/{id}/download/{artifact_name}`.
- **`DocumentResource` document tags** — `listTags()`, `appendTags()`, `replaceTags()`,
  `detachTag()` covering `GET/POST/PUT /accounts/{id}/documents/{id}/tags` and
  `DELETE …/tags/{tag_id}`.
- **`AssignmentResource::whatsappNotifications()`** — `GET /documents/{id}/assignments/{id}/whatsapp-notifications`.
- **`AssignmentResource` sequential signing** — signer entries now pass through the
  documented `step` field; added `NOTIFICATION_EMAIL` / `NOTIFICATION_WHATSAPP` constants.
- **`SignerSessionResource`** signer-facing signing actions — `currentDocument()`
  (`GET /sign`), `sign()` (`POST /documents/{id}/assignments/{id}`), and `decline()`
  (`PUT /documents/{id}/assignments/{id}/reject`).
- **`WebhookResource`** dispatch + discovery endpoints — `eventTypes()`
  (`GET /webhooks/event-types`), `dispatches()` (`GET /accounts/{id}/webhooks`, paginated
  with `event`/`delivered`/`from`/`to` filters), and `retryDispatch()`
  (`POST /accounts/{id}/webhooks/{dispatch_id}/retry`). Added constants for all 15
  subscribable event types.
- **Query-string parameter** on `HttpClientInterface::delete()` — supports the tag
  `?force=true` flag. Backward-compatible: the new `$query` arg is the third positional
  and defaults to `[]`.
- **4 new live integration tests** covering the tag, field, document-tag, and webhook
  discovery endpoints (all credit-free).

### Changed

- **`WebhookResource::deactivate()`** now calls the dedicated `PUT /accounts/{id}/webhooks/inactivate`
  endpoint (verified live) instead of re-`PUT`ting the subscription with `is_active: false`.
  The server preserves the URL / email / events, so `activate()` still restores them.
- **`DocumentResource::assertArtifact()`** promoted to `public static` so
  `SignerDocumentResource::download()` validates artifact names through the same list (DRY).

[1.4.1]: https://github.com/assinafy/php-sdk/releases/tag/v1.4.1
[1.4.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.4.0

## [1.3.0] - 2026-05-12

Second pass against `https://api.assinafy.com.br/v1/docs` plus a full live verification
against the sandbox. The live run caught two issues the unit suite had missed (see
**Fixed** below — `is_active` and the non-existent `DELETE` for webhooks).

### Added

- **`Configuration::forPublic()`** and **`AssinafyClient::forAuth()`** — build a client for
  the unauthenticated surface of the API without having to fabricate an API key / account
  ID. Use it to call `auth()->login()`, `requestPasswordReset()`, `resetPassword()`,
  `socialLogin()` and the public document endpoints (`verify`, `publicInfo`, `sendToken`).
  Account-scoped resources called on a public client now raise a clear `RuntimeException`
  instead of silently sending a placeholder account ID and getting a 401 from the API.
- **`DocumentResource::SEND_TOKEN_CHANNEL_EMAIL`** constant + allow-list validation on
  `sendToken()` — typos like `'whatsapp'` now raise `ValidationException` up front
  instead of being forwarded blindly.
- **`WebhookResource::deactivate()`** / **`activate()`** — soft toggle the subscription
  via `PUT … {is_active: false|true}`. Replaces the broken `delete()` (see Removed).
- **`WebhookResource::register($url, $email, $events, $isActive = true)`** — new optional
  fourth arg so callers can create an initially-inactive subscription.
- **Query-string parameter** on `HttpClientInterface::post()` / `put()` — lets resources
  send query params alongside a JSON body without manually concatenating into the URI.
  Backward-compatible: existing callers continue to work, the new `$query` arg is the
  fourth positional and defaults to `[]`.
- **`ASSINAFY_BASE_URL`** env-var support in `tests/Integration/LiveApiTest.php` — set it
  to `Configuration::SANDBOX_BASE_URL` to run the integration suite against sandbox.
- **6 new live integration tests** covering thumbnail / page downloads, `verify` with a
  bogus hash, the assignment lifecycle (`estimateCost` → `create` → `estimateResendCost`
  → `resend` → `resetExpiration`), and the webhook activate/deactivate round-trip.
  Templates tests skip cleanly when the sandbox account has no templates.

### Changed

- **`SignerSessionResource::confirmData()`** now passes `signer-access-code` through the
  HTTP client's `$query` channel instead of building the URI by hand with `rawurlencode()`.
  Behavior is identical (still goes on the query string) but it's consistent with the
  rest of the signer-session methods and robust against future endpoint params.
- **`SignerResource::normalizePhone()`** — removed a dead ternary (`($hasPlus ? '+' : '+')`)
  that always evaluated to `'+'`. The normalized output is unchanged.
- **`AbstractResource::extractData()` docblock** clarifies the list-vs-single envelope
  convention; every `list()` method now declares the `{data, meta}` shape it returns.
- **`TemplateResource::get()` docblock** explicitly notes that `GET /accounts/{id}/templates/{id}`
  is part of the v1 API even though it's not currently rendered in the public docs UI.
- **`WebhookResource` class docblock** points to the live integration suite that exercises
  the (undocumented) webhook subscription endpoints on every release.

### Removed

- **`WebhookResource::delete()`** — the underlying `DELETE /accounts/{id}/webhooks/subscriptions`
  route does not exist on the v1 API (verified live: returns 404 *Página não encontrada*).
  The method has never worked. Use `deactivate()` instead — same outcome, supported by
  the API.

### Fixed

- **`WebhookResource::register()` `is_active` field** — verified live against sandbox: the
  API rejects the request with `O atributo "is_active" é obrigatório.` if `is_active` is
  omitted. The field stays in the payload and the new fourth parameter `$isActive` lets
  callers opt out of immediate activation.
- **Auth bootstrap chicken-and-egg** — `Configuration::__construct()` no longer forces
  callers to invent dummy credentials just to reach `auth()->login()`. Use
  `AssinafyClient::forAuth()`.

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

[1.3.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.3.0
[1.2.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.2.0
[1.1.1]: https://github.com/assinafy/php-sdk/releases/tag/v1.1.1
[1.1.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.1.0
[1.0.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.0.0
