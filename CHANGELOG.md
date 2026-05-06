# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.1.1]: https://github.com/assinafy/php-sdk/releases/tag/v1.1.1
[1.1.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.1.0
[1.0.0]: https://github.com/assinafy/php-sdk/releases/tag/v1.0.0
