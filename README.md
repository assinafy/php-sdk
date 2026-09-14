# SDK PHP da Assinafy

*Português · [Read in English](README.en.md)*

Cliente PHP independente de framework para a [API Assinafy v1](https://api.assinafy.com.br/v1/docs)
— plataforma brasileira de assinatura eletrônica de documentos. Cobre administração de workspace,
preparação de documentos, solicitações de assinatura, sessões do signatário, artefatos, templates,
tags, campos e webhooks.

> **Referência completa em inglês.** Este documento cobre instalação, autenticação e os fluxos
> principais. O guia que acompanha um documento do upload até a certificação está em
> **[README.en.md](README.en.md)**, e a referência consolidada em
> [docs/API_REFERENCE.md](docs/API_REFERENCE.md).

## Requisitos

- PHP 8.2 até PHP 8.5
- `ext-json`
- `ext-mbstring`
- Composer 2

O transporte padrão usa Guzzle. Aplicações podem injetar um logger PSR-3 ou a própria
`HttpClientInterface` do SDK; o transporte não é uma implementação PSR-18.

## Instalação

```bash
composer require assinafy/php-sdk
```

O pacote é publicado no Packagist como
[`assinafy/php-sdk`](https://packagist.org/packages/assinafy/php-sdk) — nenhuma configuração de
repositório é necessária. Veja [docs/INSTALLATION.md](docs/INSTALLATION.md) para restrições de versão
e setup de desenvolvimento.

Mantenha chaves de API e identificadores de conta em um gerenciador de segredos ou em variáveis de
ambiente — **nunca** em `composer.json`, no código-fonte, em fixtures ou na configuração de CI.

## Configurando o cliente

Use produção, a menos que a operação seja intencionalmente um teste de sandbox:

```php
<?php

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;

$client = AssinafyClient::create(
    apiKey: (string) getenv('ASSINAFY_API_KEY'),
    accountId: (string) getenv('ASSINAFY_ACCOUNT_ID'),
    baseUrl: Configuration::DEFAULT_BASE_URL,
);
```

Para desenvolvimento, mude apenas a URL base:

```php
$sandbox = AssinafyClient::create(
    apiKey: (string) getenv('ASSINAFY_API_KEY'),
    accountId: (string) getenv('ASSINAFY_ACCOUNT_ID'),
    baseUrl: Configuration::SANDBOX_BASE_URL,
);
```

Use `Configuration` diretamente para controlar timeouts ou injetar um logger:

```php
$logger = new \Psr\Log\NullLogger(); // Troque pelo logger PSR-3 da sua aplicação.

$configuration = new Configuration(
    apiKey: (string) getenv('ASSINAFY_API_KEY'),
    accountId: (string) getenv('ASSINAFY_ACCOUNT_ID'),
    baseUrl: Configuration::DEFAULT_BASE_URL,
    timeout: 30,
    connectTimeout: 10,
);

$client = new AssinafyClient($configuration, logger: $logger);
```

O transporte embutido impõe `User-Agent: Assinafy-PHP-SDK/v{SDK_VERSION}` em toda requisição — por
exemplo, a versão 2.1.3 envia `Assinafy-PHP-SDK/v2.1.3`. Isso vale para requisições autenticadas,
públicas, de signatário, JSON, upload multipart, corpo bruto e download binário.
`Configuration::SDK_VERSION` é a fonte única da versão no header. Aplicações que substituírem o
transporte `HttpClientInterface` embutido precisam enviar exatamente o mesmo header.

URLs base remotas e customizadas precisam usar HTTPS. HTTP puro só é aceito em hosts de
desenvolvimento em loopback. Credenciais, query strings e fragmentos são rejeitados nas URLs base, e
timeouts precisam ser positivos.

## Modos de autenticação

A autenticação por chave de API do workspace é o modo normal para operações de documento. Login e
outras operações públicas começam sem credenciais de workspace:

```php
$public = AssinafyClient::forAuth(Configuration::DEFAULT_BASE_URL);
$session = $public->auth()->login(
    'desenvolvedor@exemplo.com.br',
    (string) getenv('ASSINAFY_PASSWORD'),
);

$contas = $public->accounts()->list($session['access_token']);
```

Depois de escolher uma conta, um cliente Bearer pode chamar recursos com escopo de conta:

```php
$bearerClient = AssinafyClient::forBearer(
    accessToken: $session['access_token'],
    accountId: $contas['data'][0]['id'],
    baseUrl: Configuration::DEFAULT_BASE_URL,
);
```

Chaves de API, tokens Bearer e códigos de acesso do signatário são credenciais **separadas**. Um
cliente público não envia nem `X-Api-Key` nem `Authorization`; chamar um recurso com escopo de conta
nele falha localmente.

## Métodos de verificação do signatário

Definidos por signatário ao criar o assignment. O método de verificação e o de notificação são
**acoplados**: envie um, os dois ou nenhum — o lado que faltar é inferido. Sem nenhum dos dois, ambos
assumem `Email`.

| Método | Como funciona | Custo por signatário |
| --- | --- | --- |
| `Email` *(padrão)* | Código de uso único (OTP) por e-mail, exigido antes de assinar | Gratuito |
| `Whatsapp` | Código de uso único (OTP) por WhatsApp | Verificação gratuita; notificação 0,45 crédito, só em planos pagos |
| `DigitalCertificate` | O signatário assina com o **próprio certificado ICP-Brasil (A1/A3)**, pela extensão de navegador Web PKI, gerando uma assinatura **PAdES qualificada** | 2 créditos |

Combinações permitidas: `Email` → notifica por `Email`; `Whatsapp` → notifica por `Whatsapp`;
`DigitalCertificate` → notifica por `Email` **ou** `Whatsapp`. Apenas um método de notificação por
signatário.

Estime sempre antes de enviar: `$client->assignments()->estimateCost(...)` devolve o custo em
créditos com o detalhamento por item.

### Certificado digital ICP-Brasil

Exige o recurso **Certificado Digital** na conta (planos Standard e Pro), CPF ou CNPJ em
`government_id` do signatário, e exatamente **um signatário por certificado naquele passo**. Um CPF
exige o certificado daquela pessoa (e-CPF, ou e-CNPJ que a nomeie como representante legal); um CNPJ
exige um e-CNPJ da empresa.

Antes de abrir o assignment, o signatário precisa confirmar os dados de identidade e aceitar os
termos. O endpoint comum de assinatura **rejeita** signatários por certificado — a assinatura deles é
produzida por um handshake de dois passos com a extensão Web PKI:

```
POST /v1/signers/certificate/start     → data.token   (token da operação Web PKI)
        ↓  o navegador assina o token com o certificado do signatário
POST /v1/signers/certificate/complete  → data.signerName
```

> Essas duas rotas são extensões implantadas **somente em produção**: o sandbox não as expõe e elas
> não constam do documento OpenAPI publicado.

Concluído o fluxo, baixar o artefato `pades` devolve a assinatura PAdES qualificada.

## Trilha de atividades e artefatos

As atividades de um documento devolvem todos os eventos registrados, cada um com um snapshot do
`payload` do evento e a `origin` da requisição (`ip`, `user-agent`).

Artefatos disponíveis para download:

| Artefato | Conteúdo |
| --- | --- |
| `original` | O PDF enviado, como recebido |
| `certificated` | O documento assinado, com a certificação da plataforma |
| `certificate-page` | Apenas a página de certificação |
| `pades` | Assinaturas ICP-Brasil dos signatários + caixa de certificação — só existe em documentos que tiveram signatários por certificado digital |
| `bundle` | Zip com `original`, `certificated` e `certificate-page`, mais o `pades` quando houver |

A verificação pública confere um documento assinado pelo hash da assinatura, sem autenticação.

## Ambientes

| | |
| --- | --- |
| Produção | `Configuration::DEFAULT_BASE_URL` — `https://api.assinafy.com.br/v1` |
| Sandbox | `Configuration::SANDBOX_BASE_URL` — `https://sandbox.assinafy.com.br/v1` |

O sandbox é gratuito e espelha a produção para testar a integração de ponta a ponta — com a exceção
das rotas de certificado digital, que existem apenas em produção. As demais diferenças estão
documentadas em [README.en.md](README.en.md#sandbox-and-production-differences).

## Documentação

- **[README.en.md](README.en.md)** — guia completo do fluxo, em inglês
- [docs/API_REFERENCE.md](docs/API_REFERENCE.md) — referência consolidada
- [docs/INSTALLATION.md](docs/INSTALLATION.md) — instalação e desenvolvimento
- [docs/EXAMPLES.md](docs/EXAMPLES.md) — exemplos focados
- [Documentação da API](https://api.assinafy.com.br/v1/docs)

## Licença

Distribuído sob a licença [MIT](LICENSE).
