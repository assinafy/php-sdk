# Architecture

## Overview

The Assinafy PHP SDK is a small, synchronous client for the Assinafy v1 API. It uses a facade over focused resource classes, a project-specific HTTP abstraction, PSR-3 logging, strict PHP types, and explicit exception mapping.

The package targets PHP 8.2 through 8.5, the complete supported CI matrix. The authoritative endpoint mapping, including known differences between the published OpenAPI document and sandbox behavior, is in [docs/API_REFERENCE.md](docs/API_REFERENCE.md).

## Directory structure

```text
assinafy-php-sdk/
├── src/
│   ├── AssinafyClient.php
│   ├── Configuration.php
│   ├── Exceptions/
│   │   ├── ApiException.php
│   │   ├── AssinafyException.php
│   │   ├── NetworkException.php
│   │   └── ValidationException.php
│   ├── Http/
│   │   ├── GuzzleHttpClient.php
│   │   ├── HttpClientInterface.php
│   │   ├── LogRedactor.php
│   │   └── Response.php
│   ├── Resources/
│   │   ├── AbstractResource.php
│   │   ├── AccountResource.php
│   │   ├── AssignmentResource.php
│   │   ├── AuthResource.php
│   │   ├── DocumentResource.php
│   │   ├── FieldResource.php
│   │   ├── SignerDocumentResource.php
│   │   ├── SignerResource.php
│   │   ├── SignerSessionResource.php
│   │   ├── TagResource.php
│   │   ├── TemplateResource.php
│   │   ├── UserResource.php
│   │   └── WebhookResource.php
│   └── Support/
│       ├── MutableLogger.php
│       └── WebhookEventParser.php
├── tests/
│   ├── Unit/
│   └── Integration/
├── docs/
│   ├── API_REFERENCE.md
│   ├── EXAMPLES.md
│   ├── INSTALLATION.md
│   ├── index.php
│   └── quickstart.php
├── composer.json
├── phpstan.neon
├── phpcs.xml
└── phpunit.xml
```

## Components

### Client facade

`AssinafyClient` owns the configuration, transport, and logger proxy. Resource accessors are lazy and return the same resource instance for the lifetime of the client.

```php
use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;

$apiKey = getenv('ASSINAFY_API_KEY');
$accountId = getenv('ASSINAFY_ACCOUNT_ID');
if (!is_string($apiKey) || $apiKey === '' || !is_string($accountId) || $accountId === '') {
    throw new RuntimeException('Assinafy sandbox credentials are not configured.');
}

$client = AssinafyClient::create(
    apiKey: $apiKey,
    accountId: $accountId,
    baseUrl: Configuration::SANDBOX_BASE_URL,
);

$documents = $client->documents();
$user = $client->users()->get();
```

`AssinafyClient::forAuth()` creates a public client for login, password-reset, OAuth URL helpers,
and public document operations. It sends neither `X-Api-Key` nor `Authorization`; protected
bootstrap methods must receive the login token explicitly. Once an account ID is known,
`AssinafyClient::forBearer()` configures `Authorization: Bearer ...` once for every workspace
resource. Account-scoped methods reject public configuration instead of sending placeholder
credentials.

The high-level `uploadAndRequestSignatures()` workflow validates every signer description before uploading, uploads a PDF, optionally waits for document readiness, resolves or creates signers, and creates a virtual assignment.

### Resource layer

Each resource extends `AbstractResource`, which centralizes account paths, path-segment validation, pagination normalization, Bearer headers, signer access-code queries, and response data extraction.

| Accessor | Responsibility |
|---|---|
| `accounts()` | Account discovery and management, branding, and account statistics |
| `assignments()` | Signature requests, cost estimates, resend operations, expiration resets, and WhatsApp history |
| `auth()` | Login, social login, OAuth URL builders, password flows, and API-key lifecycle |
| `documents()` | Document upload, retrieval, search, downloads, tags, verification, and template-driven creation |
| `fields()` | Field definitions, validation, and the global field-type catalog |
| `signers()` | Workspace signer CRUD, email lookup, and explicit-country-code E.164 normalization |
| `signerSession()` | End-signer identity, verification, data confirmation, signature image, signing, and decline operations |
| `signerDocuments()` | End-signer document lookup, search, bulk actions, and downloads |
| `tags()` | Account tag CRUD |
| `templates()` | Template upload, polling, retrieval, update, deletion, and page downloads |
| `users()` | Authenticated user profile and cross-account statistics |
| `webhooks()` | Subscription configuration, event types, delivery history, and retry |

Workspace resources use either the configured `X-Api-Key` or the global Bearer header supplied by
`forBearer()`. Selected bootstrap methods can override configured authentication with an explicit
Bearer token; a null token falls back to the client configuration. Signer-facing resources put
the access code in the `signer-access-code` query parameter, matching the OpenAPI security scheme,
and keep it separate from workspace authentication.

### HTTP layer

`HttpClientInterface` is the SDK's own transport contract. It is intentionally small and is not a claim that the SDK accepts any arbitrary HTTP client implementation directly. `GuzzleHttpClient` is the default adapter, backed by the required Guzzle runtime dependency, and implements JSON requests, multipart uploads, raw image uploads, binary downloads, timeouts, logging, and exception translation. Nullable `post()`/`put()`/`patch()` data distinguishes no body (`null`) from an explicit JSON array (`[]`).

`Response` stores the status, headers, raw body, and parsed JSON body. Most resource methods unwrap the API's `data` field; paginated methods retain the response envelope and add a normalized `pagination` entry derived from `X-Pagination-*` headers.

`LogRedactor` can sanitize request options, JSON bodies, query-string credentials, Bearer tokens,
API keys, signer codes, verification codes, and password fields. The default transport logs only
payload-free request summaries and response metadata; it does not log request or response bodies.

### Configuration

`Configuration` validates mutually exclusive API-key/Bearer credentials, account ID, base URL,
and positive timeout values. Remote base URLs require HTTPS; plain HTTP is restricted to
loopback development hosts. It exposes production and sandbox base URL constants:

```php
Configuration::DEFAULT_BASE_URL; // production
Configuration::SANDBOX_BASE_URL; // sandbox
```

Configuration is read-only after construction. `Configuration::forPublic()` uses internal
sentinel values that are never emitted as authentication headers. `Configuration::forBearer()`
stores an access token and emits `Authorization` instead of `X-Api-Key` for all workspace calls.

### Logging

The SDK accepts any PSR-3 `LoggerInterface`; otherwise it uses `NullLogger`. `MutableLogger` is an internal proxy shared by the default transport and lazily created resources. Calling `AssinafyClient::setLogger()` updates that proxy, so resources obtained before the logger change also use the new logger.

```php
use Psr\Log\LoggerInterface;

/** @var LoggerInterface $logger */
$client->setLogger($logger);
```

Applications should still avoid logging their own raw request bodies or webhook payloads. `LogRedactor` protects logging performed through the SDK's default transport, not unrelated application logging.

### Webhook parsing

The API contract has no webhook secret registration field or signature header. The SDK therefore does not expose an HMAC verification API. `WebhookEventParser` only decodes the delivery envelope and reads its type, entity data, event-specific payload, and account ID.

Webhook bodies must be treated as untrusted input. A handler should use HTTPS, apply network controls where possible, return promptly, make processing idempotent, and re-fetch the referenced entity through an authenticated resource before acting.

### Exceptions

```text
AssinafyException
├── ApiException
├── NetworkException
└── ValidationException
```

- `ApiException` represents non-success API responses and retains the HTTP status and parsed response data.
- `NetworkException` represents connection, DNS, TLS, and timeout failures.
- `ValidationException` represents SDK validation failures that use structured validation errors.
- Some local precondition failures intentionally use `InvalidArgumentException` or `RuntimeException`, as documented per method.

## Object and request flow

An ordinary resource request follows this path:

```text
Application
  -> AssinafyClient resource accessor
  -> resource validation and request shaping
  -> HttpClientInterface
  -> GuzzleHttpClient
  -> Assinafy v1 API
  -> Response
  -> resource envelope/data normalization
  -> application
```

The upload-and-assign helper composes existing public methods rather than duplicating their HTTP logic:

```text
uploadAndRequestSignatures()
  -> validate signer descriptions
  -> documents()->upload()
  -> documents()->waitUntilReady() when enabled
  -> signers()->findByEmail() or signers()->create()
  -> assignments()->create()
```

## Design principles

- **Single responsibility:** configuration, transport, response parsing, redaction, resources, and event parsing are separate concerns.
- **Dependency inversion:** resources depend on `HttpClientInterface` and `LoggerInterface`, not concrete logging packages.
- **Open for adapters:** applications can supply a custom implementation of `HttpClientInterface` and any PSR-3 logger.
- **KISS:** resource methods mirror one API operation or a clearly documented local composite helper.
- **DRY:** shared account paths, authentication queries, pagination, response extraction, validation, and logging propagation are centralized.

## Testing strategy

Unit tests use `tests/Unit/Support/FakeHttpClient.php`, a recording implementation of `HttpClientInterface`. It queues deterministic responses and records method, URI, query, body, and headers. This keeps unit tests offline and verifies request contracts directly.

Integration tests are opt-in and target the sandbox. Credentials are supplied only through environment variables; they are never committed to documentation or source code.

```bash
composer test
composer phpstan
composer phpcs

# Explicitly opt in to sandbox tests after setting secret environment variables.
ASSINAFY_INTEGRATION=1 composer test:integration
```

## Security boundaries

- Production and sandbox defaults use HTTPS.
- Custom remote base URLs require HTTPS; HTTP is restricted to loopback development hosts.
- Secrets belong in environment variables or a secret manager, not in code or committed fixtures.
- Signer access codes are query credentials and must be handled like passwords.
- SDK transport logs pass through `LogRedactor`.
- Incoming webhook data is parsed, not authenticated; re-fetch authoritative state before side effects.
- Public clients cannot call account-scoped paths accidentally.

## Compatibility and change control

The public package targets PHP `^8.2`, uses PSR-4 autoloading and PSR-3 logging, and supports Guzzle 7 and 8 through its own transport abstraction. Endpoint behavior must stay aligned with the official v1 contract and the documented runtime divergences. New public methods should include request and return PHPDoc, unit request-shape tests, API-reference mapping, and sandbox coverage when the operation can be exercised safely.
