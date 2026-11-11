# Document envelopes

`$client->envelopes()` manages several documents sent together. All paths below are relative to
`/v1/accounts/{accountId}`. Use a workspace API key or a Bearer token authorized for that workspace.
OAuth reads require `documents:read`, writes require `documents:write`. Creation, estimation and
adding a document also require the Envelopes plan feature; unavailable features return `403`.

An envelope has no status of its own: `counts` groups its documents by status. Every document keeps
its assignment, certificate and downloads. Upload PDFs and wait for `metadata_ready` before adding
them; they must have no assignment. Maximum: 20 documents, 20 signers, 2,000 fields and 100 copy
receivers. Every signer must appear on at least one document. Envelope signing is parallel: do not
send `step`. A certificate signer must be alone on each of their documents, which must be `virtual`.
Biometric verification is shared across that signer's documents in the envelope.

## Create and estimate

`create(array $data, ?string $idempotencyKey = null): array` sends `POST /envelopes`.
`estimateCost(array $data): array` sends `POST /envelopes/estimate-cost` with the same body,
creating and charging nothing. The SDK normalizes nested list indexes to preserve JSON arrays.

```php
$request = [
    'name' => 'Service agreement',
    'message' => 'Please review the agreement and attachment.',
    'signers' => [
        ['id' => 'signer-id', 'verification_method' => 'Email',
            'notification_methods' => ['Email'], 'embedded' => false],
    ],
    'documents' => [
        ['id' => 'agreement-id', 'method' => 'virtual', 'signer_ids' => ['signer-id'],
            'expires_at' => '2027-12-31T23:59:59Z', 'copy_receiver_ids' => ['copy-signer-id']],
        ['id' => 'attachment-id', 'method' => 'virtual', 'signer_ids' => ['signer-id']],
    ],
];
$estimate = $client->envelopes()->estimateCost($request);
if (!$estimate['has_sufficient_resources']) {
    throw new RuntimeException($estimate['blocking_reason'] ?? 'Insufficient resources');
}
$request['max_total_credits'] = $estimate['total_credits'];
$envelope = $client->envelopes()->create($request, 'unique-request-id');
```

Example estimate response:

```php
[
    'documents' => 1, 'credits' => 0, 'needs_extra_document' => false,
    'extra_document_cost' => 0, 'total_credits' => 0, 'breakdown' => [],
    'document_balance' => 10, 'credit_balance' => 20,
    'has_sufficient_resources' => true, 'blocking_reason' => null, 'message' => null,
];
```

`max_total_credits` caps the spend: a higher current cost returns `409 CreditCeilingExceeded`,
without creating or charging anything. Estimates ignore the ceiling. One envelope consumes one
plan document; notifications and biometric verification are charged once per signer, digital
certificate signatures once per document signed. Use the estimate's current price.

Persist a fresh `Idempotency-Key` for each intended envelope. Reuse it after an ambiguous failure:
the server returns the original envelope without charging again, regardless of the new body.
An in-flight request returns `409 IdempotencyKeyInUse`. Keys belong to the workspace and do not
expire. Deleting the envelope removes the result that key would replay. The SDK does not retry.

Creation returns `200` when the envelope exists; that does not prove every invitation arrived.
Inspect each assignment's `notification_history`. Each signer receives one invitation listing
their documents. `embedded: true` uses an embed session and returns a null signing URL; see
[embedded signing](EMBEDDED_SIGNING.md).

A `collect` document replaces its virtual fields with explicit placements:

```php
$collect = [
    'id' => 'document-id', 'method' => 'collect', 'signer_ids' => ['signer-id'],
    'entries' => [[
        'page_id' => 'page-id',
        'fields' => [[
            'signer_id' => 'signer-id', 'field_id' => 'field-id',
            'display_settings' => ['left' => 120, 'top' => 400, 'width' => 180, 'height' => 32],
        ]],
    ]],
];
```

The fields must belong to this document's signers; every listed signer needs a field. Coordinates
are page-image pixels at 150 DPI. `expires_at` accepts ISO 8601 text, Unix seconds or null;
o offset means UTC and a date alone ends at 23:59 UTC. A nonempty expiration must be at least
one hour ahead. Null, zero, empty text or omission means no expiration.

## Envelope response

`create`, `get`, `update`, `addDocument` and `removeDocument` return this unwrapped shape.
The example shows one document; `documents` includes every current document in position order.

```php
[
    'resource' => 'envelope', 'id' => 'envelope-id', 'name' => 'Service agreement',
    'message' => 'Please review the agreement and attachment.',
    'documents' => [[
        'id' => 'agreement-id', 'position' => 1, 'name' => 'agreement.pdf',
        'status' => 'pending_signature',
        'artifacts' => [
            'original' => 'https://api.assinafy.com.br/v1/documents/agreement-id/download/original',
            'thumbnail' => 'https://api.assinafy.com.br/v1/documents/agreement-id/thumbnail',
        ],
        'signers' => [[
            'id' => 'signer-id', 'full_name' => 'Example Signer', 'email' => 'signer@example.com',
            'whatsapp_phone_number' => null, 'completed' => false, 'signed_at' => null,
        ]],
    ]],
    'counts' => ['pending_signature' => 1],
    'signing_urls' => [[
        'signer_id' => 'signer-id',
        'url' => 'https://app.assinafy.com.br/sign/signing-token',
    ]],
    'created_at' => '2026-11-01T12:00:00Z',
]
```

`counts` is null when no document remains. List results omit the single-resource `resource` and
include only thumbnail artifacts, whose URLs may return 404 until generation finishes.
Certified documents expose their available certified artifacts; PAdES appears only when available.
Signing links carry credentials; do not log them.

## Read, update and activity

`get(string $envelopeId): array`: `GET /envelopes/{envelopeId}`, no query/body, returns the
Envelope response above. The workspace must own the envelope; missing resources return 404.

`list(int $page = 1, int $perPage = 20, array $filters = []): array`:
`GET /envelopes`, query `['page' => 1, 'per-page' => 20, 'search' => 'agreement', 'status' => 'open']`.
`status` is `open` or `closed`; omit either filter to include all matches. Newest first. Response:

```php
[
    'status' => 200, 'message' => '',
    'data' => [$envelope], // The complete envelope response above, without resource; thumbnail artifacts only.
    'pagination' => ['current_page' => 1, 'page_count' => 1, 'per_page' => 20, 'total_count' => 1],
]
```

`update(string $envelopeId, array $data): array`: `PATCH /envelopes/{envelopeId}`.
Request `['name' => 'Updated agreement', 'message' => null, 'document_ids' => ['attachment-id', 'agreement-id']]`.
Send only changed properties. `document_ids` must list every current document exactly once.
Name: 1–255 characters, no `<`. Message may be null. Open and closed envelopes accept updates.
No notice is sent; subsequent notices use the updated values. Returns the Envelope response.

`activities(string $envelopeId, int $page = 1, int $perPage = 20, string $sort = '-id'): array`:
`GET /envelopes/{envelopeId}/activities`; query `['page' => 1, 'per-page' => 20, 'sort' => '-id']`.
Use `id` for oldest first. Includes documents taken out, but not deleted documents. Response:

```php
[
    'status' => 200, 'message' => '',
    'data' => [[
        'id' => 1, 'document_id' => 'agreement-id', 'document_name' => 'agreement.pdf',
        'event' => 'document_uploaded', 'message' => 'Document uploaded.', 'payload' => [],
        'origin' => null, 'created_at' => '2026-11-01T12:00:00Z',
    ]],
    'pagination' => ['current_page' => 1, 'page_count' => 1, 'per_page' => 20, 'total_count' => 1],
]
```

## Add, remove, remind and cancel

`addDocument(string $envelopeId, array $document): array`:
`POST /envelopes/{envelopeId}/documents`. Request:
`['id' => 'new-document-id', 'signer_ids' => ['signer-id'], 'method' => 'virtual', 'max_total_credits' => 0]`.
The open envelope gains the ready unassigned document last. Only existing envelope signers may be
selected. `entries`, `expires_at`, `copy_receiver_ids` follow creation. Invitations are sent again
to chosen signers; only digital-certificate signatures add cost. Returns the Envelope response.
Closed envelope: `409 EnvelopeClosed`; exceeded ceiling: `409 CreditCeilingExceeded`.

`removeDocument(string $envelopeId, string $documentId): array`:
`DELETE /envelopes/{envelopeId}/documents/{documentId}`, no body/query. Cancels the open document,
notifies its participants and returns the Envelope response without it. A closed or fully signed
document returns `409 DocumentClosed`. Removing again returns 404. Its original invitation may
still open the other documents, so deleting that document outright may return `409 EnvelopeOpen`
until all the envelope's documents close.

`resend(string $envelopeId, string $signerId): array`:
`PUT /envelopes/{envelopeId}/signers/{signerId}/resend`, no body/query. Requires an open envelope
and signer with documents still to sign. Paid notifications are charged once per sent message.
Response: `['is_sent' => true, 'envelope_id' => 'envelope-id', 'signer_id' => 'signer-id']`.
Closed envelope: `409 EnvelopeClosed`; nothing left to sign or insufficient credit: 400.

`cancel(string $envelopeId): array`: `PUT /envelopes/{envelopeId}/cancel`, no body/query.
Cancels cancellable documents and notifies participants. Closed, fully signed and certificating
documents stay unchanged. Repeating is safe. Response:
`[['id' => 'agreement-id', 'status' => 'rejected_by_user', 'cancelled' => true]]`.

`delete(string $envelopeId): array`: `DELETE /envelopes/{envelopeId}`, no body/query, response `[]`.
All documents must be closed; otherwise `409 EnvelopeOpen`. Documents, certificates and downloads
remain available. The envelope itself then returns 404 and is absent from lists.

## Download

`download(string $envelopeId, string $artifact = 'certificated'): string` sends
`GET /envelopes/{envelopeId}/download/{artifactName}`, no body/query. Returns `application/zip`
bytes. Allowed artifacts: `original`, `certificated`, `certificate-page`, `pades`, `bundle`.
Non-original downloads include only certified documents; PAdES includes only documents with it.
No matching artifacts: 404. Files are named by position, such as `1 - agreement.pdf`; bundle
places each document's bundle files in its own folder.

```php
$bytes = $client->envelopes()->download('envelope-id', 'bundle');
file_put_contents('/private/storage/agreement.zip', $bytes, LOCK_EX);
```
