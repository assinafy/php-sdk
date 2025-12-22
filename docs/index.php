<?php

require __DIR__ . '/../vendor/autoload.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assinafy PHP SDK - Documentation</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 0;
            text-align: center;
            margin-bottom: 40px;
        }
        
        header h1 {
            font-size: 3rem;
            margin-bottom: 10px;
        }
        
        header p {
            font-size: 1.2rem;
            opacity: 0.9;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .card h2 {
            color: #667eea;
            margin-bottom: 15px;
            font-size: 1.8rem;
        }
        
        .card h3 {
            color: #764ba2;
            margin: 20px 0 10px;
            font-size: 1.3rem;
        }
        
        .card p {
            margin-bottom: 15px;
            color: #666;
        }
        
        pre {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            overflow-x: auto;
            border-radius: 4px;
            margin: 15px 0;
        }
        
        code {
            font-family: "Courier New", monospace;
            font-size: 0.9rem;
        }
        
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .feature {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .feature h3 {
            color: white;
            margin-bottom: 10px;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin: 10px 5px;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #764ba2;
        }
        
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: bold;
        }
        
        .status.success {
            background: #d4edda;
            color: #155724;
        }
        
        footer {
            text-align: center;
            padding: 40px 0;
            color: #666;
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <h1>Assinafy PHP SDK</h1>
            <p>Modern, Framework-Agnostic PHP SDK for Digital Signatures</p>
            <p><span class="status success">Production Ready</span></p>
        </div>
    </header>

    <div class="container">
        <div class="card">
            <h2>Welcome to Assinafy PHP SDK</h2>
            <p>A modern, PSR-compliant PHP SDK for integrating with the Assinafy digital signature API. Built with clean architecture and SOLID principles.</p>
            
            <div class="feature-grid">
                <div class="feature">
                    <h3>PSR Standards</h3>
                    <p>PSR-4, PSR-3, PSR-18 compliant</p>
                </div>
                <div class="feature">
                    <h3>Framework Agnostic</h3>
                    <p>Works with any PHP project</p>
                </div>
                <div class="feature">
                    <h3>Type Safe</h3>
                    <p>PHP 8.1+ with strict types</p>
                </div>
                <div class="feature">
                    <h3>Well Documented</h3>
                    <p>Comprehensive docs & examples</p>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Quick Start</h2>
            <h3>Installation</h3>
            <pre><code>composer require assinafy/php-sdk guzzlehttp/guzzle</code></pre>

            <h3>Basic Usage</h3>
            <pre><code>&lt;?php

require 'vendor/autoload.php';

use Assinafy\SDK\AssinafyClient;

$client = AssinafyClient::create(
    apiKey: 'your-api-key',
    accountId: 'your-account-id'
);

$result = $client->uploadAndRequestSignatures(
    filePath: '/path/to/contract.pdf',
    fileName: 'contract.pdf',
    signers: [
        [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ],
    ],
    message: 'Please sign this contract'
);

echo "Document ID: {$result['document']['document_id']}\n";</code></pre>
        </div>

        <div class="card">
            <h2>Core Features</h2>
            
            <h3>Document Management</h3>
            <ul>
                <li>Upload PDF documents</li>
                <li>Track document status</li>
                <li>Download signed documents</li>
                <li>Monitor signing progress</li>
            </ul>

            <h3>Signer Management</h3>
            <ul>
                <li>Create and manage signers</li>
                <li>Search by email</li>
                <li>Store metadata</li>
            </ul>

            <h3>Assignment Management</h3>
            <ul>
                <li>Request signatures</li>
                <li>Cancel requests</li>
                <li>Resend notifications</li>
            </ul>

            <h3>Webhook Support</h3>
            <ul>
                <li>Signature verification</li>
                <li>Event handling</li>
                <li>Automatic registration</li>
            </ul>
        </div>

        <div class="card">
            <h2>Documentation</h2>
            <p>Explore the complete documentation:</p>
            <div>
                <a href="https://github.com/your-org/assinafy-php-sdk" class="btn">GitHub Repository</a>
                <a href="INSTALLATION.md" class="btn">Installation Guide</a>
                <a href="EXAMPLES.md" class="btn">Code Examples</a>
                <a href="https://api.assinafy.com.br/v1/docs" class="btn">API Documentation</a>
            </div>
        </div>

        <div class="card">
            <h2>System Status</h2>
            <pre><code>PHP Version: <?php echo PHP_VERSION; ?>

Loaded Extensions:
<?php
$required = ['json', 'curl', 'openssl'];
foreach ($required as $ext) {
    $status = extension_loaded($ext) ? 'Loaded' : 'Missing';
    echo "  {$ext}: {$status}\n";
}
?>

SDK Installation:
<?php
$sdkClass = class_exists('Assinafy\SDK\AssinafyClient');
echo "  AssinafyClient: " . ($sdkClass ? 'Loaded' : 'Not found') . "\n";
?>
</code></pre>
        </div>
    </div>

    <footer>
        <div class="container">
            <p>&copy; 2024 Assinafy PHP SDK. MIT License.</p>
            <p>Built with PHP 8.3+ following PSR standards and SOLID principles.</p>
        </div>
    </footer>
</body>
</html>

