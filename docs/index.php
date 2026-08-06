<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Assinafy PHP SDK documentation</title>
    <style>
        :root {
            color-scheme: light dark;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.6;
        }

        body {
            margin: 0;
            background: #f4f6fb;
            color: #1f2937;
        }

        header {
            padding: 3.5rem 1.5rem;
            background: #332c70;
            color: #fff;
        }

        main,
        header > div,
        footer {
            width: min(960px, calc(100% - 3rem));
            margin: 0 auto;
        }

        main {
            padding: 2rem 0;
        }

        section {
            margin-bottom: 1.5rem;
            padding: 1.5rem;
            border: 1px solid #d8dbea;
            border-radius: 0.6rem;
            background: #fff;
        }

        h1,
        h2 {
            line-height: 1.2;
        }

        h1 {
            margin: 0 0 0.5rem;
        }

        h2 {
            margin-top: 0;
        }

        a {
            color: #5145b5;
        }

        nav ul,
        .resources {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 0.75rem 1.5rem;
        }

        pre {
            overflow-x: auto;
            padding: 1rem;
            border-radius: 0.4rem;
            background: #151827;
            color: #f8fafc;
        }

        footer {
            padding: 0 0 2rem;
            color: #4b5563;
        }

        @media (prefers-color-scheme: dark) {
            body {
                background: #111421;
                color: #e5e7eb;
            }

            section {
                border-color: #3b4055;
                background: #1c2030;
            }

            a {
                color: #b9b2ff;
            }

            footer {
                color: #c2c7d2;
            }
        }
    </style>
</head>
<body>
    <header>
        <div>
            <h1>Assinafy PHP SDK</h1>
            <p>A synchronous, framework-agnostic client for the Assinafy v1 digital-signature API.</p>
        </div>
    </header>

    <main>
        <section>
            <h2>Requirements and scope</h2>
            <p>
                The SDK is tested on PHP 8.2 through 8.5, uses strict types and PSR-4 autoloading,
                accepts PSR-3 loggers, and supports Guzzle 7 and 8 as its runtime transport. It maps all
                89 operations on the 68 paths in the current OpenAPI document, including browser
                OAuth URL builders, plus five live-tested template-management routes that are not
                currently published in OpenAPI.
            </p>
        </section>

        <section>
            <h2>Install</h2>
            <p>
                Version 2.0.0 is released as repository tag <code>v2.0.0</code>, but Packagist
                does not currently expose <code>assinafy/php-sdk</code>. After the package is
                published to Packagist:
            </p>
            <pre><code>composer require assinafy/php-sdk:^2.0</code></pre>
            <p>
                Until then, follow the <a href="INSTALLATION.md">tagged VCS/path repository
                installation instructions</a>.
            </p>
        </section>

        <section>
            <h2>Sandbox quick start</h2>
            <p>Load credentials from environment variables or a secret manager; never commit them.</p>
            <pre><code>&lt;?php

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;

$client = AssinafyClient::create(
    apiKey: (string) getenv('ASSINAFY_API_KEY'),
    accountId: (string) getenv('ASSINAFY_ACCOUNT_ID'),
    baseUrl: Configuration::SANDBOX_BASE_URL,
);

$documents = $client-&gt;documents()-&gt;list(page: 1, perPage: 20);</code></pre>
            <p>
                User-session integrations can call <code>AssinafyClient::forBearer()</code> once
                an account ID is known; its Authorization header applies to every workspace
                resource. Public/bootstrap and signer access-code flows remain credential-isolated.
                Custom remote base URLs require HTTPS; plain HTTP is accepted only for loopback
                development on localhost, 127.0.0.1, or ::1.
            </p>
        </section>

        <section>
            <h2>Resources</h2>
            <ul class="resources">
                <li>Accounts and statistics</li>
                <li>Documents and templates</li>
                <li>Signers and assignments</li>
                <li>Signer sessions and documents</li>
                <li>Authenticated user profile</li>
                <li>Fields and tags</li>
                <li>Authentication helpers</li>
                <li>Webhooks and event parsing</li>
            </ul>
        </section>

        <section>
            <h2>Documentation</h2>
            <nav aria-label="SDK documentation">
                <ul>
                    <li><a href="INSTALLATION.md">Installation guide</a></li>
                    <li><a href="API_REFERENCE.md">Complete public API reference</a></li>
                    <li><a href="EXAMPLES.md">Examples</a></li>
                    <li><a href="../ARCHITECTURE.md">Architecture</a></li>
                    <li><a href="quickstart.php">Read-only sandbox quick start</a></li>
                    <li><a href="https://api.assinafy.com.br/v1/docs">Official Assinafy API documentation</a></li>
                    <li><a href="https://github.com/assinafy/php-sdk">GitHub mirror</a></li>
                </ul>
            </nav>
        </section>
    </main>

    <footer>
        <p>Assinafy PHP SDK &middot; MIT License</p>
    </footer>
</body>
</html>
