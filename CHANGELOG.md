# Changelog

All notable changes to the Assinafy PHP SDK are documented here.
Versions follow [Semantic Versioning](https://semver.org/).

## 2.2.0 - 2026-09-20

- Add `AssinafyClient::oauth()` and `OAuthResource`, covering the marketplace authorization-code
  flow end to end: PKCE material, the authorization URL, callback validation, token exchange,
  refresh, revocation, OpenID Connect userinfo, and both discovery documents.
- Mint a fresh PKCE verifier and `state` per connection attempt, and verify the callback's `state`
  and `iss` before an authorization code is used.
- Return OAuth and OpenID Connect bodies as flat JSON; token and revocation errors expose the RFC
  error code as the exception message and `error_description` in the response data.
- Document the verification and notification matrix, including ICP-Brasil A1 and A3 certificates
  under `DigitalCertificate`, with per-signer credit costs.
- Set the SDK User-Agent version to `2.2.0`.

## 2.1.4 - 2026-09-18

- Prevent transport exception chains from retaining Guzzle requests when PHP records exception arguments.
- Reject injected Guzzle clients with default HTTP authentication, keeping public and signer requests credential-scoped.
- Keep workspace credentials off OAuth token and revocation requests; redact authorization codes and PKCE verifiers.
- Expand request/response examples and the complete document workflow, including marketplace OAuth integration.
- Document current sandbox statistics, notification preferences, public document responses, and template prerequisites.
- Use `has_sufficient_credits` and the notification-cost breakdown in resend examples.
- Exclude local agent configuration, environment files, and installed dependencies from source archives and Docker build contexts.

## 2.1.3 - 2026-08-27

- Publish `assinafy/php-sdk` on Packagist for standard Composer installation.
- Set the SDK User-Agent version to `2.1.3`.

## 2.1.2 - 2026-08-27

- Expand public method documentation with request and response examples.
- Document template signing-role setup, webhook payloads, and environment-specific availability.
- Preserve exception types and messages for assignment expiration validation.

## 2.1.1 - 2026-08-21

- Enforce the versioned SDK User-Agent on every request.
- Validate injected Guzzle base URIs and reject default credential headers.
- Restrict transport paths to the configured API base URI.
- Reject malformed JSON envelopes and pagination headers.
- Validate PDF headers, end markers, readability, and the 25 MB upload limit.
- Redact credentials in diagnostic object dumps and transport logs.

## 2.1.0 - 2026-08-14

- Add authenticated-user notification preferences.
- Accept DigitalCertificate verification in assignment and template requests and estimates.
- Add the `pades` download artifact and digital-certificate statistics.
- Allow signer `government_id` updates and terms confirmation.
- Validate ordinary assignment notification coupling and sequential signing steps.

## 2.0.0 - 2026-08-06

- Require PHP 8.2 or later and support Guzzle 7 and 8 as runtime dependencies.
- Add account discovery, workspace management, branding, and account/user statistics.
- Add global Bearer authentication with `Configuration::forBearer()` and `AssinafyClient::forBearer()`.
- Add signer document search and template readiness polling.
- Add pagination metadata from `X-Pagination-*` response headers.
- Allow assignment estimates without signer IDs and include account context in assignment listing.
- Require explicit international phone prefixes and validate timeouts and HTTPS base URLs.
- Keep credentials off public and signer routes, disable redirects, and redact transport diagnostics.
- Replace `WebhookVerifier` with `WebhookEventParser` for unsigned webhook delivery payloads.
- Remove the `webhookSecret` constructor argument; legacy configuration-array keys remain ignored.
- Add PATCH support and nullable request bodies to the transport interface.
- Preserve template management, explicit empty notification lists, and public send-token request shapes.

## 1.4.1 - 2026-06-05

- Add template upload, update, deletion, and rendered-page download.
- Share PDF upload validation between document and template uploads.

## 1.4.0 - 2026-05-27

- Add workspace tags, field definitions, field validation, and field-type discovery.
- Add document tag list, append, replace, and detach methods.
- Add signer document listing, downloads, and bulk sign/decline operations.
- Add sequential signing steps and WhatsApp notification history.
- Add signer current-document, sign, and decline methods.
- Add webhook event discovery, delivery history, and retry.
- Use the dedicated webhook inactivation endpoint while preserving subscription settings.
- Add query parameters to transport DELETE requests.

## 1.3.0 - 2026-05-12

- Add public clients through `Configuration::forPublic()` and `AssinafyClient::forAuth()`.
- Add webhook activation/deactivation and optional initial activation state.
- Include the required `is_active` subscription field.
- Replace `webhooks()->delete()` with `deactivate()`.
- Validate the email channel for public document access-token delivery.
- Add query parameters to POST and PUT transport requests.

## 1.2.0 - 2026-05-11

- Add password/social authentication, API-key management, and password reset/change methods.
- Add signer identity, terms, verification, confirmation, and signature-image methods.
- Add document deletion, thumbnails, page downloads, activities, statuses, and public access methods.
- Add artifact, status, verification, and assignment-method constants.
- Send assignment signers as objects and pagination using `per-page`.
- Apply the 25 MB upload limit and poll the API's document lifecycle states.
- Resolve paths beneath `/v1/` and preserve multipart boundaries.
- Add raw-body uploads to the transport interface.
- Reuse signers by email in the upload-and-request-signatures workflow.
- Replace unsupported assignment cancellation/resend routes with supported lifecycle methods.
- Return native API identifiers through `id`.

## 1.1.1 - 2026-05-06

- Send signer phone numbers in `whatsapp_phone_number` and read the same response field.

## 1.1.0 - 2026-05-06

- Add template listing/detail, document creation from templates, and template cost estimation.
- Add public document verification, assignment cost estimates, resend, and expiration reset.
- Add signer update and deletion.

## 1.0.0 - 2024-12-22

- Introduce the framework-independent SDK with PSR-4 autoloading, PSR-3 logging, and an injectable transport.
- Provide document upload/download, signer management, assignment workflows, exceptions, and webhook helpers.
- Include Composer installation, examples, and a Docker development environment.
