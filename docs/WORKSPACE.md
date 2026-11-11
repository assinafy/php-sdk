# Workspace settings, push alerts and webhook delivery

These methods use the configured account ID and credential. All examples show PHP arrays and SDK
returns with the wire `data` envelope removed. Errors use `ApiException`; local malformed inputs
use `ValidationException`. No method retries mutations automatically.

## Workspace features and usage

`accounts()->get(?string $expand = null): array` sends `GET /accounts/{accountId}`. With
`get('signing_features')`, the query is `['expand' => 'signing_features']`; there is no body.
API key/user-session authentication or OAuth `account:read` is required. The expanded response is:

```php
[
    'resource' => 'account', 'id' => 'account-id', 'name' => 'Example workspace',
    'primary_color' => null, 'secondary_color' => null, 'notification_sender_type' => 'User',
    'roles' => ['owner'], 'is_delete_allowed' => true,
    'limits' => ['webhook_endpoints' => 3],
    'signing_features' => [
        'DigitalCertificate' => true, 'VerificationIdDocumentFaceMatchBasic' => true,
        'WhatsappNotification' => true, 'VerificationBypass' => false, 'Embed' => true,
    ],
    'created_at' => '2026-11-04T12:05:00Z',
]
```

The flags describe the current subscription, not a guarantee that a particular assignment can be
created: credits, signer identity, assignment rules and later plan changes still apply.

`accounts()->usage(): array` sends `GET /accounts/{accountId}/usage` without query or body.
It requires an API key or user session. Third-party OAuth applications receive `403` regardless
of scopes; this is billing information. The response preserves unlimited balances as `null`:

```php
[
    'plan' => 'Pro', 'documents_renewal_date' => '2026-12-01',
    'balances' => [
        'documents' => ['balance' => 100, 'limit' => 100, 'spent' => 0],
        'credits' => ['balance' => 20, 'limit' => null, 'spent' => 0],
    ],
]
```

`plan` and `documents_renewal_date` can also be `null`; no prices or payment details are returned.

## Allowed embed origins

All four methods require an API key or user session and the workspace's Embed feature.
OAuth applications cannot administer origins. Missing plan support produces `403 PlanRestricted`.

| Method | HTTP request | Request body | SDK return |
| --- | --- | --- | --- |
| `embedOrigins(): array` | `GET /accounts/{accountId}/embed-origins` | None | List of origin objects, oldest first |
| `addEmbedOrigin(string $origin): array` | `POST /accounts/{accountId}/embed-origins` | `['origin' => 'https://app.example.com']` | Created origin object |
| `updateEmbedOrigin(string $originId, string $origin): array` | `PUT /accounts/{accountId}/embed-origins/{originId}` | `['origin' => 'https://new.example.com']` | Updated origin object, same ID |
| `deleteEmbedOrigin(string $originId): array` | `DELETE /accounts/{accountId}/embed-origins/{originId}` | None | `[]` |

Call these on `$client->accounts()`. The complete object returned by creation or update, and
contained in each list entry, is:

```php
[
    'id' => 'origin-id', 'origin' => 'https://app.example.com',
    'created_at' => '2026-11-04T12:05:00Z',
]
```

An origin is `scheme://host[:port]`: HTTPS, or HTTP for `localhost` and `127.0.0.1` only.
Paths, queries, fragments, credentials and wildcards are forbidden. The API lowercases the host,
removes a trailing slash and default port, and rejects duplicates or an eleventh origin with `400`.
An unknown origin ID produces `404`. Removing the last origin makes new embed sessions fail with
`409 embed_origins_missing`.

## Personal push preferences

`users()->pushPreferences(?string $accessToken = null): array` sends
`GET /users/self/push-preferences` without query or body. It returns:

```php
[
    'SignerViewed' => true, 'SignerSigned' => true, 'DocumentCompleted' => true,
    'SignerDeclined' => true, 'DocumentAboutToExpire' => true, 'DocumentExpired' => true,
    'SignerIdentityReviewRequired' => true, 'SignerWhatsappFailed' => true,
]
```

`users()->updatePushPreferences(array $preferences, ?string $accessToken = null): array`
sends `PUT /users/self/push-preferences`. For example, `['SignerViewed' => false]` changes just
that key; the response is the complete map above with `SignerViewed` false. At least one boolean
entry is required. New server keys are forwarded; the server rejects unknown codes with `400`.
`UserResource::PUSH_PREFERENCE_CODES` lists the documented keys for interface rendering.

These are personal settings across all of the user's accounts, independent of email preferences.
API key/user-session authentication is required; an explicit user access token can be passed.
Third-party OAuth applications receive `403`, regardless of scopes. Every key defaults to true.
The email for an identity review is always sent even when its push alert is disabled.

## Webhook secret overlap and test delivery

`webhooks()->rotateEndpointSecret(string $endpointId, bool $keepPrevious = false): string`
preserves the string secret return. `rotateEndpointSecretWithMetadata()` accepts the same
arguments and returns the complete data object. Both send
`POST /accounts/{accountId}/webhooks/endpoints/{endpointId}/secret/rotate`.
They require API key/user-session authentication or OAuth `webhooks:write`, and signing must
already be enabled (`400` otherwise). An unknown endpoint produces `404`.

The default sends no body and immediately invalidates the old secret. Passing `true` sends:

```php
['keep_previous' => true]
```

The metadata method returns:

```php
[
    'secret' => 'whsec_example',
    'previous_secret_expires_at' => '2026-11-12T15:00:00Z',
]
```

The expiry exists only with overlap enabled. Deliveries carry signatures from both keys for
24 hours. Persist the replacement immediately; another rotation replaces the previous retained
key. `WebhookEventParser::verifySignature()` accepts a matching signature from either entry.

`webhooks()->testEndpoint(string $endpointId): array` sends
`POST /accounts/{accountId}/webhooks/endpoints/{endpointId}/test` without query or body.
It requires API key/user-session authentication or OAuth `webhooks:write`. It sends one test to
that endpoint even when inactive or held, without lifting or counting toward a hold. The endpoint
has 10 seconds to answer. At most 10 tests per user per 10 minutes are allowed (`429` thereafter).
It returns a delivery-history entry, for example:

```php
[
    'resource' => 'activity_dispatching_history', 'id' => 'dispatch-id',
    'event' => 'webhook_test', 'activity_id' => 456, 'endpoint_id' => 'endpoint-id',
    'endpoint' => 'https://example.com/webhook', 'payload' => null,
    'delivered' => true, 'webhook_id' => 'message-id', 'attempt' => 1, 'kind' => 'manual',
    'will_retry' => false, 'http_status' => 200, 'response_body' => 'OK', 'error' => null,
    'created_at' => '2026-11-11T15:00:00Z', 'updated_at' => '2026-11-11T15:00:00Z',
]
```

`WebhookResource::EVENT_WEBHOOK_TEST` identifies test deliveries; it is absent from the event
catalog and cannot be subscribed to. The delivered event has null `payload` and `origin`; its
subject and object describe the endpoint without the secret.

## Related contract documents

`contracts()->related(string $documentId, ?string $expand = null): array` accepts
`related('document-id', 'document')` to send `GET
/accounts/{accountId}/documents/{documentId}/contract/related` with query
`['expand' => 'document']` and no body. API key/user-session authentication or OAuth
`documents:read` is required. The document must belong to a contract family (`404` otherwise).
The result is the complete root contract described in [API_REFERENCE.md](API_REFERENCE.md),
including `amendments`, plus the root `document` object from `documents()->get()` when expanded.
Each amendment already includes its `document_name`. The original call without expansion is unchanged.

## Workspace notification policy

`accounts()->notificationPolicy(): array` sends `GET
/accounts/{accountId}/notification-policy` without query or body. Every member can read it.
`accounts()->updateNotificationPolicy(array $changes): array` sends `PUT` on the same path and
requires the owner role (`403` for other members). Both require API-key/user-session credentials;
third-party OAuth applications cannot access these personal/workspace notification settings.

The update merges the supplied fields, for example:

```php
['events' => ['SignerViewed' => false], 'owners_receive_all' => true]
```

Both methods return the complete policy:

```php
[
    'editable' => true, 'owners_receive_all' => true,
    'events' => [
        'DocumentCompleted' => true, 'SignerDeclined' => true, 'DocumentCancelled' => true,
        'DocumentAboutToExpire' => true, 'DocumentExpired' => true, 'DocumentExpirationReset' => true,
        'DocumentProcessingFailed' => true, 'TemplateProcessingFailed' => true,
        'SignerWhatsappFailed' => true, 'ContractRenewalNotice' => true, 'ContractExpiring' => true,
        'ContractExpired' => true, 'ContractRenewed' => true, 'SignerViewed' => false,
        'SignerSigned' => true, 'SignerIdentityReviewRequired' => true,
    ],
]
```

`editable` describes the requesting user's right to edit, not an editable field. Setting an event
false suppresses it for everyone in the workspace on every channel, regardless of personal
preferences. Identity-review email is always sent so a signer can continue; its flag suppresses
only push. `owners_receive_all` adds push alerts for all members' documents to owners, whose
individual preferences still apply. Unknown fields/codes, non-booleans and empty requests fail
without changes. Future event names are forwarded to the API for validation.
