<?php

namespace App\Mcp;

use App\Exceptions\ProviderException;
use Throwable;

/**
 * Minimal Model Context Protocol server over stdio (JSON-RPC 2.0).
 * All logging goes to STDERR so it never corrupts the protocol on STDOUT.
 */
final class McpServer
{
    private const PROTOCOL_VERSION = '2024-11-05';

    public function __construct(private readonly ToolRegistry $tools) {}

    public function run(): void
    {
        $stdin = fopen('php://stdin', 'r');
        if ($stdin === false) {
            $this->log('Failed to open STDIN');

            return;
        }

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $request = json_decode($line, true);
            if (! is_array($request)) {
                continue;
            }

            $response = $this->handle($request);
            if ($response !== null) {
                $this->write($response);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>|null
     */
    private function handle(array $request): ?array
    {
        $id = $request['id'] ?? null;
        $method = (string) ($request['method'] ?? '');
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        // Notifications carry no id and expect no response.
        if ($id === null && str_starts_with($method, 'notifications/')) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'ping' => (object) [],
                'tools/list' => ['tools' => $this->tools->definitions()],
                'tools/call' => $this->callTool($params),
                default => null,
            };
        } catch (Throwable $e) {
            return $this->error($id, -32603, $e->getMessage());
        }

        if ($result === null) {
            return $this->error($id, -32601, "Method not found: {$method}");
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $version = is_string($params['protocolVersion'] ?? null)
            ? $params['protocolVersion']
            : self::PROTOCOL_VERSION;

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => 'warm', 'version' => '1.0.0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $result = $this->tools->call($name, $arguments);

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE)]],
                'isError' => is_array($result) && ($result['status'] ?? null) === 'error',
            ];
        } catch (Throwable $e) {
            // A provider failure on message.send is an expected domain error:
            // map it back to the tool's status:error contract instead of a
            // generic isError. Any other exception stays an unexpected error.
            if ($e instanceof ProviderException && $name === 'message.send') {
                return [
                    'content' => [['type' => 'text', 'text' => json_encode([
                        'status' => 'error',
                        'providerMessageId' => null,
                        'error' => $e->getMessage(),
                    ], JSON_UNESCAPED_UNICODE)]],
                    'isError' => true,
                ];
            }

            $this->log("Tool {$name} failed: {$e->getMessage()}");

            return [
                'content' => [['type' => 'text', 'text' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE)]],
                'isError' => true,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function write(array $payload): void
    {
        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        fflush(STDOUT);
    }

    private function log(string $message): void
    {
        fwrite(STDERR, "[warm-mcp] {$message}\n");
    }
}
