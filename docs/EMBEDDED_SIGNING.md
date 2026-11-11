# Embedded signing

The Embed plan feature lets an application host Assinafy's signing screen in an iframe.
Use an API key or user-session Bearer on your backend. Marketplace OAuth applications cannot
manage embed origins or mint sessions; never expose a workspace credential to the browser.

## Prepare the signer

Register the application's exact origin using the account's embed-origin methods. Origins use
`https://host[:port]`, without credentials, paths, queries, fragments or wildcards. HTTP is
accepted only for `localhost` and `127.0.0.1`. The API normalizes host casing, a trailing slash
and default ports. Each workspace supports up to ten origins; removing every origin disables
new sessions.

Upload the document and wait for preparation. Create an assignment whose signer has
`embedded: true` and uses `Email` or `Whatsapp` verification:

```php
$assignment = $client->assignments()->create($documentId, [[
    'id' => $signerId,
    'verification_method' => 'Email',
    'notification_methods' => ['Email'],
    'embedded' => true,
]]);
// signing_urls contains ['signer_id' => $signerId, 'url' => null, 'embedded' => true].
```

Embedded signers still receive the normal invitation and its six-digit verification code.
That invitation also permits signing outside the frame. An invitation-free configuration
requires account enablement. DigitalCertificate and biometric verification are not supported
for embedded signers.

## Create a session

`embeddedSigning()->createSession(string $documentId, string $assignmentId, string $signerId): array`
sends `POST /documents/{documentId}/assignments/{assignmentId}/signers/{signerId}/embed-sessions`
with no body and returns the unwrapped session:

```php
$session = $client->embeddedSigning()->createSession($documentId, $assignment['id'], $signerId);
// [
//     'url' => 'https://app.assinafy.com.br/embed/<single-use-token>',
//     'expires_at' => '2027-01-01T12:05:00Z',
// ]
```

The signer must be marked embedded, still unsigned and in an active signing step. At least one
origin must be registered. The URL expires after five minutes and works once: mint it each time
the iframe opens, including a new opening after reload. Do not log, persist or reuse it.
Sandbox URLs use `https://app-sandbox.assinafy.com.br`. The limit is 30 sessions per signer per hour.
For an envelope, mint on any of its documents still awaiting that signer; the frame lists all
remaining documents, and the envelope invitation code verifies them.

The API returns 400 (`embed_not_enabled_for_signer`), 403 (`PlanRestricted`), 404 for an unknown
resource, 429 for the session limit, or 409 with `already_signed`, `document_not_ready`,
`document_unavailable` or `embed_origins_missing`. Only `document_not_ready` means preparation
may finish soon; retry the mint after a delay. A metadata-ready webhook alone does not prove
an assignment exists.

## Display and verify frame events

Give the iframe a descriptive title and `allow="clipboard-write; fullscreen"`. On every `load`,
send `{v: 1, type: 'assinafy:hello'}` to the exact Assinafy app origin every 500 ms, stopping on
`ready`, `error`, or after ten seconds. Restart this handshake if the frame reloads.

Accept a message only when both `event.origin` equals the expected app origin and
`event.source` equals your iframe's `contentWindow`. Require `event.data.v === 1` and the
`assinafy:` type prefix. Remove listeners and stop the timer when closing the frame.

| Message type | Payload | Handling |
| --- | --- | --- |
| `assinafy:ready` | `{document_id, signer_id}` | Stop the handshake timer; may recur after reload |
| `assinafy:signed` | `{document_id, signer_id, via}` | Refresh authenticated status; handle duplicates |
| `assinafy:declined` | `{document_id, signer_id, via}` | Refresh authenticated status; handle duplicates |
| `assinafy:resize` | `{height}` | Apply a finite numeric height with a sensible minimum |
| `assinafy:error` | `{code, message}` | Stop the handshake timer and show the failure |

`via` is `embed` or `external`. Errors include `origin_not_allowed`, `session_expired`,
`not_embedded`, `document_unavailable`, `already_signed` and `access_closed`. Some errors arrive
before the handshake and contain no document data; still check their origin and source.
Frame events are UI hints. Confirm completion through verified webhooks or an authenticated
resource read before granting access or advancing business state.

The frame has no resend button. Resend from the backend using `assignments()->resend()`
or the envelope's resend operation; the current code is reused. Editing the signer's email or
phone before verification changes their codes and invalidates an open frame, requiring a new session.

## Session exchange

`embeddedSigning()->exchangeSession(string $token, ?string $parentOrigin = null): array` exposes
`POST /embed/sessions/{token}/exchange` for the hosted embed page. Integrations normally load
the URL and let Assinafy perform this exchange. The path token authenticates the request;
workspace credentials are stripped even when the SDK client has them configured.

Optional request body and complete return shape:

```php
// Request: ['parent_origin' => 'https://app.example.com']; null sends no body.
$result = $public->embeddedSigning()->exchangeSession($token, 'https://app.example.com');
// [
//     'signer_access_code' => '<signer-access-code>',
//     'signer_id' => 'signer-id',
//     'document_id' => 'document-id',
//     'verification_method' => 'Email',
//     'embed_origins' => ['https://app.example.com'],
// ]
```

The token is consumed even on 409 (`already_signed`, `document_not_ready`, `document_unavailable`).
Unknown, expired or spent tokens return 410 (`embed_session_invalid`). Never retry an exchange
or expose its signer access code in logs.
