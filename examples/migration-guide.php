<?php

declare(strict_types=1);

use Duyler\HttpServer\Config\ServerConfig;
use Duyler\HttpServer\Dto\RequestData;
use Duyler\HttpServer\Dto\ResponseData;
use Duyler\HttpServer\Server;
use Nyholm\Psr7\Response;

/**
 * Migration Guide: Request ID Mechanism
 *
 * This example shows how to migrate from the old API to the new
 * Request ID mechanism. Since this project hasn't been published yet,
 * migration is straightforward.
 *
 * TABLE OF CONTENTS
 * =================
 *
 * 1. API Changes Overview
 * 2. Basic Migration (Sequential Processing)
 * 3. Advanced Migration (Parallel Processing)
 * 4. Migration Checklist
 *
 * ============================================================================
 * 1. API CHANGES OVERVIEW
 * ============================================================================
 *
 * Before (Old API):
 * -----------------
 *   getRequest(): ?ServerRequestInterface
 *     - Returns PSR-7 request object
 *     - No request tracking
 *
 *   respond(ResponseInterface $response): void
 *     - Sends response to last request
 *     - Sequential only (FIFO)
 *
 * After (New API):
 * ----------------
 *   getRequest(): ?RequestData
 *     - Returns RequestData object containing:
 *       - id: Unique request identifier (e.g., "req_1")
 *       - request: PSR-7 ServerRequestInterface
 *       - connectionId: Internal connection ID
 *
 *   respond(ResponseData $responseData): void
 *     - Sends response bound to specific request
 *     - Supports parallel processing
 *     - Out-of-order responses allowed
 *
 * ============================================================================
 * 2. BASIC MIGRATION (Sequential Processing)
 * ============================================================================
 */

// ============================================================================
// BEFORE: Old API (Hypothetical - project not published)
// ============================================================================

// Note: This is shown for illustration. The project hasn't been published,
// so there's no actual "old API" to migrate from.

// Old approach (hypothetical):
// while ($server->hasRequest()) {
//     $request = $server->getRequest(); // ServerRequestInterface
//
//     $response = new Response(200, [], 'OK');
//
//     $server->respond($response); // Response sent to last request
// }

// ============================================================================
// AFTER: New API - Option 1: Using convenience method (Recommended)
// ============================================================================

function exampleNewApiConvenience(): void
{
    $config = new ServerConfig(host: '0.0.0.0', port: 8080);
    $server = new Server($config);
    $server->start();

    while ($server->hasRequest()) {
        $requestData = $server->getRequest(); // RequestData

        if ($requestData === null) {
            continue;
        }

        $response = new Response(200, [], 'OK');

        $server->respond($requestData->respond($response));
    }
}

// ============================================================================
// AFTER: New API - Option 2: Explicit ResponseData creation
// ============================================================================

function exampleNewApiExplicit(): void
{
    $config = new ServerConfig(host: '0.0.0.0', port: 8080);
    $server = new Server($config);
    $server->start();

    while ($server->hasRequest()) {
        $requestData = $server->getRequest(); // RequestData

        if ($requestData === null) {
            continue;
        }

        $response = new Response(200, [], 'OK');

        $responseData = new ResponseData($requestData->id, $response);

        $server->respond($responseData);
    }
}

/**
 * ============================================================================
 * 3. ADVANCED MIGRATION (Parallel Processing)
 * ============================================================================
 *
 * The Request ID mechanism enables parallel processing with Fibers.
 * This is a NEW capability that wasn't possible before.
 */

function exampleParallelProcessing(): void
{
    $config = new ServerConfig(host: '0.0.0.0', port: 8080);
    $server = new Server($config);
    $server->start();

    $actors = [];

    while (true) {
        if (!$server->hasRequest()) {
            foreach ($actors as $key => $fiber) {
                if ($fiber->isTerminated()) {
                    unset($actors[$key]);
                    continue;
                }

                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            }

            usleep(1000);
            continue;
        }

        $requestData = $server->getRequest();

        if ($requestData === null) {
            continue;
        }

        $fiber = new Fiber(function () use ($server, $requestData): void {
            $response = processRequest($requestData->request);

            $server->respond($requestData->respond($response));
        });

        $fiber->start();
        $actors[] = $fiber;
    }
}

function processRequest(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface
{
    usleep(random_int(100000, 500000));

    return new Response(200, [], 'Processed');
}

/**
 * ============================================================================
 * 4. MIGRATION CHECKLIST
 * ============================================================================
 *
 * □ Step 1: Update getRequest() calls
 *   - Change: $request = $server->getRequest()
 *   - To:     $requestData = $server->getRequest()
 *   - Access request via: $requestData->request
 *
 * □ Step 2: Update respond() calls
 *   - Change: $server->respond($response)
 *   - To:     $server->respond($requestData->respond($response))
 *
 * □ Step 3: Handle null values
 *   - Always check if getRequest() returns null
 *   - This handles race conditions gracefully
 *
 * □ Step 4: (Optional) Implement parallel processing
 *   - Use Fibers for concurrent request handling
 *   - See parallel-processing.php example
 *
 * □ Step 5: Update tests
 *   - Mock RequestData instead of ServerRequestInterface
 *   - Test request ID binding
 *   - Test parallel scenarios
 */

// ============================================================================
// COMPARISON TABLE
// ============================================================================

/**
 * ┌─────────────────────┬──────────────────────┬──────────────────────────┐
 * │ Aspect              │ Old API              │ New API                  │
 * ├─────────────────────┼──────────────────────┼──────────────────────────┤
 * │ getRequest() return │ ServerRequestInterface│ RequestData              │
 * │ respond() param     │ ResponseInterface    │ ResponseData             │
 * │ Request tracking    │ None                 │ Unique ID per request    │
 * │ Processing model    │ Sequential only      │ Sequential OR Parallel   │
 * │ Response order      │ FIFO (strict)        │ Any order (flexible)     │
 * │ Slow request impact │ Blocks all others    │ Independent processing   │
 * │ Fiber support       │ Limited              │ Full support             │
 * └─────────────────────┴──────────────────────┴──────────────────────────┘
 */

// ============================================================================
// BEST PRACTICES
// ============================================================================

/**
 * 1. ALWAYS check for null
 *    $requestData = $server->getRequest();
 *    if ($requestData === null) {
 *        continue; // or break, depending on your logic
 *    }
 *
 * 2. USE convenience method
 *    $server->respond($requestData->respond($response));
 *    // More readable than:
 *    $server->respond(new ResponseData($requestData->id, $response));
 *
 * 3. LOG request IDs for debugging
 *    echo "Processing request: {$requestData->id}\n";
 *
 * 4. CONSIDER parallel processing for:
 *    - I/O-bound operations (database, API calls)
 *    - Mixed workloads (slow + fast requests)
 *    - High-concurrency scenarios
 *
 * 5. AVOID parallel processing for:
 *    - CPU-bound operations (no benefit)
 *    - Simple request/response patterns
 *    - Stateful operations that need strict ordering
 */

echo "This is a reference file. Run examples/request-id-basic.php or examples/parallel-processing.php instead.\n";
