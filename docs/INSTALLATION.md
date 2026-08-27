# Installation Guide

## Requirements

- PHP 8.2, 8.3, 8.4, or 8.5
- Composer 2
- The PHP `json` and `mbstring` extensions
- TLS trust roots (CA certificates) suitable for HTTPS API requests

PHP uses annually supported release branches rather than an LTS designation. The
SDK tests every currently supported branch; use PHP 8.5 for new deployments.

The SDK includes Guzzle as its default HTTP transport. Applications do not need to
install a separate HTTP client.

## Install with Composer

Version 2.1.1 is available as repository tag `v2.1.1`, but Packagist does not currently expose
`assinafy/php-sdk`. After the package is published there, install it with:

```bash
composer require assinafy/php-sdk:^2.1
```

Until that publication is complete, Composer can install the stable `v2.1.1` tag directly from
the public GitHub mirror:

```bash
composer config repositories.assinafy vcs https://github.com/assinafy/php-sdk.git
composer require assinafy/php-sdk:2.1.1
```

The documentation on the repository's current `main` describes the `v2.1.1` release. Use the
documentation shipped with a tag when installing that tag.

Optional PSR-3 logging integrations, such as Monolog, can be installed separately:

```bash
composer require monolog/monolog
```

If developing the SDK itself, clone the public mirror and install development
dependencies:

```bash
git clone https://github.com/assinafy/php-sdk.git
cd php-sdk
composer install
```

## Configure credentials

Never commit API keys. Supply credentials with your deployment platform's secret
manager or environment configuration:

```bash
export ASSINAFY_API_KEY='your-api-key'
export ASSINAFY_ACCOUNT_ID='your-account-id'
```

The SDK does not load `.env` files itself. Framework environment helpers or a
package such as `vlucas/phpdotenv` may be used by the host application.

Production is the default API target:

```php
<?php

use Assinafy\SDK\AssinafyClient;

$apiKey = getenv('ASSINAFY_API_KEY');
$accountId = getenv('ASSINAFY_ACCOUNT_ID');

if (!is_string($apiKey) || $apiKey === '' || !is_string($accountId) || $accountId === '') {
    throw new RuntimeException('Assinafy credentials are not configured');
}

$client = AssinafyClient::create(
    apiKey: $apiKey,
    accountId: $accountId,
);
```

Select the sandbox explicitly for development and live integration tests:

```php
use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;

$client = AssinafyClient::create(
    apiKey: $apiKey,
    accountId: $accountId,
    baseUrl: Configuration::SANDBOX_BASE_URL,
);
```

The base URLs are:

- Production: `https://api.assinafy.com.br/v1`
- Sandbox: `https://sandbox.assinafy.com.br/v1`

Custom remote base URLs must use HTTPS. Plain HTTP is accepted only for local loopback
development (`localhost`, `*.localhost`, `127.0.0.1`, or `::1`, with an optional port). A base URL
must be absolute and include a host, and it cannot contain embedded credentials, a query string,
or a fragment. Do not disable TLS verification to work around certificate errors.

## Framework configuration

In Laravel, credentials can be exposed through `config/services.php` and injected
where the client is constructed:

```php
'assinafy' => [
    'api_key' => env('ASSINAFY_API_KEY'),
    'account_id' => env('ASSINAFY_ACCOUNT_ID'),
    'base_url' => env('ASSINAFY_BASE_URL', 'https://api.assinafy.com.br/v1'),
],
```

In Symfony, declare environment-backed parameters or service arguments:

```yaml
parameters:
    assinafy.api_key: '%env(ASSINAFY_API_KEY)%'
    assinafy.account_id: '%env(ASSINAFY_ACCOUNT_ID)%'
    assinafy.base_url: '%env(default:assinafy_default_base_url:ASSINAFY_BASE_URL)%'
    assinafy_default_base_url: 'https://api.assinafy.com.br/v1'
```

## Verify the installation

Constructing the client verifies that autoloading, credentials, and the default
transport are available without making an API request:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;

$client = AssinafyClient::create(
    apiKey: (string) getenv('ASSINAFY_API_KEY'),
    accountId: (string) getenv('ASSINAFY_ACCOUNT_ID'),
    baseUrl: Configuration::SANDBOX_BASE_URL,
);

echo "Assinafy SDK configured for {$client->getConfig()->getBaseUrl()}\n";
```

## Docker development environment

The included Compose environment runs PHP 8.5 FPM and Nginx:

```bash
export ASSINAFY_DOCKER_UID="$(id -u)"
export ASSINAFY_DOCKER_GID="$(id -g)"
docker compose up -d --build
docker compose exec php composer install
docker compose exec php vendor/bin/phpunit --testsuite=unit
docker compose down
```

The UID/GID mapping keeps bind-mounted Composer files owned by the current user on native Linux;
it defaults to `1000:1000`. The web service listens on `http://localhost:8080`. The SDK has no
database dependency.

## Live sandbox tests

Live tests create and modify sandbox resources and can consume sandbox credits.
Run them only with dedicated sandbox credentials and force the sandbox URL:

```bash
read -rs ASSINAFY_API_KEY
export ASSINAFY_API_KEY
export ASSINAFY_ACCOUNT_ID='sandbox-account-id'
export ASSINAFY_BASE_URL='https://sandbox.assinafy.com.br/v1'
export ASSINAFY_INTEGRATION=1
vendor/bin/phpunit --testsuite=integration
```

GitHub Actions provides the manual **Sandbox integration** workflow. Create the `sandbox`
environment before the first dispatch, require reviewers, prevent self-review, restrict allowed
deployment refs, and configure these environment-scoped secrets:

- `ASSINAFY_API_KEY`
- `ASSINAFY_ACCOUNT_ID`
- `ASSINAFY_SANDBOX_TEST_EMAIL` (required only for notification tests)
- `ASSINAFY_SANDBOX_TEST_EMAIL_ALT` (required only for notification tests)
- `ASSINAFY_SANDBOX_SIGNER_ID` (optional; set together with the access code)
- `ASSINAFY_SANDBOX_SIGNER_ACCESS_CODE` (optional; enables signer-read checks)

Do not rely on the workflow to create the environment: GitHub auto-created environments have no
protection rules or secrets. The workflow hard-codes and verifies the sandbox hostname;
credentials are never stored in the repository. Notification, shared-state, and disposable-account
deletion tests are disabled by default and require explicit workflow-dispatch options. In GitLab,
set `RUN_ASSINAFY_NOTIFICATION_TESTS=1`, `RUN_ASSINAFY_STATEFUL_TESTS=1`, or
`RUN_ASSINAFY_DESTRUCTIVE_TESTS=1` when starting the protected sandbox job.

## Troubleshooting

### Missing PHP extension

Confirm the required runtime extensions and dependency platform requirements:

```bash
php -m | grep -E 'json|mbstring'
composer check-platform-reqs
```

### Autoloading errors

Regenerate Composer's optimized autoloader:

```bash
composer dump-autoload --optimize
```

### TLS errors

Check PHP's configured CA locations and update the operating system CA bundle:

```bash
php -r 'print_r(openssl_get_cert_locations());'
```

Do not disable TLS certificate verification.

### Authentication errors

Verify that the API key belongs to the target environment, the account ID is
correct, the account is active, and the base URL is the intended production or
sandbox endpoint. Do not log credential values while diagnosing authentication.

## Next steps

- Read the [README](../README.md) for usage examples.
- See [EXAMPLES.md](EXAMPLES.md) for complete workflows.
- Consult the [Assinafy API documentation](https://api.assinafy.com.br/v1/docs).
