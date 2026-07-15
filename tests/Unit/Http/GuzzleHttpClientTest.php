<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Http;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\NetworkException;
use Assinafy\SDK\Http\GuzzleHttpClient;
use GuzzleHttp\Client;
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
            'headers' => ['X-Api-Key' => 'key'],
        ]);

        return new GuzzleHttpClient(new Configuration('key', 'acc'), $logger, $guzzle);
    }

    private function lastRequest(): RequestInterface
    {
        return $this->transactions[count($this->transactions) - 1]['request'];
    }

    public function testGetSendsQueryStringAndParsesEnvelope(): void
    {
        $client = $this->client([new GuzzleResponse(200, [], '{"status":200,"data":[{"id":"d1"}]}')]);

        $response = $client->get('accounts/acc/documents', ['per-page' => 2, 'page' => 1]);

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('/v1/accounts/acc/documents', $this->lastRequest()->getUri()->getPath());
        $this->assertSame('per-page=2&page=1', $this->lastRequest()->getUri()->getQuery());
        $this->assertSame([['id' => 'd1']], $response->getData()['data']);
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
            new GuzzleResponse(404, [], '{"status":404,"data":null,"message":"Documento não encontrado."}'),
        ]);

        $this->expectException(ApiException::class);
        $client->get('documents/nope');
    }

    public function testConnectionFailureBecomesNetworkException(): void
    {
        $client = $this->client([new ConnectException('timeout', new Request('GET', 'documents'))]);

        $this->expectException(NetworkException::class);
        $client->get('documents');
    }

    public function testUploadFileRejectsAMissingFile(): void
    {
        $client = $this->client([]);

        $this->expectException(\InvalidArgumentException::class);
        $client->uploadFile('accounts/acc/documents', '/no/such/file.pdf');
    }

    public function testUploadFileSendsMultipartWithABoundary(): void
    {
        $pdf = tempnam(sys_get_temp_dir(), 'up') . '.pdf';
        file_put_contents($pdf, '%PDF-1.4 fixture');

        $client = $this->client([new GuzzleResponse(200, [], '{"status":200,"data":{"id":"d1"}}')]);

        try {
            $client->uploadFile('accounts/acc/documents', $pdf);

            $contentType = $this->lastRequest()->getHeaderLine('Content-Type');
            $this->assertStringStartsWith('multipart/form-data', $contentType);
            $this->assertStringContainsString('boundary=', $contentType, 'Boundary must not be stripped');
            $this->assertStringContainsString('name="file"', (string) $this->lastRequest()->getBody());
        } finally {
            @unlink($pdf);
        }
    }

    public function testPostRawSendsBodyVerbatimWithCustomContentType(): void
    {
        $client = $this->client([new GuzzleResponse(200, [], '{"status":200,"data":null}')]);

        $client->postRaw('signature', "\x89PNG-bytes", 'image/png', ['signer-access-code' => 'abc']);

        $request = $this->lastRequest();
        $this->assertSame("\x89PNG-bytes", (string) $request->getBody());
        $this->assertSame('image/png', $request->getHeaderLine('Content-Type'));
        $this->assertSame('signer-access-code=abc', $request->getUri()->getQuery());
    }

    /**
     * Regression test for a real credential leak: before 2.0.0 the client logged the whole
     * request options array, so `auth()->login()` wrote the plaintext password and every
     * bearer-authenticated call wrote its token straight into the host application's logs.
     */
    public function testDebugLogsNeverContainCredentials(): void
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
            [new GuzzleResponse(200, [], '{"status":200,"data":{"access_token":"jwt-secret-value"}}')],
            $logger
        );

        $client->post('login', ['email' => 'a@b.com', 'password' => 'hunter2'], [
            'Authorization' => 'Bearer bearer-secret-value',
        ]);

        $log = implode("\n", $logger->lines);

        $this->assertNotSame('', $log, 'Debug logging must still happen');
        $this->assertStringNotContainsString('hunter2', $log, 'Password leaked into logs');
        $this->assertStringNotContainsString('bearer-secret-value', $log, 'Bearer token leaked into logs');
        $this->assertStringNotContainsString('jwt-secret-value', $log, 'Response access_token leaked into logs');
        $this->assertStringContainsString('a@b.com', $log, 'Non-secret context should remain useful');
    }
}
