<?php

declare(strict_types=1);

namespace Duyler\HttpServer\Tests\Unit\Dto;

use Duyler\HttpServer\Dto\ResponseData;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ResponseDataTest extends TestCase
{
    public function testItCreatesResponseDataWithAllFields(): void
    {
        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('req_456', $response);

        self::assertSame('req_456', $responseData->requestId);
        self::assertSame($response, $responseData->response);
    }

    public function testItIsImmutable(): void
    {
        $response = new Response(200, [], 'OK');
        $responseData = new ResponseData('req_789', $response);

        self::assertSame('req_789', $responseData->requestId);
        self::assertSame($response, $responseData->response);
    }
}
