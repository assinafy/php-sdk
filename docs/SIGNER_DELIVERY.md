# Signer delivery, capacities and observers

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

## Signer capacity on the certificate

The following workspace methods are published by the
[staging API](https://api.angry-pisco.stage.assinafy.com.br/v1/docs); the selected deployment must
support them. They do not use a signer access code.

Pass `sign_as` in each signer to `assignments()->create()`, `estimateCost()` or `schedule()`, or
in the signer options of `uploadAndRequestSignatures()`. For example:

```php
$signers = [['id' => 'signer-id', 'sign_as' => 'Witness']];
$estimate = $client->assignments()->estimateCost('document-id', $signers);
$assignment = $client->assignments()->create('document-id', $signers);
$capacities = $client->assignments()->signerCapacities('document-id');
// SDK response:
$capacities = [['signer_id' => 'signer-id', 'sign_as' => 'Witness']];
```

`signerCapacities(string $documentId): array` sends
`GET /v1/documents/{documentId}/signer-capacities`, no body/query, with workspace credentials
or OAuth `documents:read`. It returns `[]` before assignment and otherwise follows signer order.
Capacity is fixed at assignment creation, appears on the certificate row, and does not change
verification, signing order or cost. It is absent from the ordinary assignment and webhook bodies.
Omitting it or sending null means `Sign`.

`AssignmentResource::SIGNER_CAPACITIES` contains the supported case-sensitive values:
`Sign`, `Approve`, `Acknowledge`, `Party`, `Witness`, `Intervening`, `Receipt`,
`IssuerEndorserGuarantor`, `IssuerEndorserGuarantorSurety`, `Surety`, `PartySurety`,
`JointDebtor`, `PartyJointDebtor`, `ContractingParty`, `ContractedParty`, `Landlord`, `Tenant`,
`Buyer`, `Seller`, `AttorneyInFact`, `LegalRepresentative`, `Employer`, `Employee`.

## Notification history

`assignments()->notificationHistory(string $documentId): array` sends
`GET /v1/documents/{documentId}/notification-history`, no body/query. Use workspace credentials
or OAuth `documents:read`. Before assignment it returns `[]`; otherwise:

```php
[
    ['signer_id' => 'signer-id', 'notification_history' => [
        [
            'event' => 'signature_request',
            'status' => 'sent',
            'error_code' => null,
            'error_message' => null,
            'sent_at' => '2027-01-02T12:00:00Z',
            'failed_at' => null,
            'delivered_at' => null,
            'opened_at' => null,
        ],
    ]],
]
```

`status` is the furthest stage: `sent`, `delivered`, `opened` or `failed`. Delivery/open timestamps
are nullable; the SDK retains them when returned. An open can mean an email open/click or a
WhatsApp read and is not proof that a person read the document. A failure can supply localized
`error_message` and `error_code`: `MailboxFull`, `AddressNotFound`, `AddressSuppressed`,
`ContentRejected`, `MarkedAsSpam`, `NotOnWhatsapp`, `RecipientRestricted`, `NotSent` or `Unknown`.
Do not log these records as general diagnostics; they may contain personal data.

## Document observers

`documents()->watchers(string $documentId): array` sends `GET /v1/documents/{documentId}/watchers`,
no body/query. `setWatchers(string $documentId, array $watcherIds): array` sends `PUT` on the same
path with `['watcher_ids' => ['contact-id']]`. These methods require an API key or user-session
Bearer; marketplace OAuth cannot use them.

```php
$watchers = $client->documents()->setWatchers('document-id', ['contact-id']);
// Both methods return complete contact records:
$watchers = [[
    'resource' => 'signer', 'id' => 'contact-id', 'full_name' => 'Example Observer',
    'email' => 'observer@example.com', 'whatsapp_phone_number' => null,
    'government_id' => null, 'has_accepted_terms' => false,
]];
$client->documents()->setWatchers('document-id', []); // Clear all observers.
```

Up to ten distinct existing workspace contacts with email addresses may observe an unfinished
document. The SDK deduplicates/reindexes IDs before sending. Reading follows the saved order and
omits deleted contacts. Observers receive an email when the document expires, is declined or is
signed by everyone; it contains the document/sender names, with no link or attachment. Workspace
notification preferences do not suppress these messages. Updating a closed document is refused.

## Envelope reminder pricing

`envelopes()->estimateResendCost(string $envelopeId, string $signerId): array` sends
`POST /v1/accounts/{accountId}/envelopes/{envelopeId}/signers/{signerId}/estimate-resend-cost`,
no body/query, using workspace credentials or OAuth `documents:write`.

```php
$estimate = $client->envelopes()->estimateResendCost('envelope-id', 'signer-id');
// SDK response:
$estimate = [
    'total' => 0,
    'breakdown' => [['code' => 'Email', 'name' => 'Email', 'cost' => 0]],
    'credit_balance' => 20,
    'has_sufficient_credits' => true,
];
```

The estimate prices one reminder for the whole envelope and neither sends nor charges. It does
not check cooldown or eligibility; `resend()` can still refuse. Use returned prices for paid
channels. Document and envelope reminders share the two-minute cooldown and ten-per-day limit.
