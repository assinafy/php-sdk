# Architecture Documentation

## Overview

The Assinafy PHP SDK follows clean architecture principles with clear separation of concerns, dependency inversion, and adherence to SOLID principles.

## Directory Structure

```
assinafy-php-sdk/
├── src/
│   ├── AssinafyClient.php          # Main SDK entry point
│   ├── Configuration.php            # Configuration object
│   ├── Exceptions/                  # Exception hierarchy
│   │   ├── AssinafyException.php
│   │   ├── ApiException.php
│   │   ├── ValidationException.php
│   │   └── NetworkException.php
│   ├── Http/                        # HTTP abstraction layer
│   │   ├── HttpClientInterface.php
│   │   ├── GuzzleHttpClient.php
│   │   └── Response.php
│   ├── Resources/                   # API resource classes
│   │   ├── AbstractResource.php
│   │   ├── DocumentResource.php
│   │   ├── SignerResource.php
│   │   ├── AssignmentResource.php
│   │   └── WebhookResource.php
│   └── Support/                     # Helper classes
│       └── WebhookVerifier.php
├── docs/                            # Documentation
│   ├── index.php
│   ├── EXAMPLES.md
│   └── INSTALLATION.md
├── docker/                          # Docker setup
│   ├── Dockerfile
│   └── nginx/
│       └── default.conf
├── composer.json
├── docker-compose.yml
├── README.md
├── MIGRATION.md
└── ARCHITECTURE.md
```

## Layers

### 1. Presentation Layer (Client)

**AssinafyClient** is the main entry point for users:

```php
$client = AssinafyClient::create($apiKey, $accountId);
$client->documents()->upload(...);
```

**Responsibilities:**
- Facade for all SDK functionality
- Factory for resource classes
- Dependency injection management

### 2. Resource Layer

Resource classes encapsulate domain logic for each API resource:

- **DocumentResource**: Document operations
- **SignerResource**: Signer management
- **AssignmentResource**: Signature requests
- **WebhookResource**: Webhook management

**Pattern**: Each resource extends `AbstractResource` and follows the same structure.

### 3. HTTP Layer

Abstraction over HTTP communication:

**HttpClientInterface**:
- Defines contract for HTTP operations
- Allows swapping implementations

**GuzzleHttpClient**:
- Default implementation using Guzzle
- Handles request/response transformation
- Implements error handling

**Response**:
- Value object for HTTP responses
- Automatic JSON parsing
- Status checking helpers

### 4. Configuration Layer

**Configuration** class:
- Immutable configuration object
- Validation of required parameters
- Default value management
- Factory methods for various input formats

### 5. Exception Layer

Hierarchical exception structure:

```
AssinafyException (base)
├── ApiException (HTTP errors)
├── ValidationException (validation errors)
└── NetworkException (network errors)
```

### 6. Support Layer

Helper classes that don't fit other layers:

- **WebhookVerifier**: HMAC signature verification

## Design Patterns

### 1. Facade Pattern

`AssinafyClient` provides a simplified interface:

```php
$client->uploadAndRequestSignatures(...);
```

Instead of:
```php
$document = $client->documents()->upload(...);
$client->documents()->waitUntilReady($documentId);
$signer = $client->signers()->create(...);
$assignment = $client->assignments()->create(...);
```

### 2. Factory Pattern

Multiple factory methods for flexibility:

```php
AssinafyClient::create($apiKey, $accountId);
AssinafyClient::fromArray($config);
new AssinafyClient($config, $httpClient, $logger);
```

### 3. Strategy Pattern

HTTP client is pluggable:

```php
interface HttpClientInterface {
    public function get(string $uri, ...): Response;
    public function post(string $uri, ...): Response;
}

class GuzzleHttpClient implements HttpClientInterface { }
class CustomHttpClient implements HttpClientInterface { }
```

### 4. Template Method Pattern

`AbstractResource` defines common behavior:

```php
abstract class AbstractResource
{
    protected function extractData(array $response): array { }
    protected function normalizeId(array $data): array { }
}
```

### 5. Dependency Injection

All dependencies injected via constructor:

```php
public function __construct(
    HttpClientInterface $httpClient,
    Configuration $config,
    ?LoggerInterface $logger = null
)
```

## SOLID Principles

### Single Responsibility Principle

Each class has one reason to change:
- `Configuration`: Manages configuration
- `DocumentResource`: Document operations
- `GuzzleHttpClient`: HTTP communication
- `WebhookVerifier`: Signature verification

### Open/Closed Principle

Open for extension, closed for modification:
- Add new HTTP clients via interface
- Add new loggers via PSR-3
- Extend resources without changing core

### Liskov Substitution Principle

Any `HttpClientInterface` can replace `GuzzleHttpClient`:

```php
$client = new AssinafyClient($config, new CustomHttpClient());
```

### Interface Segregation Principle

Small, focused interfaces:
- `HttpClientInterface`: Only HTTP methods
- `LoggerInterface` (PSR-3): Only logging methods

### Dependency Inversion Principle

Depend on abstractions:
- Resources depend on `HttpClientInterface`, not Guzzle
- Client depends on `LoggerInterface`, not Monolog

## Data Flow

### Upload and Request Signatures

```
User Code
    ↓
AssinafyClient::uploadAndRequestSignatures()
    ↓
DocumentResource::upload()
    ↓
HttpClientInterface::uploadFile()
    ↓
GuzzleHttpClient::request()
    ↓
Guzzle HTTP Client
    ↓
Assinafy API
```

### Webhook Verification

```
Webhook Request
    ↓
User Webhook Handler
    ↓
WebhookVerifier::verify()
    ↓
HMAC Comparison
    ↓
Event Processing
```

## Error Handling Strategy

### Exception Hierarchy

```php
try {
    $document = $client->documents()->upload(...);
} catch (ValidationException $e) {
    $errors = $e->getErrors();
} catch (ApiException $e) {
    $statusCode = $e->getStatusCode();
    $responseData = $e->getResponseData();
} catch (NetworkException $e) {
    echo "Network error: {$e->getMessage()}";
} catch (AssinafyException $e) {
    $context = $e->getContext();
}
```

### HTTP Error Mapping

- 400-499: `ApiException` (client errors)
- 500-599: `ApiException` (server errors)
- Network failures: `NetworkException`
- Invalid input: `ValidationException`

## Extensibility Points

### 1. Custom HTTP Client

```php
class MyHttpClient implements HttpClientInterface
{
    public function get(string $uri, array $params = [], array $headers = []): Response
    {
    }
}

$client = new AssinafyClient($config, new MyHttpClient());
```

### 2. Custom Logger

```php
use Psr\Log\LoggerInterface;

class MyLogger implements LoggerInterface
{
}

$client->setLogger(new MyLogger());
```

### 3. Custom Resource

```php
class CustomResource extends AbstractResource
{
    public function myCustomMethod(): array
    {
        $response = $this->httpClient->get('custom-endpoint');
        return $response->getData();
    }
}
```

### 4. Middleware Pattern (Future)

```php
$client->addMiddleware(new RateLimitMiddleware());
$client->addMiddleware(new RetryMiddleware());
```

## Testing Strategy

### Unit Tests

Test each class in isolation:

```php
class DocumentResourceTest extends TestCase
{
    public function testUpload()
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $config = new Configuration('key', 'account');
        
        $resource = new DocumentResource($httpClient, $config);
    }
}
```

### Integration Tests

Test with real API:

```php
$client = AssinafyClient::create($_ENV['API_KEY'], $_ENV['ACCOUNT_ID']);
$document = $client->documents()->upload('test.pdf', 'Test.pdf');
```

### Mock HTTP Client

```php
$mockClient = new MockHttpClient([
    new Response(200, [], '{"data": {...}}'),
]);

$client = new AssinafyClient($config, $mockClient);
```

## Performance Considerations

### 1. Lazy Loading

Resources are created on-demand:

```php
public function documents(): DocumentResource
{
    if ($this->documents === null) {
        $this->documents = new DocumentResource(...);
    }
    return $this->documents;
}
```

### 2. Connection Pooling

Guzzle maintains connection pool automatically.

### 3. Timeout Configuration

```php
new Configuration(
    apiKey: '...',
    accountId: '...',
    timeout: 30,
    connectTimeout: 10
);
```

## Security

### 1. Webhook Signature Verification

```php
$verifier = $client->webhookVerifier();
if (!$verifier->verify($payload, $signature)) {
    exit('Invalid signature');
}
```

### 2. HTTPS Only

Default base URL uses HTTPS.

### 3. No Credential Logging

Sensitive data never logged.

## Future Enhancements

### 1. Async Support

```php
$promise = $client->documents()->uploadAsync(...);
```

### 2. Retry Middleware

```php
$client->withRetry(maxAttempts: 3, backoff: 'exponential');
```

### 3. Caching Layer

```php
$client->withCache($cachePool, ttl: 3600);
```

### 4. Batch Operations

```php
$client->batch()
    ->uploadDocument(...)
    ->createSigner(...)
    ->execute();
```

## Conclusion

The SDK architecture is:
- **Modular**: Clear separation of concerns
- **Extensible**: Easy to add new features
- **Testable**: All dependencies injectable
- **Maintainable**: Follows industry standards
- **Type-Safe**: Full PHP 8.1+ type coverage
- **Framework-Agnostic**: Works anywhere

