<?php

namespace App\Http\Controllers;

use App\Mcp\McpServer;
use App\Mcp\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * HTTP transport for the WARM MCP server (JSON-RPC over POST /mcp).
 *
 * Stateless Streamable HTTP: every POST carries one JSON-RPC message (or a
 * batch) and gets the response as JSON. Notifications and client responses
 * produce no body (202 Accepted). The JSON-RPC semantics live in McpServer so
 * the stdio and HTTP transports stay in lockstep.
 */
final class McpController
{
    public function __invoke(Request $request): JsonResponse|Response
    {
        $payload = json_decode((string) $request->getContent(), true);

        if (! is_array($payload)) {
            return $this->error(null, -32700, 'Parse error', 400);
        }

        $server = new McpServer(app(ToolRegistry::class));

        // JSON-RPC batch: an array of messages, answered with an array.
        if (array_is_list($payload)) {
            $responses = [];
            foreach ($payload as $message) {
                if (! is_array($message)) {
                    continue;
                }

                $response = $server->handleRequest($message);
                if ($response !== null) {
                    $responses[] = $response;
                }
            }

            return $responses === [] ? response()->noContent(202) : response()->json($responses);
        }

        $response = $server->handleRequest($payload);

        return $response === null ? response()->noContent(202) : response()->json($response);
    }

    private function error(mixed $id, int $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
