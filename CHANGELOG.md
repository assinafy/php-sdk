# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Installation is now a plain `composer require assinafy/php-sdk`.** The package is published
  on Packagist, so the VCS/path-repository workaround the documentation carried is no longer
  needed. Projects that added a `repositories` entry to install an earlier release should remove
  it so Composer resolves from Packagist. Documentation only; the package itself is unchanged
  from 2.1.2.

## [2.1.2] - 2026-08-27

Documentation and internal-consistency release. No public API, request shape, or response
handling changed; the transport behaves exactly as in 2.1.1.

### Added

- **Request and response payloads on every public method.** All 107 public methods across the
  resource classes and `WebhookEventParser` now document the exact request body or query
  parameters they send and the response shape they return, with concrete examples, so an IDE
  shows the contract at the call site. Previously six methods carried payload examples.
  Notable details now written down:
  - `AuthResource::generateApiKey()` is the only call that returns the API key in full;
    `getApiKey()` returns it masked to the last four characters.
  - `DocumentResource::verify()` answers `200` with `is_valid => false` for an unknown hash
    rather than `404`, so callers must branch on the field and not the status.
  - `DocumentResource::activities()` returns newest-first, with an integer `id` and a
    nullable `origin`.
  - `FieldResource::validate()` and `validateMultiple()` report a failed value as `200` with
    `success => false`, not as an error status.
  - `FieldResource::types()` repeats `email` in the live list; de-duplicate on `type` before
    rendering a picker.
  - `WebhookResource::get()` returns `null`, not `[]`, when no subscription exists.
- **README navigation and a "Sandbox and production differences" section.** `accounts()->stats()`,
  `users()->stats()`, and `users()->notificationPreferences()` are served on production but not
  by the sandbox, which answers a framework `404`. The section records how to tell a missing
  route from a missing resource by the error body, and notes that an unauthenticated request
  makes the same distinction because routing resolves before authentication.
- **Missing changelog entries for 2.1.0 and 2.1.1**, which shipped as tags without being
  recorded here.

### Changed

- **ISO 8601 expiry validation lives in one place.** `AssinafyClient::validateExpiration()` and
  `AssignmentResource::assertDateTime()` carried byte-identical copies of the same pattern,
  UTC-offset range check, and `DateTimeImmutable` round-trip. Both now call
  `Support\Iso8601::reasonInvalid()`, which returns the reason so each caller keeps raising its
  own exception type — `\InvalidArgumentException` and `ValidationException` respectively — with
  the same messages as before.

### Fixed

- **Changelog link references** were split across two blocks, one stranded mid-file between the
  1.4.0 and 1.3.0 sections. They are now consolidated at the end, with the 2.1.x releases added.

## [2.1.1] - 2026-08-21

Transport hardening. No resource method signatures changed.

### Added

- **Response envelope validation.** A `2xx` HTTP response whose body carries a non-2xx `status`
  is now raised as an `ApiException` for that status instead of being returned as success, and a
  `status` that is not an integer in `100`–`599` raises a `NetworkException`. A `data` key that
  is present but neither `null` nor an array is likewise rejected rather than passed through.
- **Guards on injected Guzzle clients.** A client supplied to `GuzzleHttpClient` may not define
  default `Authorization` or `X-Api-Key` headers, and its `base_uri` must match the configured
  API base URL.
- **`__debugInfo()` on `Configuration` and `GuzzleHttpClient`** so `var_dump()` and exception
  dumps report the authentication mode and header names rather than credential values.

### Changed

- **Request URIs must be relative to the configured base URL.** Absolute URLs, leading slashes,
  and `..` traversal segments are rejected before the request is built.
- **`LogRedactor::summarizeRequestOptions()`** logs the structure of a request — query keys,
  header names, JSON keys, body size — instead of its values, so the default transport never
  writes a payload to the log.

## [2.1.0] - 2026-08-14

### Added

- **`UserResource::notificationPreferences()` and `updateNotificationPreferences()`**
  (`GET`/`PUT /users/self/notification-preferences`) covering the nine owner-facing document
  email preferences. The update is a merge: omitted keys keep their current value, and the full
  map is returned. Codes are validated locally against
  `UserResource::NOTIFICATION_PREFERENCE_CODES`.

### Fixed

- **Public and signer-facing routes no longer inherit workspace credentials.** Requests to the
  unauthenticated bootstrap, verification, and signer-session endpoints now omit `X-Api-Key` and
  `Authorization` even when issued from a client configured with workspace credentials, so a
  workspace key is not presented to a route that does not expect one. Explicit per-request
  headers are left untouched.

## [2.0.0] - 2026-08-06

This release is a full audit against the current Assinafy API reference and
running sandbox. The machine-readable OpenAPI document fetched on 2026-08-05 contains 89
operations on 68 paths. Coverage is 89/89 operations: the two browser-facing OAuth operations
are URL builders, while every JSON, multipart, binary, and signer operation has a resource
method. Five additional live template-management routes are retained under regression coverage
even though they are absent from OpenAPI.

The specification and running API still disagree in a few places. The release documentation
records those differences explicitly so a future spec-only audit does not remove working
functionality or reintroduce an invalid request shape.

See [UPGRADING.md](UPGRADING.md) for migration steps.

### Security

- **Credentials no longer leak into logs.** `GuzzleHttpClient::request()` logged the entire
  request options array at debug level, writing plaintext passwords (`login()`,
  `generateApiKey()`, `changePassword()`, `resetPassword()`), `Authorization` bearer tokens,
  and response `access_token`s to wherever the host application ships its logs. A new
  `Http\LogRedactor` masks them; a regression test asserts a real `login()` call leaks
  nothing. **Rotate any credential that may have been captured in existing logs.**
- **Dependency advisories cleared.** Guzzle is now a runtime dependency at
  `^7.15.2 || ^8.0.2` rather than a development-only suggestion. The lowest/highest dependency
  CI jobs exercise both supported major lines, including their different exception hierarchies.
  `ext-mbstring` is also declared because validation uses multibyte-safe string operations.
- **Remote base URLs require HTTPS.** Plain HTTP is accepted only for loopback development
  hosts (`localhost`/`*.localhost`, `127.0.0.1`, and `::1`); credentials, queries, and fragments
  are rejected in base URLs.

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
  unimplemented in 1.x: `list`, `create`, `get`, `update`, `delete`, `theme`, `stats`,
  `downloadLogo`, `uploadLogo`, and `deleteLogo`. `list()` and `create()` are not
  account-scoped. A bootstrap client must pass the login Bearer token to those methods, or use
  a globally Bearer-authenticated client; `forAuth()` alone intentionally sends no credentials.
- **`DocumentResource::rename()`** — `PATCH /documents/{id}`. The SDK had no `PATCH` verb at
  all, so this was unreachable even through the raw client. Only legal while the document is
  `uploaded`/`metadata_ready`; the API normalises the name (diacritics stripped, max 255).
- **`DocumentResource::search()`** — `GET /accounts/{id}/documents/search`.
- **`AssignmentResource::list()`** — `GET /assignments`. Requires an `accountId` query
  parameter that is **not in the spec** (camelCase; `account-id` and `account_id` are both
  rejected with `400 "Um contexto de conta é necessário"`).
- **`WebhookEventParser::getEventPayload()`** and `getAccountId()` — the envelope's `payload`
  key was unreachable from any helper.
- **`HttpClientInterface::patch()`**; `post()`, `put()`, and `patch()` now accept nullable
  data so `null` sends no request body, while an explicit array sends JSON. `delete()` accepts
  optional query parameters and an optional JSON body.
- `GuzzleHttpClient` accepts an injected `ClientInterface`, making the transport unit-testable
  through a comprehensive `MockHandler` suite across both supported Guzzle majors.
- `.github/dependabot.yml`, and a `composer validate --strict` CI job.
- **Global Bearer authentication.** `Configuration::forBearer()` and
  `AssinafyClient::forBearer()` apply `Authorization: Bearer ...` to every workspace resource.
  API-key lifecycle and password-change methods accept nullable per-call tokens so `null` uses
  the configured API-key or global-Bearer authentication.
- **OAuth browser helpers.** `AuthResource::socialLoginUrl()` and
  `socialLoginCallbackUrl()` represent the two non-JSON browser operations without asking the
  server-side JSON transport to follow redirects or parse HTML.
- **`SignerResource::normalizePhoneNumber()`** is now a public shared E.164 normalizer.
  Signer create/update require an explicit leading `+` and country code, accept common visual
  separators, and validate 8–15 digits instead of guessing a country for local input.

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
- **Assignment notification channels are independent of verification.** OpenAPI permits an
  empty array, Email, WhatsApp, or both regardless of `verification_method`; the SDK no longer
  rejects those valid combinations or truncates them to one channel.
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

### Published/runtime divergences retained

- **Signer authentication uses the `signer-access-code` query parameter.** The SDK follows
  the current OpenAPI security scheme consistently. `acceptTerms()` sends that query parameter
  with no request body; `verifyCode()` sends only the verification code in JSON.
- **Signer-access-code acquisition is inbox-driven.** Assignment `signing_urls` contain
  `signer_id` and `url`, but do not expose the one-time access code. Deriving a code from a URL
  path segment produced `401` in the sandbox. `sendToken()` delivers the code to the assigned
  signer's inbox; authenticated signer-read integration checks are therefore separately opt-in
  through `ASSINAFY_SIGNER_ID` and `ASSINAFY_SIGNER_ACCESS_CODE`, and are not reported as live
  successes when those credentials are absent.
- **Public send-token keeps the working runtime body.** OpenAPI currently shows `{email}`,
  while the sandbox requires `{recipient, channel}` and rejects a recipient who is not already
  a signer assigned to the target document. The SDK sends the runtime body shape; live tests use
  an assigned signer.
- **Authenticated-user responses are normalized.** OpenAPI declares `GET /users/self` as
  `data: AuthUser`, while the sandbox returns `data: {user: AuthUser, accounts: AuthAccount[]}`.
  `UserResource::get()` returns `data.user` for the sandbox shape and still accepts the published
  shape, keeping its `AuthUser` return contract stable.
- **Published statistics methods are retained despite a sandbox deployment gap.** Both
  `GET /accounts/{accountId}/stats` and `GET /users/self/stats` returned an application-level
  `404` route-not-deployed response in the sandbox on 2026-08-05. Their SDK methods remain
  because both operations are published and count toward 89/89 coverage; they are not presented
  as currently runnable sandbox functionality.
- **Document-tag operations are published; five template-management operations are not.**
  Document `listTags()`, `replaceTags()`, `appendTags()`, and `detachTag()` map directly to
  current OpenAPI operations. The body description calls its strings tag IDs, but the sandbox
  and SDK use tag names and auto-create missing names. Template create/get/update/delete and page
  download remain available because the live API supports them and regression tests cover them.
- **OAuth start and callback are browser operations, not missing SDK functionality.** The SDK
  returns their absolute URLs. The current start route produces the documented redirect; the
  callback returns browser content rather than a JSON resource.

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
- SDK-specific injectable HTTP client interface (it is not PSR-18)
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

[Unreleased]: https://github.com/assinafy/php-sdk/compare/v2.1.2...HEAD
[2.1.2]: https://github.com/assinafy/php-sdk/compare/v2.1.1...v2.1.2
[2.1.1]: https://github.com/assinafy/php-sdk/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/assinafy/php-sdk/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/assinafy/php-sdk/compare/v1.4.1...v2.0.0
[1.4.1]: https://github.com/assinafy/php-sdk/releases/tag/v1.4.1
[1.4.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.4.0
[1.3.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.3.0
[1.2.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.2.0
[1.1.1]: https://github.com/assinafy/php-sdk/releases/tag/v1.1.1
[1.1.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.1.0
[1.0.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.0.0
