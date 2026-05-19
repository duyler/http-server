<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Dto;

use Duyler\HttpServer\Dto\RequestData;
use Duyler\HttpServer\Dto\ResponseData;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RequestDataTest extends TestCase
{
    #[Test]
    public function it_creates_request_data_with_all_fields(): void
    {
        $request = new ServerRequest('GET', '/test');
        $requestData = new RequestData('req_123', $request, 42);

        self::assertSame('req_123', $requestData->id);
        self::assertSame($request, $requestData->request);
        self::assertSame(42, $requestData->connectionId);
    }

    #[Test]
    public function it_creates_response_data_via_respond_method(): void
    {
        $request = new ServerRequest('GET', '/test');
        $requestData = new RequestData('req_123', $request, 42);

        $response = new Response(200, [], 'OK');
        $responseData = $requestData->respond($response);

        self::assertInstanceOf(ResponseData::class, $responseData);
        self::assertSame('req_123', $responseData->requestId);
        self::assertSame($response, $responseData->response);
    }

    #[Test]
    public function it_is_immutable(): void
    {
        $request = new ServerRequest('GET', '/test');
        $requestData = new RequestData('req_123', $request, 42);

        self::assertSame('req_123', $requestData->id);
        self::assertSame($request, $requestData->request);
        self::assertSame(42, $requestData->connectionId);
    }
}
