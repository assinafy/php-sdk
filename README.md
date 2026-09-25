# Assinafy PHP SDK

*Português · [Read in English](README.en.md)*

Cliente PHP independente de framework para a [API Assinafy v1](https://api.assinafy.com.br/v1/docs).
Este guia acompanha um documento do upload até a assinatura e o download dos arquivos finais.
A [referência de métodos](docs/API_REFERENCE.md) contém os parâmetros, autenticação e formatos de
retorno de todo o SDK. Os docblocks dos recursos incluem exemplos completos de requisição e resposta.

- [Instalação e configuração](#instalação-e-configuração)
- [Fluxo completo do documento](#fluxo-completo-do-documento)
- [Campos e assinatura collect](#campos-e-assinatura-collect)
- [Templates e organização](#templates-e-organização)
- [Webhooks](#webhooks)
- [Aplicativos de marketplace e OAuth](#aplicativos-de-marketplace-e-oauth)
- [Respostas, paginação e erros](#respostas-paginação-e-erros)
- [Recursos disponíveis](#recursos-disponíveis)
- [Testes e desenvolvimento](#testes-e-desenvolvimento)

## Instalação e configuração

Requisitos: PHP 8.2–8.5, Composer 2 e extensões `json` e `mbstring`. O cliente padrão exige TLS 1.2 ou superior.
PHP usa ciclos de suporte, sem uma edição LTS; PHP 8.5 é a versão recomendada para novos projetos.
Consulte a [política de suporte do PHP](https://www.php.net/supported-versions.php).
Guzzle é instalado como dependência de execução.

```bash
composer require assinafy/php-sdk
```

O pacote está disponível no [Packagist](https://packagist.org/packages/assinafy/php-sdk).
Use a documentação da versão instalada; o código em `main` pode conter alterações ainda não publicadas.
Veja também o [guia de instalação](docs/INSTALLATION.md).

Carregue a chave e o identificador da conta pelo gerenciador de segredos da aplicação ou pelo ambiente.
O SDK não lê arquivos `.env` automaticamente. Nunca coloque credenciais em código, logs ou commits.

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

Em produção, use `Configuration::DEFAULT_BASE_URL` (`https://api.assinafy.com.br/v1`).
O sandbox usa `https://sandbox.assinafy.com.br/v1`; as credenciais são específicas do ambiente.
URLs remotas exigem HTTPS. HTTP é permitido somente para desenvolvimento em loopback.

Para configurar timeouts e um logger PSR-3:

```php
$configuration = new Configuration(
    apiKey: (string) getenv('ASSINAFY_API_KEY'),
    accountId: (string) getenv('ASSINAFY_ACCOUNT_ID'),
    baseUrl: Configuration::SANDBOX_BASE_URL,
    timeout: 30,
    connectTimeout: 10,
);
$client = new AssinafyClient($configuration, logger: new \Psr\Log\NullLogger());
```

O transporte envia `Assinafy-PHP-SDK/v{SDK_VERSION}` como User-Agent em todas as requisições.
Implementações próprias de `HttpClientInterface` devem enviar o mesmo cabeçalho.

## Fluxo completo do documento

### 1. Enviar o PDF e aguardar o processamento

O arquivo precisa ser um PDF legível, com cabeçalho e marcador final válidos e até 25 MB.
O servidor processa as páginas de forma assíncrona e aplica o limite de 2.000 páginas.

```php
$document = $client->documents()->upload('/caminho/absoluto/contrato.pdf');
$documentId = $document['id'];

$document = $client->documents()->waitUntilReady(
    documentId: $documentId,
    maxWaitSeconds: 60,
    pollIntervalSeconds: 2,
);

$document = $client->documents()->rename($documentId, 'Contrato de serviços.pdf');
```

Guarde o `documentId` imediatamente após o upload. A renomeação deve acontecer enquanto o documento
estiver em `uploaded` ou `metadata_ready`, antes da solicitação de assinatura. `waitUntilReady()`
aguarda a preparação do documento; ele não aguarda que os destinatários assinem.

### 2. Criar ou reutilizar o signatário

```php
$email = 'signer@example.com';
$signer = $client->signers()->findByEmail($email)
    ?? $client->signers()->create('Signatário de exemplo', $email);
$signerId = $signer['id'];
```

`findByEmail()` percorre a busca paginada e retorna a correspondência exata, ignorando maiúsculas,
ou `null`. Reutilizar um signatário não altera seu nome ou telefone; use `update()` para isso.
Números de WhatsApp precisam incluir `+` e o código do país, com 8–15 dígitos.

### 3. Estimar os recursos necessários

```php
$signerPlan = [[
    'id' => $signerId,
    'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
    'notification_methods' => [AssignmentResource::NOTIFICATION_EMAIL],
    'step' => 1,
]];

$estimate = $client->assignments()->estimateCost($documentId, $signerPlan);
if (!($estimate['has_sufficient_resources'] ?? false)) {
    throw new RuntimeException($estimate['blocking_reason'] ?? 'Recursos insuficientes');
}
```

A resposta inclui `documents`, `credits`, `needs_extra_document`, `extra_document_cost`,
`total_credits`, `breakdown`, `document_balance`, `credit_balance`, `has_sufficient_resources`,
`blocking_reason` e `message`. IDs de signatário podem ser omitidos na estimativa.

Uma atribuição comum aceita no máximo um canal de notificação por signatário. Se um lado for
omitido, o servidor infere o outro; omitir ambos seleciona Email. Etapas informadas devem ser
contíguas a partir de 1 e existir para todos os signatários.

Cada signatário tem um método de verificação (como comprova a identidade) acoplado a um método de
notificação (como recebe o convite). O SDK valida a matriz antes de enviar a requisição.

| Verificação | Notificação permitida | Requisitos | Créditos por signatário |
| --- | --- | --- | --- |
| `VERIFICATION_EMAIL` | `Email` | Email cadastrado no signatário | 0 |
| `VERIFICATION_WHATSAPP` | `Whatsapp` | `whatsapp_phone_number` e assinatura paga | 0,45 |
| `VERIFICATION_DIGITAL_CERTIFICATE` | `Email` ou `Whatsapp` | Recurso Certificado Digital na conta (planos Standard e Pro), CPF em `government_id` e o signatário sozinho na sua etapa | 2 pela assinatura, além da notificação |

`DigitalCertificate` cobre os certificados ICP-Brasil **A1** (arquivo de software no dispositivo) e
**A3** (cartão ou token). Os dois usam esse mesmo valor e o mesmo payload — mudam apenas onde a
chave privada fica guardada. A assinatura é concluída no navegador pela extensão Web PKI, então
`signerSession()->sign()` não a finaliza e a API não publica operação de início/conclusão de
certificado. O resultado é uma assinatura qualificada PAdES, baixável pelo artefato `pades`.
Nos custos, ela aparece no breakdown sob o código `SignatureDigitalCertificate`.

### 4. Solicitar a assinatura

```php
$assignment = $client->assignments()->create(
    documentId: $documentId,
    signers: $signerPlan,
    method: AssignmentResource::METHOD_VIRTUAL,
    options: [
        'message' => 'Por favor, revise e assine o contrato.',
        'expires_at' => (new DateTimeImmutable('+7 days'))->format(DateTimeInterface::ATOM),
    ],
);
$assignmentId = $assignment['id'];
```

A atribuição contém `id`, `method`, `expires_at`, `message`, `signers`, `copy_receivers`, `items`,
`summary` e `signing_urls`. As notificações seguem a ordem de assinatura: etapas posteriores são
notificadas quando ficam disponíveis. URLs de assinatura são dados sensíveis.

Reenvio e alteração do prazo usam a atribuição existente:

```php
$resendEstimate = $client->assignments()->estimateResendCost($documentId, $assignmentId, $signerId);
if ($resendEstimate['has_sufficient_credits'] ?? false) {
    $client->assignments()->resend($documentId, $assignmentId, $signerId);
}
$client->assignments()->resetExpiration(
    $documentId,
    $assignmentId,
    (new DateTimeImmutable('+14 days'))->format(DateTimeInterface::ATOM),
);
```

Para a sequência padrão, o helper reúne upload, preparação, resolução dos signatários e atribuição virtual:

```php
$result = $client->uploadAndRequestSignatures(
    filePath: '/caminho/absoluto/contrato.pdf',
    signers: [['full_name' => 'Signatário de exemplo', 'email' => 'signer@example.com']],
    message: 'Por favor, assine o contrato.',
);
$document = $result['document'];
$assignment = $result['assignment'];
$signerIds = $result['signer_ids'];
```

O helper valida as descrições antes do upload, mas não desfaz objetos remotos se uma etapa posterior
falhar. Use as chamadas separadas quando precisar persistir cada ID e controlar a recuperação.

### 5. Concluir a experiência do signatário

Normalmente o destinatário usa a interface de assinatura da Assinafy. Uma interface própria precisa
de um `signer-access-code` atual, obtido pelo canal do signatário, e do código de verificação quando
solicitado. A chave da conta não substitui essas credenciais. Não extraia o código do caminho de
`signing_urls`.

```php
$public = AssinafyClient::forAuth(Configuration::SANDBOX_BASE_URL);
$delivery = $public->documents()->sendToken($documentId, 'signer@example.com');
```

O destinatário deve estar atribuído ao documento. O canal suportado pelo SDK é `email`.
O retorno contém `document`, `channel` e `recipient`; o código enviado não é retornado.

Depois de receber os códigos pelo canal autorizado:

```php
$accessCode = (string) getenv('ASSINAFY_SIGNER_ACCESS_CODE');
$session = $public->signerSession();
$profile = $session->self($accessCode);
$session->acceptTerms($accessCode);
$session->verifyCode($accessCode, (string) getenv('ASSINAFY_VERIFICATION_CODE'));
$session->confirmData($documentId, $accessCode, [
    'full_name' => 'Signatário de exemplo',
    'email' => 'signer@example.com',
    'has_accepted_terms' => true,
]);
$current = $session->currentDocument($accessCode);
$session->sign($documentId, $assignmentId, $accessCode, []);
```

O array vazio conclui a atribuição virtual. Em `collect`, envie os valores de cada campo solicitado
com `itemId`, `fieldId`, `pageId` e `value`. O signatário também pode recusar com `decline()`, enviar
imagem PNG/JPEG com `uploadSignature()` ou consultar documentos por `signerDocuments()`.

### 6. Acompanhar assinatura e certificação

```php
$progress = $client->documents()->getSigningProgress($documentId);
// ['signed' => 0, 'total' => 1, 'pending' => 1, 'percentage' => 0.0]
$fullySigned = $client->documents()->isFullySigned($documentId);
$activities = $client->documents()->activities($documentId);
```

`ready`, `certificating` e `certificated` indicam conclusão pelos signatários. A certificação e os
arquivos finais podem ficar disponíveis depois. Use webhooks e consulte o documento autenticado para
confirmar o estado. Se precisar aguardar em um processo síncrono, limite o tempo:

```php
$deadline = hrtime(true) + 60_000_000_000;
do {
    $document = $client->documents()->get($documentId);
    if ($document['status'] === DocumentResource::STATUS_CERTIFICATED) {
        break;
    }
    if (in_array($document['status'], DocumentResource::FAILURE_STATUSES, true)) {
        throw new RuntimeException('Documento encerrado sem certificação');
    }
    if (hrtime(true) >= $deadline) {
        throw new RuntimeException('Certificação pendente; consulte novamente mais tarde');
    }
    sleep(2);
} while (true);
```

### 7. Baixar os arquivos finais e verificar

```php
$pdf = $client->documents()->download($documentId, DocumentResource::ARTIFACT_CERTIFICATED);
if (file_put_contents('/armazenamento/privado/contrato-assinado.pdf', $pdf, LOCK_EX) === false) {
    throw new RuntimeException('Não foi possível salvar o arquivo');
}

$signatureHash = (string) getenv('ASSINAFY_DOCUMENT_SIGNATURE_HASH');
$verification = $public->documents()->verify($signatureHash);
if (!$verification['is_valid']) {
    throw new RuntimeException('Documento não validado');
}
```

Artefatos: `original`, `certificated`, `certificate-page`, `pades` e `bundle`. `bundle` contém ZIP;
os demais são PDFs. `pades` exige documento com assinatura por certificado digital. Miniaturas e
páginas renderizadas retornam bytes de imagem. Um hash desconhecido pode retornar HTTP 200 com
`is_valid: false`; sempre leia esse campo.

## Campos e assinatura collect

Uma atribuição `collect` coloca campos em páginas já processadas. Descubra os IDs do catálogo:

```php
$fields = $client->fields()->list(includeStandard: true);
$signatureFields = array_values(array_filter($fields, static fn (array $f): bool => $f['type'] === 'signature'));
if ($signatureFields === []) {
    throw new RuntimeException('Campo de assinatura indisponível');
}
$entries = [[
    'page_id' => $document['pages'][0]['id'],
    'fields' => [[
        'signer_id' => $signerId,
        'field_id' => $signatureFields[0]['id'],
        'display_settings' => ['left' => 10, 'top' => 10, 'width' => 240, 'height' => 60, 'fontSize' => 18],
    ]],
]];
```

Use esse payload em `estimateCost()` e `create()` com `method: 'collect'` e
`options: ['entries' => $entries]` em um documento sem atribuição. As coordenadas usam a imagem
da página a 150 DPI, a partir do canto superior esquerdo; respeite `width` e `height` da página.

Para `DigitalCertificate`, configure a funcionalidade da conta, atualize `government_id` do
signatário com CPF/CNPJ e deixe esse signatário sozinho em sua etapa. A estimativa inclui os custos
de certificado e notificação. A conclusão ICP-Brasil depende do fluxo da Assinafy; o SDK não expõe
um protocolo de início/conclusão de certificado sem contrato publicado.

## Templates e organização

```php
$client->documents()->appendTags($documentId, ['contratos', '2026']);
$tags = $client->documents()->listTags($documentId);
$client->documents()->detachTag($documentId, $tags[0]['id']);
$client->documents()->replaceTags($documentId, ['concluídos']);
```

`appendTags()` e `replaceTags()` recebem nomes e criam os nomes inexistentes; `detachTag()` recebe
o ID da tag. `tags()` gerencia as definições da conta.

Templates aceitam upload, leitura, edição, exclusão e download de páginas. Um template criado pela
API recebe uma função `Editor`. Configure funções de assinatura e campos na interface da Assinafy
antes de gerar documentos com signatários. Selecione um template já preparado:

```php
$templateId = (string) getenv('ASSINAFY_TEMPLATE_ID');
$template = $client->templates()->get($templateId);
$roles = array_values(array_filter(
    $template['roles'],
    static fn (array $role): bool => $role['assignment_type'] !== 'Editor',
));
if ($roles === []) {
    throw new RuntimeException('Configure uma função de assinatura no template');
}
$templateSigners = [['role_id' => $roles[0]['id'], 'id' => $signerId]];
$estimate = $client->documents()->estimateCostFromTemplate($templateId, $templateSigners);
$generated = $client->documents()->createFromTemplate($templateId, $templateSigners, [
    'name' => 'Contrato do template.pdf',
    'message' => 'Por favor, revise e assine.',
    'tags' => ['contratos'],
]);
```

O exemplo usa uma função de assinatura, sem campos de editor obrigatórios. Vincule todas as
funções necessárias e preencha `editor_fields` quando o template exigir.
As tags padrão do template são combinadas com as tags informadas.

## Webhooks

Cada conta possui uma assinatura de webhook; `register()` cria ou substitui a configuração.

```php
use Assinafy\SDK\Resources\WebhookResource;

$client->webhooks()->register(
    'https://hooks.example.com/assinafy/caminho-aleatorio-longo',
    'ops@example.com',
    WebhookResource::DEFAULT_EVENTS,
);
```

O envelope contém `id`, `event`, `message`, `subject`, `origin`, `account_id`, `created_at`, `object`
e `payload`. `webhookEvents()->extractEvent($json)` retorna o evento ou `null` para conteúdo inválido;
`getEventData()` lê `object`, e `getEventPayload()` lê `payload`.

As entregas não têm assinatura criptográfica. Use HTTPS, caminho imprevisível, limites de tamanho,
idempotência por evento e uma consulta autenticada ao objeto antes de agir. Retorne 2xx rapidamente
e processe por uma fila da aplicação. Há duas tentativas, separadas por três segundos; falhas
consecutivas podem pausar entregas. Não dependa da ordem entre `assignment_created` e
`document_metadata_ready`.

`deactivate()` pausa, `activate()` retoma, `dispatches()` lista o histórico e `retryDispatch()`
solicita outra entrega. O [catálogo completo](docs/API_REFERENCE.md#event-catalog) descreve os eventos.

## Aplicativos de marketplace e OAuth

Use OAuth quando a sua aplicação age sobre a conta **de outra pessoa**, sem nunca receber a senha
ou a API key dela. Para automatizar a sua própria conta, continue com a API key.

`oauth()` devolve um `OAuthResource` que cobre o fluxo inteiro: material PKCE, URL de autorização,
validação do callback, troca do código, renovação, revogação, userinfo e os dois documentos de
descoberta. O [guia OAuth](docs/OAUTH.md) traz os payloads completos, os escopos e a lista de
verificação para produção.

```php
use Assinafy\SDK\Resources\OAuthResource;

$oauth = AssinafyClient::forAuth()->oauth(
    (string) getenv('ASSINAFY_OAUTH_CLIENT_ID'),
    (string) getenv('ASSINAFY_OAUTH_CLIENT_SECRET') ?: null,
);

// 1. Envie o navegador para a Assinafy e guarde a transação na sessão do usuário.
$start = $oauth->startAuthorization('https://app.example.com/callback', [
    OAuthResource::SCOPE_DOCUMENTS_READ,
    OAuthResource::SCOPE_DOCUMENTS_WRITE,
    OAuthResource::SCOPE_OFFLINE_ACCESS,
]);
$_SESSION['assinafy_oauth'] = $start;
header('Location: ' . $start['authorization_url'], true, 302);

// 2. No redirect URI, valide o retorno e troque o código.
$transaction = $_SESSION['assinafy_oauth'];
unset($_SESSION['assinafy_oauth']);
$tokens = $oauth->exchangeCode($oauth->handleCallback($_GET, $transaction), $transaction);

// 3. O token vale para a única conta escolhida pelo usuário.
$accounts = AssinafyClient::forAuth()->accounts()->list($tokens['access_token']);
$connected = AssinafyClient::forBearer($tokens['access_token'], $accounts['data'][0]['id']);
```

`startAuthorization()` gera um `code_verifier` e um `state` novos a cada tentativa;
`handleCallback()` compara o `state` com `hash_equals` e exige que `iss` seja o servidor de
autorização esperado, antes de o código ser usado. Passe `null` como segundo argumento de `oauth()`
para um aplicativo público, que autentica só com PKCE e não recebe segredo.

As respostas de token são JSON plano, sem o envelope `data`. Leia o `scope` retornado em vez de
supor que todo escopo pedido foi concedido. `refresh_token` só vem com `offline_access` aprovado e
`id_token` só com `openid`.

```php
$renovado = $oauth->refresh($conexao->refreshToken);     // devolve um refresh token NOVO
$repositorio->salvarRefreshToken($renovado['refresh_token']); // guarde-o antes de qualquer outra coisa
$claims   = $oauth->userinfo($renovado['access_token']); // {sub, name?, email?, email_verified?}
// Ao desconectar, revogue o token guardado mais recentemente — nunca uma cópia aposentada.
$oauth->revoke($repositorio->refreshTokenAtual(), OAuthResource::TOKEN_TYPE_HINT_REFRESH);
```

Cada renovação aposenta o refresh token usado. Um refresh token repetido é indistinguível de um
roubado, então o servidor encerra a conexão inteira: guarde o novo token antes de qualquer outra
coisa, renove um de cada vez por conexão e nunca reenvie um refresh token após um timeout: releia o
que você guardou e, se ainda for o token enviado, peça ao usuário para reconectar. Só uma falha que
comprovadamente ocorreu antes do envio (DNS, conexão recusada, handshake TLS) pode ser repetida. O
acesso dura 1 hora. O refresh token vale 30 dias e cada renovação devolve um novo, válido por 30
dias a partir dela: a conexão só expira se a aplicação passar 30 dias sem renovar.

O SDK não guarda tokens, não mantém locks e não renova nada sozinho. Crie um cliente por conexão e
nunca compartilhe credencial mutável entre usuários. OAuth está publicado em produção; o sandbox
não responde essas rotas. Os helpers legados `socialLoginUrl()` e `socialLoginCallbackUrl()` são
separados desse fluxo.

## Respostas, paginação e erros

Métodos de recurso individual normalmente retornam `data` sem envelope. Listagens paginadas
retornam o envelope com `data` e `pagination`, extraída dos cabeçalhos `X-Pagination-*`:

```php
$page = $client->documents()->list(page: 1, perPage: 100);
foreach ($page['data'] as $row) {
    echo $row['name'], PHP_EOL;
}
$totalPages = $page['pagination']['page_count'];
```

Páginas começam em 1, com até 100 itens. Catálogos e listas sem paginação, como tags, campos,
atividades e eventos, retornam arrays diretos. Consulte o retorno específico de cada método;
algumas exclusões preservam o envelope e outras retornam `[]`.

```php
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\NetworkException;
use Assinafy\SDK\Exceptions\ValidationException;

try {
    $document = $client->documents()->get($documentId);
} catch (ValidationException $exception) {
    $errors = $exception->getErrors();
} catch (ApiException $exception) {
    $status = $exception->getStatusCode();
    $retryAfter = $exception->getResponseHeaderLine('Retry-After');
} catch (NetworkException $exception) {
    // A aplicação decide como recuperar uma falha de transporte ou resposta inválida.
}
```

O SDK não repete automaticamente mutações. Após um timeout, confirme o estado remoto antes de
reenviar upload, atribuição ou renovação OAuth. Em HTTP 429, respeite `Retry-After` quando presente.
O transporte desabilita redirecionamentos e registra apenas metadados, sem corpos ou valores de
credenciais. Respostas e contextos de exceção podem conter dados pessoais: não registre tudo.

## Recursos disponíveis

| Acesso | Operações |
| --- | --- |
| `accounts()` | Descoberta, criação/edição/exclusão, tema, logo e estatísticas |
| `users()` | Perfil, preferências de notificação e estatísticas do usuário |
| `documents()` | Upload, busca, metadados, artefatos, verificação pública, tags e geração por template |
| `signers()` | Cadastro, atualização, busca e exclusão de signatários |
| `assignments()` | Estimativa, atribuição, reenvio, prazo e histórico WhatsApp |
| `templates()` | Upload, consulta, edição, processamento e páginas de templates |
| `tags()` / `fields()` | Organização, definições de campos e validação de valores |
| `webhooks()` / `webhookEvents()` | Configuração, histórico, retry e leitura de eventos |
| `auth()` | Login, conta de usuário, API key e senha |
| `oauth()` | Autorização de marketplace: PKCE, callback, token, renovação, revogação, userinfo e descoberta |
| `signerSession()` / `signerDocuments()` | Ações e documentos acessíveis ao signatário |

Estatísticas de conta/usuário e preferências de notificação estão disponíveis no sandbox.
Recursos sujeitos ao plano, como notificações WhatsApp e Certificado Digital, podem responder 403.
OAuth está publicado em produção e ausente do sandbox; confirme pela descoberta do ambiente escolhido.

## Testes e desenvolvimento

```bash
composer install
composer check
```

O comando valida o pacote, executa PHPUnit, PHPStan, PHPCS e a verificação de dependências.
Os testes de unidade não usam rede. Para integração, forneça os segredos pelo ambiente:

```bash
read -rs ASSINAFY_API_KEY
export ASSINAFY_API_KEY
export ASSINAFY_ACCOUNT_ID='sandbox-account-id'
export ASSINAFY_BASE_URL='https://sandbox.assinafy.com.br/v1'
export ASSINAFY_INTEGRATION=1
vendor/bin/phpunit --testsuite=integration --testdox
```

A execução padrão usa destinatários únicos em `example.com`, verifica respostas da API e remove
seus documentos, signatários e templates. Ela não comprova entrega de email ou assinatura pelo
usuário. Opções adicionais:

| Variável | Pré-requisito/efeito |
| --- | --- |
| `ASSINAFY_NOTIFICATION_TESTS=1` | Usa `ASSINAFY_TEST_EMAIL` e `ASSINAFY_TEST_EMAIL_ALT`, caixas controladas pelo operador |
| `ASSINAFY_SIGNER_ID` + `ASSINAFY_SIGNER_ACCESS_CODE` | Exercita leituras autenticadas de uma sessão atual |
| `ASSINAFY_STATEFUL_TESTS=1` | Altera preferências/configuração compartilhadas e restaura os valores |
| `ASSINAFY_DESTRUCTIVE_TESTS=1` | Cria e exclui uma conta descartável, incluindo seu ciclo de webhooks |

Login, troca de senha e gestão destrutiva da API key precisam de usuário descartável com senha.
Conclusão de assinatura exige os códigos do signatário; templates exigem uma função de assinatura
configurada; OAuth exige aplicativo registrado e consentimento. Uma chave de workspace não fornece
essas credenciais. A integração recusa produção por padrão.

GitLab CI é o pipeline principal; GitHub Actions recebe o espelho e executa a matriz PHP 8.2–8.5,
dependências mínimas/atuais e verificações de qualidade. GitHub não executa testes de sandbox;
a integração é local e explícita, com um job opcional protegido no GitLab. Consulte
[ARCHITECTURE.md](ARCHITECTURE.md), [UPGRADING.md](UPGRADING.md) e os [exemplos](docs/EXAMPLES.md).

Licença [MIT](LICENSE).
