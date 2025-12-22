# Installation Guide

## Requirements

- PHP 8.1 or higher
- Composer
- ext-json

## Installation via Composer

```bash
composer require assinafy/php-sdk
```

### Installing with Guzzle HTTP Client

The SDK requires an HTTP client. We recommend Guzzle:

```bash
composer require assinafy/php-sdk guzzlehttp/guzzle
```

### Installing with Logging Support

For logging capabilities, install Monolog:

```bash
composer require assinafy/php-sdk guzzlehttp/guzzle monolog/monolog
```

## Docker Setup

The SDK includes a Docker Compose environment for development and testing.

### Starting the Environment

```bash
docker-compose up -d
```

This will start:
- PHP 8.3 FPM container
- MySQL 8.0 database (host: mysql, user: root, password: root)
- Nginx web server on port 8080

### Installing Dependencies

```bash
docker-compose exec php composer install
```

### Running Tests

```bash
docker-compose exec php vendor/bin/phpunit
```

### Stopping the Environment

```bash
docker-compose down
```

## Manual Installation

If you prefer not to use Composer, you can clone the repository:

```bash
git clone https://github.com/your-org/assinafy-php-sdk.git
cd assinafy-php-sdk
composer install
```

## Configuration

### Environment Variables

Create a `.env` file in your project root:

```env
ASSINAFY_API_KEY=your-api-key
ASSINAFY_ACCOUNT_ID=your-account-id
ASSINAFY_WEBHOOK_SECRET=your-webhook-secret
ASSINAFY_BASE_URL=https://api.assinafy.com.br/v1
```

### Laravel Configuration

Add to `config/services.php`:

```php
'assinafy' => [
    'api_key' => env('ASSINAFY_API_KEY'),
    'account_id' => env('ASSINAFY_ACCOUNT_ID'),
    'webhook_secret' => env('ASSINAFY_WEBHOOK_SECRET'),
    'base_url' => env('ASSINAFY_BASE_URL', 'https://api.assinafy.com.br/v1'),
],
```

### Symfony Configuration

Add to `config/packages/assinafy.yaml`:

```yaml
parameters:
    assinafy.api_key: '%env(ASSINAFY_API_KEY)%'
    assinafy.account_id: '%env(ASSINAFY_ACCOUNT_ID)%'
    assinafy.webhook_secret: '%env(ASSINAFY_WEBHOOK_SECRET)%'
    assinafy.base_url: '%env(ASSINAFY_BASE_URL)%'
```

## Verification

Test your installation:

```php
<?php

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;

$client = AssinafyClient::create(
    apiKey: $_ENV['ASSINAFY_API_KEY'],
    accountId: $_ENV['ASSINAFY_ACCOUNT_ID']
);

try {
    $documents = $client->documents()->list(page: 1, perPage: 5);
    echo "Connection successful! Found " . count($documents['data'] ?? []) . " documents.\n";
} catch (\Exception $e) {
    echo "Connection failed: {$e->getMessage()}\n";
}
```

## Troubleshooting

### "Class not found" errors

Make sure Composer autoloading is working:

```bash
composer dump-autoload
```

### HTTP client not found

Install Guzzle:

```bash
composer require guzzlehttp/guzzle
```

### SSL/TLS errors

Make sure your PHP installation has up-to-date CA certificates:

```bash
php -r "print_r(openssl_get_cert_locations());"
```

### API authentication errors

Verify your credentials:
1. Check that your API key is correct
2. Verify your account ID
3. Ensure your account is active in Assinafy dashboard

## Next Steps

- Read the [README](../README.md) for usage examples
- Check [EXAMPLES.md](EXAMPLES.md) for detailed code samples
- Review the API documentation at https://api.assinafy.com.br/v1/docs

