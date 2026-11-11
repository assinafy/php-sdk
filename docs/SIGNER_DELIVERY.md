# Signer branding and certified copies

Use `$client->signerSession()` and `$client->signerDocuments()` with a public client, for example
`AssinafyClient::forAuth(Configuration::DEFAULT_BASE_URL)`. These routes use a signer access code
or a download token, never the workspace API key or OAuth Bearer credential. The SDK strips
workspace credentials if an authenticated client is used. Keep access codes, download tokens and
links out of logs. A download token is different from a signer access code.

## Branding

`signerSession()->theme(string $accessCode): array` sends `GET /v1/sign/theme` with query
`['signer-access-code' => '<access-code>']`, no body. Available before verification and after document
deletion. A sequential step that has not started returns 400; an unknown code returns 401.
The SDK returns:

```php
[
    'account_name' => 'Example workspace',
    'primary_color' => '123456',
    'secondary_color' => 'ffffff',
    'logo' => 'https://api.assinafy.com.br/v1/sign/logo',
]
```

Colors are six hex digits without `#`: primary is the button background, secondary its text.
`logo` is null when absent. The URL has no credential; use the SDK's `logo()` method to fetch it.

`signerSession()->logo(string $accessCode): string` sends `GET /v1/sign/logo` with the same
query, no body, and returns `image/*` bytes. It has the same authentication and step requirements.
No logo returns 404.

```php
$theme = $public->signerSession()->theme($accessCode);
if ($theme['logo'] !== null) {
    $imageBytes = $public->signerSession()->logo($accessCode);
}
```

## Send the certified copy

`signerSession()->sendCertifiedCopy(string $accessCode, ?string $documentId = null): array`
sends `POST /v1/sign/certified-copy`, query `['signer-access-code' => '<access-code>']`.
By default there is no request body. Optional body `['document_id' => 'document-id']` selects
another document in the code's envelope that this signer signed; outside an envelope it is ignored.
A document outside that envelope or not assigned to this signer returns 404.

The signer must have signed and the document must be certified. A closed signer code still works,
even after workspace deletion. `GET /v1/sign` exposes `certified_copy` availability. The message
uses the original signing contact and channel, not a subsequently edited signer record.

```php
$result = $public->signerSession()->sendCertifiedCopy($accessCode);
// SDK response:
$result = [
    'available' => true,
    'notification_method' => 'Email',
    'resends_left' => null,
    'sent_to' => 's***@example.com',
];
```

Email permits one resend every two minutes and ten per 24 hours. WhatsApp permits one per signature
request, then the signer must contact the sender; its response has `resends_left => 0` and
`available => false`. A replacement link lasts 30 minutes. Unavailable copies return 409;
rate limits return 429. A failed send returns 500 without consuming a resend. The SDK never retries
these mutations automatically.

## Download a delivered link

`signerDocuments()->downloadFromToken(string $token, bool $pades = false): string` sends
`GET /v1/signers/downloads/{token}`, adding `/pades` when requested. No query, body or other
credential is sent. Response: complete `application/pdf` bytes. The completion link lasts seven
days; resent links last 30 minutes. PAdES requires that artifact and otherwise returns 404.

```php
$pdfBytes = $public->signerDocuments()->downloadFromToken($downloadToken, pades: true);
file_put_contents('/private/storage/signed.pdf', $pdfBytes, LOCK_EX);
```

Expired or unknown links answer 302. Redirect following remains disabled: the SDK raises
`ApiException` instead of returning an HTML expired-link page as PDF bytes. Do not log the
redirect Location, which may contain the token.

## Resend from an expired link

`signerDocuments()->resendDownload(string $token): array` sends
`POST /v1/signers/downloads/{token}/resend`, no query/body or other credential.
A known expired token is accepted; the document must be certified and the token must belong to
a signature request with an original delivery contact. Copy-receiver tokens return 409.
Unknown tokens return 404. Channel, contact, limits and 30-minute replacement validity match
`sendCertifiedCopy()` above.

```php
$result = $public->signerDocuments()->resendDownload($expiredDownloadToken);
// SDK response:
$result = [
    'available' => false,
    'notification_method' => 'Whatsapp',
    'resends_left' => 0,
    'sent_to' => '+55******0001',
];
```
