<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Http;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\NetworkException;
use Assinafy\SDK\Http\GuzzleHttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\Exception\ConnectException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;

final class GuzzleHttpClientTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $transactions = [];

    /**
     * Build a client whose Guzzle handler is a MockHandler, so requests are recorded
     * rather than sent. Possible because GuzzleHttpClient accepts an injected client.
     *
     * @param array<int, mixed> $responses
     */
    private function client(array $responses, ?AbstractLogger $logger = null): GuzzleHttpClient
    {
        $this->transactions = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->transactions));

        $guzzle = new Client([
            'base_uri' => 'https://api.example.com/v1/',
            'handler' => $stack,
        ]);

        return new GuzzleHttpClient(
            new Configuration('key', 'acc', 'https://api.example.com/v1'),
            $logger,
            $guzzle
        );
    }

    private function lastRequest(): RequestInterface
    {
        return $this->transactions[count($this->transactions) - 1]['request'];
    }

    public function testGetSendsQueryStringAndParsesEnvelope(): void
    {
        $client = $this->client([new GuzzleResponse(200, [], '{"status":200,"data":[{"id":"d1"}]}')]);

        $response = $client->get(
            'accounts/acc/documents',
            ['per-page' => 2, 'page' => 1],
            ['user-agent' => 'caller-override']
        );

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/v1/accounts/acc/documents', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('per-page=2&page=1', $this->lastRequest()->getUri()->getQuery());
        $this->assertSame([['id' => 'd1']], $response->getData()['data']);
        $this->assertSame('key', $this->lastRequest()->getHeaderLine('X-Api-Key'));
        $this->assertSame(
            'Assinafy-PHP-SDK/v' . Configuration::SDK_VERSION,
            $this->lastRequest()->getHeaderLine('User-Agent')
        );
    }

    public function testExplicitBearerReplacesConfiguredApiKey(): void
    {
        $client = $this->client([new GuzzleResponse(200, [], '{"status":200,"data":{"id":"u1"}}')]);

        $client->get('users/self', [], ['Authorization' => 'Bearer access-token']);

        $request = $this->lastRequest();
        $this->assertSame('Bearer access-token', $request->getHeaderLine('Authorization'));
        $this->assertFalse($request->hasHeader('X-Api-Key'));
        $this->assertSame(
            'Assinafy-PHP-SDK/v' . Configuration::SDK_VERSION,
            $request->getHeaderLine('User-Agent')
        );
    }

    public function testPublicAndSignerRequestsDoNotInheritWorkspaceCredentials(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, [], '{"status":200,"data":{}}'),
            new GuzzleResponse(200, [], '{"status":200,"data":{}}'),
            new GuzzleResponse(200, [], '{"status":200,"data":{}}'),
        ]);

        $client->get('public/documents/d1');
        $this->assertFalse($this->lastRequest()->hasHeader('X-Api-Key'));
        $this->assertSame(
            'Assinafy-PHP-SDK/v' . Configuration::SDK_VERSION,
            $this->lastRequest()->getHeaderLine('User-Agent')
        );

        $client->get('signers/self', ['signer-access-code' => 'signer-code']);
        $this->assertFalse($this->lastRequest()->hasHeader('X-Api-Key'));
        $this->assertSame(
            'Assinafy-PHP-SDK/v' . Configuration::SDK_VERSION,
            $this->lastRequest()->getHeaderLine('User-Agent')
        );

        $client->post('login', ['email' => 'a@example.com', 'password' => 'secret']);
        $this->assertFalse($this->lastRequest()->hasHeader('X-Api-Key'));
        $this->assertSame(
            'Assinafy-PHP-SDK/v' . Configuration::SDK_VERSION,
            $this->lastRequest()->getHeaderLine('User-Agent')
        );
    }

    public function testAccountFieldValidationRetainsWorkspaceCredentialWithSignerContext(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, [], '{"status":200,"data":{"success":true}}'),
        ]);

        $client->post(
            'accounts/acc/fields/f1/validate',
            ['value' => 'x'],
            [],
            ['signer-access-code' => 'signer-code']
        );

        $this->assertSame('key', $this->lastRequest()->getHeaderLine('X-Api-Key'));
    }

    public function testOwnerEstimateAndSignerSignWithSimilarPathsUseCorrectCredentials(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, [], '{"status":200,"data":{}}'),
            new GuzzleResponse(200, [], '{"status":200,"data":{}}'),
        ]);

        $client->post('documents/d1/assignments/estimate-cost', ['signers' => []]);
        $this->assertSame('key', $this->lastRequest()->getHeaderLine('X-Api-Key'));

        $client->post(
            'documents/d1/assignments/a1',
            [],
            [],
            ['signer-access-code' => 'signer-code']
        );
        $this->assertFalse($this->lastRequest()->hasHeader('X-Api-Key'));
    }

    public function testPatchSendsJsonBodyWithContentType(): void
    {
        $client = $this->client([new GuzzleResponse(200, [], '{"status":200,"data":{"name":"new.pdf"}}')]);

        $client->patch('documents/d1', ['name' => 'new.pdf']);

        $request = $this->lastRequest();
        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame('{"name":"new.pdf"}', (string) $request->getBody());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testDeleteSendsNoBodyByDefault(): void
    {
        $client = $this->client([new GuzzleResponse(200, [], '{"status":200,"data":null}')]);

        $client->delete('documents/d1');

        $this->assertSame('', (string) $this->lastRequest()->getBody());
    }

    public function testPostAndPutSendNoBodyWhenDataIsOmitted(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, [], '{"status":200,"data":null}'),
            new GuzzleResponse(200, [], '{"status":200,"data":null}'),
        ]);

        $client->post('webhooks/history/retry');
        $post = $this->lastRequest();
        $this->assertSame('', (string) $post->getBody());
        $this->assertSame('', $post->getHeaderLine('Content-Type'));

        $client->put('webhooks/inactivate');
        $put = $this->lastRequest();
        $this->assertSame('', (string) $put->getBody());
        $this->assertSame('', $put->getHeaderLine('Content-Type'));
    }

    public function testExplicitEmptyJsonArrayIsPreserved(): void
    {
        $client = $this->client([new GuzzleResponse(200, [], '{"status":200,"data":null}')]);

        $client->post('documents/d1/assignments/a1', []);

        $this->assertSame('[]', (string) $this->lastRequest()->getBody());
        $this->assertSame('application/json', $this->lastRequest()->getHeaderLine('Content-Type'));
    }

    public function testDeleteCanSendAJsonBody(): void
    {
        $client = $this->client([new GuzzleResponse(200, [], '{"status":200,"data":null}')]);

        $client->delete('accounts/acc', [], [], ['force' => true]);

        $request = $this->lastRequest();
        $this->assertSame('{"force":true}', (string) $request->getBody());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    /**
     * The base_uri must keep its trailing slash or RFC 3986 resolution drops `/v1`.
     */
    public function testRelativeUrisResolveUnderTheVersionedBasePath(): void
    {
        $client = $this->client([new GuzzleResponse(200, [], '{"status":200,"data":[]}')]);

        $client->get('documents/statuses');

        $this->assertSame('/v1/documents/statuses', $this->lastRequest()->getUri()->getPath());
    }

    public function testRequestUrisCannotEscapeTheConfiguredApiBaseUrl(): void
    {
        $client = $this->client([]);

        foreach (
            [
                'https://untrusted.example/collect',
                '//untrusted.example/collect',
                '/documents/statuses',
                '../documents/statuses',
                'accounts/../users',
                '%2e%2e/documents/statuses',
            ] as $uri
        ) {
            try {
                $client->get($uri);
                $this->fail("Expected URI to be rejected: {$uri}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('relative', $e->getMessage());
            }
        }

        $this->assertSame([], $this->transactions, 'Rejected URIs must never reach Guzzle');
    }

    public function testInjectedGuzzleClientCannotDefineDefaultAuthenticationHeaders(): void
    {
        foreach (['Authorization' => 'Bearer injected-secret', 'X-Api-Key' => 'injected-secret'] as $name => $value) {
            $guzzle = new Client([
                'base_uri' => 'https://api.example.com/v1/',
                'headers' => [$name => $value],
            ]);

            try {
                new GuzzleHttpClient(new Configuration('key', 'acc'), null, $guzzle);
                $this->fail("Expected injected {$name} header to be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('default authentication headers', $e->getMessage());
            }
        }
    }

    public function testInjectedGuzzleClientMustUseConfiguredBaseUri(): void
    {
        $guzzle = new Client(['base_uri' => 'https://untrusted.example/v1/']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('base URI must match');

        new GuzzleHttpClient(new Configuration('key', 'acc'), null, $guzzle);
    }

    public function testInjectedGuzzleClientAcceptsEquivalentBaseUriCasing(): void
    {
        $guzzle = new Client([
            'base_uri' => 'https://api.example.com/v1/',
            'handler' => new MockHandler([
                new GuzzleResponse(200, [], '{"status":200,"data":[]}'),
            ]),
        ]);
        $client = new GuzzleHttpClient(
            new Configuration('key', 'acc', 'HTTPS://API.EXAMPLE.COM/v1'),
            null,
            $guzzle
        );

        $this->assertSame(200, $client->get('documents/statuses')->getStatusCode());
    }

    public function testInjectedGuzzleClientCannotFollowRedirectsWithApiKey(): void
    {
        $client = $this->client([
            new GuzzleResponse(302, ['Location' => 'https://untrusted.example/collect']),
            new GuzzleResponse(200, [], '{"status":200,"data":[]}'),
        ]);

        try {
            $client->get('documents/statuses');
            $this->fail('A redirect response must not be followed or treated as success');
        } catch (ApiException $e) {
            $this->assertSame(302, $e->getStatusCode());
        }

        $this->assertCount(1, $this->transactions, 'The redirect target must never receive a request');
        $this->assertSame('key', $this->lastRequest()->getHeaderLine('X-Api-Key'));
        $this->assertSame('api.example.com', $this->lastRequest()->getUri()->getHost());
    }

    public function testPaginationHeadersSurviveOntoTheResponse(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, ['X-Pagination-Total-Count' => '17'], '{"status":200,"data":[]}'),
        ]);

        $response = $client->get('accounts/acc/documents');

        $this->assertSame(['17'], $response->getHeaders()['X-Pagination-Total-Count']);
    }

    public function testHttpErrorBecomesApiException(): void
    {
        $client = $this->client([
            new GuzzleResponse(
                404,
                ['X-Request-Id' => 'request-123', 'Retry-After' => '5'],
                '{"status":404,"data":null,"message":"Documento não encontrado."}'
            ),
        ]);

        try {
            $client->get('documents/nope');
            $this->fail('Expected an API exception');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('request-123', $e->getResponseHeaderLine('x-request-id'));
            $this->assertSame('5', $e->getResponseHeaderLine('Retry-After'));
            $this->assertNotNull($e->getPrevious());
        }
    }

    public function testConnectionFailureBecomesNetworkException(): void
    {
        $client = $this->client([new ConnectException('timeout', new Request('GET', 'documents'))]);

        $this->expectException(NetworkException::class);
        $client->get('documents');
    }

    public function testResponseBearingTransferFailureRemainsNetworkException(): void
    {
        $failure = RequestException::create(
            new Request('GET', 'documents'),
            new GuzzleResponse(200, [], '{"status":200,"data":[]}')
        );
        $client = $this->client([$failure]);

        $this->expectException(NetworkException::class);
        $client->get('documents');
    }

    public function testRequestFailureWithoutResponseBecomesNetworkException(): void
    {
        $client = $this->client([
            new RequestException('request body failed', new Request('GET', 'documents')),
        ]);

        $this->expectException(NetworkException::class);
        $client->get('documents');
    }

    public function testSuccessfulMalformedJsonResponseBecomesNetworkException(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], '{"truncated":'),
        ]);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('invalid JSON response');

        $client->get('documents/d1');
    }

    public function testSuccessfulHtmlResponseBecomesNetworkException(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, ['Content-Type' => 'text/html'], '<h1>proxy error</h1>'),
        ]);

        $this->expectException(NetworkException::class);
        $client->get('documents/d1');
    }

    public function testSuccessfulMalformedDataEnvelopeBecomesNetworkException(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], '{"status":200,"data":"wrong"}'),
        ]);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('invalid data envelope');

        $client->get('documents/d1');
    }

    public function testSuccessfulHttpResponseWithErrorEnvelopeBecomesApiException(): void
    {
        $client = $this->client([
            new GuzzleResponse(
                200,
                ['X-Request-Id' => 'envelope-request'],
                '{"status":422,"message":"Validation failed","data":[]}'
            ),
        ]);

        try {
            $client->get('documents/d1');
            $this->fail('Expected an API exception');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('Validation failed', $e->getMessage());
            $this->assertSame('envelope-request', $e->getResponseHeaderLine('X-Request-Id'));
        }
    }

    public function testStatusOnlyErrorEnvelopeBecomesApiException(): void
    {
        $client = $this->client([new GuzzleResponse(200, [], '{"status":422}')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(422);

        $client->get('documents/d1');
    }

    public function testSuccessfulHttpResponseRejectsMalformedEnvelopeStatus(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, [], '{"status":"ok","message":"wrong type","data":[]}'),
        ]);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('invalid response envelope status');

        $client->get('documents/d1');
    }

    public function testDocumentedBinaryResponseContentTypeIsAccepted(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, ['Content-Type' => 'application/pdf'], '%PDF-binary'),
        ]);

        $this->assertSame('%PDF-binary', $client->get('documents/d1/download/original')->getBody());
    }

    public function testUploadFileRejectsAMissingFile(): void
    {
        $client = $this->client([]);

        $this->expectException(\InvalidArgumentException::class);
        $client->uploadFile('accounts/acc/documents', '/no/such/file.pdf');
    }

    public function testUploadFileSendsMultipartWithABoundary(): void
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'up');
        $this->assertIsString($temporaryPath);
        $pdf = $temporaryPath . '.pdf';
        rename($temporaryPath, $pdf);
        file_put_contents($pdf, '%PDF-1.4 fixture');

        $client = $this->client([new GuzzleResponse(200, [], '{"status":200,"data":{"id":"d1"}}')]);

        try {
            $client->uploadFile('accounts/acc/documents', $pdf);

            $contentType = $this->lastRequest()->getHeaderLine('Content-Type');
            $this->assertStringStartsWith('multipart/form-data', $contentType);
            $this->assertStringContainsString('boundary=', $contentType, 'Boundary must not be stripped');
            $this->assertStringContainsString('name="file"', (string) $this->lastRequest()->getBody());
            $this->assertSame(
                'Assinafy-PHP-SDK/v' . Configuration::SDK_VERSION,
                $this->lastRequest()->getHeaderLine('User-Agent')
            );
        } finally {
            @unlink($pdf);
        }
    }

    public function testUploadFileClosesItsHandleWhenMultipartEncodingFails(): void
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'up');
        $this->assertIsString($temporaryPath);
        $pdf = $temporaryPath . '.pdf';
        rename($temporaryPath, $pdf);
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
        $client = $this->client([]);
        $streamsBefore = count(get_resources('stream'));

        try {
            $client->uploadFile('accounts/acc/documents', $pdf, ['invalid_json' => [NAN]]);
            $this->fail('Expected JSON encoding to fail');
        } catch (\JsonException) {
            $this->assertCount($streamsBefore, get_resources('stream'));
        } finally {
            @unlink($pdf);
        }
    }

    public function testDebugDumpDoesNotExposeConfiguredCredentials(): void
    {
        $secret = 'debug-dump-api-secret';
        $client = new GuzzleHttpClient(new Configuration($secret, 'debug-account'));

        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();

        $this->assertStringNotContainsString($secret, $dump);
        $this->assertStringNotContainsString('debug-account', $dump);
    }

    public function testPostRawSendsBodyVerbatimWithCustomContentType(): void
    {
        $client = $this->client([new GuzzleResponse(200, [], '{"status":200,"data":null}')]);

        $client->postRaw('signature', "\x89PNG-bytes", 'image/png', ['signer-access-code' => 'abc']);

        $request = $this->lastRequest();
        $this->assertSame("\x89PNG-bytes", (string) $request->getBody());
        $this->assertSame('image/png', $request->getHeaderLine('Content-Type'));
        $this->assertSame('signer-access-code=abc', $request->getUri()->getQuery());
        $this->assertSame(
            'Assinafy-PHP-SDK/v' . Configuration::SDK_VERSION,
            $request->getHeaderLine('User-Agent')
        );
    }

    /**
     * Regression test for a real credential leak: before 2.0.0 the client logged the whole
     * request options array, so `auth()->login()` wrote the plaintext password and every
     * bearer-authenticated call wrote its token straight into the host application's logs.
     */
    public function testDebugLogsNeverContainCredentialsOrPii(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var array<int, string> */
            public array $lines = [];

            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = $message . ' ' . json_encode($context);
            }
        };

        $client = $this->client(
            [new GuzzleResponse(
                200,
                [],
                '{"status":200,"data":{"access_token":"jwt-secret-value",'
                . '"email":"response-pii@example.com",'
                . '"signing_urls":[{"url":"https://app.example/sign?signer-access-code=url-secret-value"}]}}'
            )],
            $logger
        );

        $client->post('login', ['email' => 'request-pii@example.com', 'password' => 'hunter2'], [
            'Authorization' => 'Bearer bearer-secret-value',
        ]);

        $log = implode("\n", $logger->lines);

        $this->assertNotSame('', $log, 'Debug logging must still happen');
        $this->assertStringNotContainsString('hunter2', $log, 'Password leaked into logs');
        $this->assertStringNotContainsString('bearer-secret-value', $log, 'Bearer token leaked into logs');
        $this->assertStringNotContainsString('jwt-secret-value', $log, 'Response access_token leaked into logs');
        $this->assertStringNotContainsString('url-secret-value', $log, 'Signing URL access code leaked into logs');
        $this->assertStringNotContainsString('request-pii@example.com', $log, 'Request PII leaked into logs');
        $this->assertStringNotContainsString('response-pii@example.com', $log, 'Response PII leaked into logs');
        $this->assertStringContainsString('json_keys', $log, 'Structural request context should remain useful');
    }

    public function testRequestExceptionLogsNeverContainSignerAccessCodes(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = (string) $message . ' ' . json_encode($context);
            }
        };
        $secret = 'signer-secret-that-must-never-be-logged';
        $client = $this->client([
            new GuzzleResponse(401, [], '{"status":401,"message":"Invalid credentials"}'),
        ], $logger);

        try {
            $client->get('signers/self', ['signer-access-code' => $secret]);
            $this->fail('Expected the 401 response to throw');
        } catch (ApiException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertStringNotContainsString($secret, $e->getMessage());
            $this->assertNotNull($e->getPrevious());
            $this->assertStringNotContainsString($secret, $e->getPrevious()->getMessage());
            $this->assertNotInstanceOf(\GuzzleHttp\Exception\RequestException::class, $e->getPrevious());
        }

        $this->assertStringNotContainsString($secret, implode("\n", $logger->lines));
    }
}
