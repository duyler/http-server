<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Dto;

use Duyler\HttpServer\Dto\RequestData;
use Duyler\HttpServer\Dto\ResponseData;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class RequestDataTest extends TestCase
{
    public function testItCreatesRequestDataWithAllFields(): void
    {
        $request = new ServerRequest('GET', '/test');
        $requestData = new RequestData('req_123', $request, 42);

        self::assertSame('req_123', $requestData->id);
        self::assertSame($request, $requestData->request);
        self::assertSame(42, $requestData->connectionId);
    }

    public function testItCreatesResponseDataViaRespondMethod(): void
    {
        $request = new ServerRequest('GET', '/test');
        $requestData = new RequestData('req_123', $request, 42);

        $response = new Response(200, [], 'OK');
        $responseData = $requestData->respond($response);

        self::assertInstanceOf(ResponseData::class, $responseData);
        self::assertSame('req_123', $responseData->requestId);
        self::assertSame($response, $responseData->response);
    }

    public function testItIsImmutable(): void
    {
        $request = new ServerRequest('GET', '/test');
        $requestData = new RequestData('req_123', $request, 42);

        self::assertSame('req_123', $requestData->id);
        self::assertSame($request, $requestData->request);
        self::assertSame(42, $requestData->connectionId);
    }
}
