# Assinafy PHP SDK

*Português · [Read in English](README.en.md)*

Cliente PHP 8.2–8.5, independente de framework, para a [API Assinafy v1](https://api.assinafy.com.br/v1/docs).
A [referência completa](docs/API_REFERENCE.md) documenta cada método, autenticação, pré-condição e
payload; veja também [OAuth](docs/OAUTH.md), [exemplos](docs/EXAMPLES.md) e [instalação](docs/INSTALLATION.md).

## Instalação e cliente

```bash
composer require assinafy/php-sdk
```

PHP não possui edição LTS. O SDK suporta PHP 8.2–8.5 e recomenda PHP 8.5 para projetos novos.
Use TLS 1.2 ou superior e carregue segredos pelo ambiente ou por um gerenciador de segredos.

```php
<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;
use Assinafy\SDK\Resources\AssignmentResource;
use Assinafy\SDK\Resources\DocumentResource;

$client = AssinafyClient::create(
    apiKey: (string) getenv('ASSINAFY_API_KEY'),
    accountId: (string) getenv('ASSINAFY_ACCOUNT_ID'),
    baseUrl: Configuration::SANDBOX_BASE_URL,
);
```

Produção usa `Configuration::DEFAULT_BASE_URL`; credenciais de sandbox e produção são distintas.
Para uma sessão de usuário, use `AssinafyClient::forAuth()`, `auth()->login()` e, quando houver
`mfa_token`, `auth()->verifyMfa()`. Para um token Bearer já emitido, use `forBearer()`.

## Fluxo completo do documento

O fluxo é: upload → preparação → signatário → estimativa → atribuição → assinatura → certificação → download.

```php
// 1. Envie um PDF legível de até 25 MB e aguarde as páginas.
$document = $client->documents()->upload('/caminho/contrato.pdf');
$documentId = $document['id'];
$document = $client->documents()->waitUntilReady($documentId);
$client->documents()->rename($documentId, 'Contrato de serviços.pdf');

// 2. Reutilize ou crie o signatário.
$email = 'signer@example.com';
$signer = $client->signers()->findByEmail($email)
    ?? $client->signers()->create('Signatário de exemplo', $email);
$signerId = $signer['id'];

// 3. Modele e estime antes de criar.
$signers = [[
    'id' => $signerId,
    'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
    'notification_methods' => [AssignmentResource::NOTIFICATION_EMAIL],
    'step' => 1,
]];
$estimate = $client->assignments()->estimateCost($documentId, $signers);
if (!($estimate['has_sufficient_resources'] ?? false)) {
    throw new RuntimeException($estimate['blocking_reason'] ?? 'Recursos insuficientes');
}

// 4. Crie a atribuição. Datas sem offset são UTC; YYYY-MM-DD expira às 23:59 UTC.
$assignment = $client->assignments()->create(
    documentId: $documentId,
    signers: $signers,
    method: AssignmentResource::METHOD_VIRTUAL,
    options: ['message' => 'Revise e assine.', 'expires_at' => '2027-12-31'],
);
$assignmentId = $assignment['id'];

// 5. A interface própria usa o código entregue ao signatário, nunca a chave da conta.
$public = AssinafyClient::forAuth(Configuration::SANDBOX_BASE_URL);
$session = $public->signerSession();
$accessCode = (string) getenv('ASSINAFY_SIGNER_ACCESS_CODE');
$session->acceptTerms($accessCode);
$session->verifyCode($accessCode, (string) getenv('ASSINAFY_VERIFICATION_CODE'));
$session->confirmData($documentId, $accessCode, [
    'full_name' => 'Signatário de exemplo', 'email' => $email, 'has_accepted_terms' => true,
]);
$session->sign($documentId, $assignmentId, $accessCode, []); // atribuição virtual

// 6. Acompanhe o estado; prefira webhooks para processos assíncronos.
$progress = $client->documents()->getSigningProgress($documentId);
$document = $client->documents()->get($documentId);

// 7. Após certificated, salve os bytes e valide o hash publicado no documento.
if ($document['status'] !== 'certificated') {
    throw new RuntimeException('Aguarde a certificação e retome pelo ID do documento.');
}
$pdf = $client->documents()->download($documentId, DocumentResource::ARTIFACT_CERTIFICATED);
file_put_contents('/armazenamento/privado/contrato-assinado.pdf', $pdf, LOCK_EX);
$verification = $public->documents()->verify((string) getenv('ASSINAFY_DOCUMENT_SIGNATURE_HASH'));
```

`waitUntilReady()` aguarda preparação, não assinaturas. Guarde cada ID criado; o helper
`uploadAndRequestSignatures()` compõe as etapas iniciais, mas não desfaz mutações remotas após falha.
Use `resetExpiration(..., null)` para remover o prazo. Reenvios devem ser estimados com
`estimateResendCost()`. Artefatos disponíveis: `original`, `certificated`, `certificate-page`,
`pades` e `bundle`.

## Verificação, notificação e assinatura

Cada signatário aceita no máximo um canal de notificação. Omitir um lado infere o outro; omitir
ambos seleciona Email. Etapas, quando usadas, devem ser contíguas a partir de 1.

| Verificação | Notificação | Requisitos principais |
| --- | --- | --- |
| `Email` | `Email` | Email cadastrado; sem custo de notificação |
| `Whatsapp` | `Whatsapp` | Telefone E.164, plano compatível; 0,45 crédito |
| `IdDocumentFaceMatchBasic` | `Email` ou `Whatsapp` | Recurso biométrico, CPF válido e consentimento explícito |
| `DigitalCertificate` | `Email` ou `Whatsapp` | `virtual`, recurso habilitado, CPF ou CNPJ válido e signatário sozinho na etapa |

`DigitalCertificate` cobre ICP-Brasil A1 e A3 pelo mesmo payload. Um CPF exige e-CPF, ou e-CNPJ que
nomeie a pessoa como representante; um CNPJ exige e-CNPJ da empresa. A extensão Web PKI conclui a
assinatura; o resultado qualificado é `pades` e `signerSession()->sign()` não executa esse protocolo.
O custo do certificado digital vem de `estimateCost()`, além da notificação, e não funciona em
`collect`. Biometria permanece CPF-only; preserve e proteja a URL de captura com fragmento `#t=`.

Em `collect`, obtenha campos com `fields()->list(includeStandard: true)` e envie `entries` com
`page_id`, `signer_id`, `field_id` e `display_settings`. Os valores finais usam `itemId`, `fieldId`,
`pageId` e `value`. As coordenadas são pixels da imagem da página a 150 DPI.

Templates criados pela API começam com função Editor. Configure funções e campos na interface web,
depois use `documents()->estimateCostFromTemplate()` e `createFromTemplate()`.

Envelopes agrupam documentos com uma única jornada por signatário: veja [envelopes](docs/ENVELOPES.md).
Para incorporar a assinatura, configure origens e use `embeddedSigning()->createSession()`;
veja [assinatura incorporada](docs/EMBEDDED_SIGNING.md). [Preferências e políticas](docs/WORKSPACE.md)
e [entrega de cópias certificadas](docs/SIGNER_DELIVERY.md) descrevem os métodos complementares.

## Contratos, webhooks e OAuth

`contracts()` lista termos, relaciona aditivos e executa renovação ou encerramento. Envie o
`updated_at` lido para detectar concorrência. Leituras OAuth usam `documents:read`; alterações usam
`documents:write` e dependem do plano.

Crie endpoints com `webhooks()->createEndpoint()` e `signingEnabled: true`. Guarde o `secret` da
criação; clientes OAuth não podem lê-lo depois, mas `webhooks:write` permite rotação. No receptor,
verifique o corpo bruto antes de decodificar, deduplique por `webhook-id` e busque o recurso novamente:

```php
$raw = (string) file_get_contents('php://input');
if (!$client->webhookEvents()->verifySignature($raw, getallheaders(), $secret)) {
    http_response_code(401);
    exit;
}
$event = $client->webhookEvents()->extractEvent($raw);
```

Aplicativos de marketplace usam `oauth($clientId, $clientSecret)` com PKCE S256, `state` e `iss`.
Guarde a transação completa de `startAuthorization()`, valide com `handleCallback()` e troque com
`exchangeCode()`. Um token autoriza uma única conta. Refresh tokens rotacionam: use lock por conexão,
persista o novo registro antes de continuar e nunca repita uma renovação ambígua. O SDK não armazena
tokens, não renova automaticamente e não valida OIDC/JWT. O fluxo completo está em [docs/OAUTH.md](docs/OAUTH.md).

## Retornos, erros e testes

Recursos individuais normalmente retornam `data` sem envelope. Listas paginadas mantêm `data` e
adicionam `pagination` a partir de `X-Pagination-*`; binários retornam `string` de bytes.
Trate `ValidationException`, `ApiException` e `NetworkException`. Em 429, consulte `getRetryAfter()`
e `getRateLimit()`; o SDK não repete chamadas automaticamente.

```bash
composer install
composer check
ASSINAFY_INTEGRATION=1 composer test:integration -- --filter OAuth
```

Testes unitários não usam rede. Integração completa exige credenciais de sandbox, conta descartável
para operações destrutivas e caixas controladas para entrega/OTP; o runner recusa produção por padrão.
GitLab CI é canônico e o espelho GitHub executa apenas verificações offline. Consulte
[ARCHITECTURE.md](ARCHITECTURE.md), [UPGRADING.md](UPGRADING.md) e [CHANGELOG.md](CHANGELOG.md).

Licença [MIT](LICENSE).
