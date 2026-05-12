<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Http;

use Assinafy\SDK\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testParsesJsonBody(): void
    {
        $response = new Response(200, [], '{"status":200,"message":"","data":{"id":"abc"}}');

        $this->assertTrue($response->isSuccess());
        $this->assertFalse($response->isClientError());
        $this->assertFalse($response->isServerError());
        $this->assertSame(['status' => 200, 'message' => '', 'data' => ['id' => 'abc']], $response->getData());
    }

    public function testNullDataOnEmptyBody(): void
    {
        $response = new Response(204, [], '');
        $this->assertNull($response->getData());
    }

    public function testNullDataOnInvalidJson(): void
    {
        $response = new Response(200, [], 'not-json');
        $this->assertNull($response->getData());
    }

    public function testClassifiesErrors(): void
    {
        $this->assertTrue((new Response(404, [], ''))->isClientError());
        $this->assertTrue((new Response(500, [], ''))->isServerError());
    }
}
