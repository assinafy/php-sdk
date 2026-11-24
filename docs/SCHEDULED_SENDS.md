# Scheduled sends

Schedule an assignment or envelope, inspect its state, cancel it, or send it immediately.
These routes are published by the [staging API](https://api.angry-pisco.stage.assinafy.com.br/v1/docs)
and require deployment support at the selected API origin. Every operation requires workspace
credentials; marketplace OAuth needs `documents:write`, including listing.

## Schedule an assignment

`assignments()->schedule(string $documentId, array $signers, string|int $sendAt,
string $method = 'virtual', array $options = []): array` sends
`POST /v1/documents/{documentId}/scheduled-sends`. The body uses the ordinary
[assignment fields](API_REFERENCE.md#assignments-assignmentresource), plus `send_at`:

```php
$scheduled = $client->assignments()->schedule(
    'document-id',
    [['id' => 'signer-id', 'verification_method' => 'Email',
      'notification_methods' => ['Email'], 'sign_as' => 'Witness']],
    '2027-01-02T12:00:00Z',
    options: ['message' => 'Please sign', 'expires_at' => '2027-01-09T12:00:00Z'],
);
// JSON request, expressed as a PHP array:
$request = [
    'method' => 'virtual',
    'signers' => [['id' => 'signer-id', 'verification_method' => 'Email',
        'notification_methods' => ['Email'], 'sign_as' => 'Witness']],
    'message' => 'Please sign',
    'expires_at' => '2027-01-09T12:00:00Z',
    'send_at' => '2027-01-02T12:00:00Z',
];
```

Use existing workspace signers. Collect assignments require the usual page entries. The document
must be eligible for an assignment. Scheduling stores the request; it creates no assignment,
sends no invitation and consumes no credits yet. The SDK preserves ordinary assignment validation.

`send_at` accepts an ISO 8601 date-time or Unix seconds, between one hour and 90 days ahead.
A date-time without an offset uses UTC. `expires_at`, when present, accepts ISO 8601 or Unix
seconds and must be at least two hours after `send_at`; null means no expiration. The server
checks time windows against its clock. Always choose dates relative to your intended send time.

The SDK unwraps `data` and returns the full scheduled-send record:

```php
[
    'resource' => 'scheduled_send',
    'id' => 'send-id',
    'type' => 'assignment',
    'document_ids' => ['document-id'],
    'status' => 'pending',
    'send_at' => '2027-01-02T12:00:00Z',
    'assignment_id' => null,
    'envelope_id' => null,
    'failure_reason' => null,
    'created_at' => '2027-01-01T12:00:00Z',
    'processed_at' => null,
]
```

## Schedule an envelope

`envelopes()->schedule(array $data, string|int $sendAt): array` sends
`POST /v1/accounts/{accountId}/scheduled-sends`. Supply the same data as
[`envelopes()->create()`](ENVELOPES.md), including the feature, document and signer preconditions.
The SDK adds `send_at`. The schedule endpoint does not accept an idempotency key.

```php
$data = [
    'name' => 'Agreement',
    'signers' => [['id' => 'signer-id']],
    'documents' => [
        ['id' => 'document-one', 'signer_ids' => ['signer-id']],
        ['id' => 'document-two', 'signer_ids' => ['signer-id']],
    ],
    'max_total_credits' => 10,
];
$scheduled = $client->envelopes()->schedule($data, '2027-01-02T12:00:00Z');
// Wire body: $data plus ['send_at' => '2027-01-02T12:00:00Z'].
```

The full response has the same fields shown above, with `type => 'envelope'` and both IDs in
`document_ids`. Both result IDs remain null until processing; a successful envelope send sets
`envelope_id` and leaves `assignment_id` null. Each document expiration must satisfy the same
minimum two-hour interval after the send time.

## List, cancel and send now

| Method | Endpoint | Request | Return |
|---|---|---|---|
| `scheduledSends()->list(int $page = 1, int $perPage = 20, ?string $documentId = null): array` | `GET /v1/accounts/{accountId}/scheduled-sends` | Query `['page' => 1, 'per-page' => 20, 'document_id' => 'document-id']`; omit the last key when null. No body. | Envelope below, newest first, all states. |
| `scheduledSends()->cancel(string $scheduledSendId): array` | `PUT /v1/accounts/{accountId}/scheduled-sends/{scheduledSendId}/cancel` | No body/query. | Full scheduled-send record with `status => 'cancelled'` and `processed_at` set. |
| `scheduledSends()->sendNow(string $scheduledSendId): array` | `PUT /v1/accounts/{accountId}/scheduled-sends/{scheduledSendId}/send` | No body/query. | Full record with `status => 'sent'`, result ID and `processed_at` set. |

```php
[
    'status' => 200,
    'message' => '',
    'data' => [$scheduled], // Complete records with the fields above.
    'pagination' => ['current_page' => 1, 'page_count' => 1, 'per_page' => 20, 'total_count' => 1],
]
```

Both actions require `pending`; otherwise they return 409. Cancellation does not send or charge.
Sending now performs the actual assignment/envelope creation and charging as the member who
scheduled it. A refused immediate send leaves the schedule pending: cancel it explicitly to stop
the later attempt. Never automatically retry a mutation after an ambiguous timeout.

The background queue checks due sends about every five minutes. It rechecks member permissions,
document eligibility, subscription, current costs, available credits and any `max_total_credits`
ceiling. State progresses from `pending` through `processing` to `sent` or `failed`; cancellation
ends at `cancelled`. A background failure records `failure_reason` and notifies the scheduling
member and workspace owner. Inspect these fields rather than assuming the invitation was sent.
