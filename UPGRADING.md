# Upgrading

## 1.x → 2.0.0

Most code needs no changes. The breaking changes are concentrated in three areas: the PHP
version floor, webhook handling, and the `Configuration` constructor.

### PHP 8.2 is now the minimum

1.x allowed `^7.4|^8.0`. PHP 7.4, 8.0 and 8.1 are all end-of-life and receive no security
patches, so 2.0.0 requires `^8.2`. The CI matrix covers 8.2 through 8.5.

No action needed beyond running a supported PHP version.

### Webhook signature verification was removed

**This is the change most likely to affect you, and it is a bug fix rather than a loss of
functionality.**

1.x shipped `WebhookVerifier::verify()`, which computed an HMAC-SHA256 over the request body
using a configured `webhook_secret`. The Assinafy API implements no such mechanism:

- `secret` appears nowhere in the published OpenAPI spec.
- `PUT /accounts/{id}/webhooks/subscriptions` accepts only `events`, `is_active`, `url` and
  `email` — there is no field in which to register a signing key.
- Real deliveries carry no signature header.

`verify()` therefore could never return `true` for a genuine delivery, yet the 1.x README and
examples told you to use it as a rejection guard:

```php
// 1.x — this dropped EVERY webhook.
if (!$verifier->verify($payload, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}
```

If you copied that snippet, your endpoint has been rejecting all events. If you wired it up
and "webhooks never worked", this is why.

**Migration:**

| 1.x | 2.0.0 |
|---|---|
| `$client->webhookVerifier()` | `$client->webhookEvents()` |
| `Support\WebhookVerifier` | `Support\WebhookEventParser` |
| `$verifier->verify($payload, $sig)` | *(removed — delete the check)* |
| `$verifier->extractEvent($payload)` | unchanged |
| `$verifier->getEventType($event)` | unchanged |
| `$verifier->getEventData($event)` | unchanged — returns the `object` entity |
| *(none)* | `$parser->getEventPayload($event)` — the `payload` key, previously unreachable |
| *(none)* | `$parser->getAccountId($event)` |

```php
// 2.0.0
$parser = $client->webhookEvents();
$event  = $parser->extractEvent(file_get_contents('php://input'));

if ($event === null) {
    http_response_code(400);
    exit('Malformed payload');
}

// Re-fetch rather than trusting the payload — see README "Securing your webhook endpoint".
$document = $client->documents()->get($parser->getEventData($event)['id']);
http_response_code(200);
```

`getEventData()` previously read `$event['data'] ?? $event['object'] ?? []`. Real deliveries
have no `data` key, so it always fell through to `object` — behaviour is unchanged; only the
dead branch is gone.

### `Configuration`: `$webhookSecret` removed

The fourth constructor argument is gone, along with `getWebhookSecret()`. It existed solely to
feed the removed verifier.

```php
// 1.x
new Configuration($apiKey, $accountId, $baseUrl, $webhookSecret, $timeout, $connectTimeout);
new Configuration('k', 'a', $url, null, 30, 10);

// 2.0.0
new Configuration($apiKey, $accountId, $baseUrl, $timeout, $connectTimeout);
new Configuration('k', 'a', $url, 30, 10);
```

`AssinafyClient::create()` likewise drops its `$webhookSecret` parameter.

If you passed positionally, `$timeout` now sits where `$webhookSecret` did — a `string` in an
`int` slot raises a `TypeError` immediately rather than misbehaving silently.

`Configuration::fromArray()` still **accepts and ignores** a `webhook_secret` key, so config
arrays carried over from 1.x keep working:

```php
Configuration::fromArray([
    'api_key'        => '…',
    'account_id'     => '…',
    'webhook_secret' => '…',  // ignored, no error
]);
```

### `HttpClientInterface` gained methods

Only relevant if you implement the interface yourself (the shipped `GuzzleHttpClient` is
unaffected):

- **Added** `patch(string $uri, array $data = [], array $headers = [], array $query = []): Response`
- **Changed** `delete()` now takes a fourth `array $data = []` for a JSON body. Existing
  implementations keep compiling if you add the parameter; ignore it unless you call
  `accounts()->delete(force: true)`.

### `list()` now returns a `pagination` key

Additive — `data` is untouched, so existing code keeps working.

The API sends no pagination in the body; there is no `meta` key on any endpoint and never
was. Pagination arrives in `X-Pagination-*` response headers, which 1.x captured and then
discarded, so callers could not paginate deterministically. 2.0.0 lifts them into the result:

```php
$result = $client->documents()->list(1, 20);
$result['data'];                       // unchanged
$result['pagination']['total_count'];  // new
$result['pagination']['page_count'];   // new
```

If you read `$page['meta']` in 1.x you were reading a key that never existed and getting
`null`. Use `pagination` instead. Endpoints that don't paginate omit the key — check with
`isset()`.

### `estimateCost()` no longer requires signer IDs

A bug fix; nothing to change unless you worked around it.

The API prices an assignment off the verification and notification methods alone, and the
docs say IDs "are not required" — but 1.x threw `ValidationException('Signer entry missing
id')` before issuing any request, making the documented flow unreachable:

```php
// Throws in 1.x, works in 2.0.0.
$client->assignments()->estimateCost($documentId, [
    ['verification_method' => 'Email', 'notification_methods' => ['Email']],
]);
```

`create()` still requires IDs — it has to know who signs.

### Credentials are redacted from logs

No API change, but worth knowing if you parse your own logs.

1.x logged the whole request options array at debug level, writing plaintext passwords
(`login()`, `generateApiKey()`, `changePassword()`, `resetPassword()`), `Authorization`
bearer tokens, and response `access_token`s into your log files. 2.0.0 replaces those values
with `[redacted]`. Rotate any credential that may have been captured in existing logs.

### New: `$client->accounts()`

The `Accounts` tag was entirely unimplemented in 1.x. `accounts()->list()` is the documented
way to discover the account ID that every other resource needs, and works on a `forAuth()`
client. See the README.
