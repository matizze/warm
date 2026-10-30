<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpHttpTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function mcp(array $payload, array $headers = []): TestResponse
    {
        return $this->postJson('/mcp', $payload, $headers + [
            'Authorization' => 'Bearer test-mcp-token',
        ]);
    }

    public function test_endpoint_is_disabled_without_configured_token(): void
    {
        config(['warm.mcp_token' => '']);

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertStatus(404);
    }

    public function test_endpoint_rejects_missing_token(): void
    {
        config(['warm.mcp_token' => 'secret']);

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertStatus(401);
    }

    public function test_endpoint_rejects_wrong_token(): void
    {
        config(['warm.mcp_token' => 'secret']);

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], [
            'Authorization' => 'Bearer wrong',
        ])->assertStatus(401);
    }

    public function test_initialize_reports_the_warm_server(): void
    {
        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => [], 'clientInfo' => ['name' => 'probe', 'version' => '1']],
        ])
            ->assertOk()
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('id', 1)
            ->assertJsonPath('result.serverInfo.name', 'warm');
    }

    public function test_tools_list_exposes_the_registry(): void
    {
        $response = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);

        $response->assertOk();
        $names = array_column($response->json('result.tools'), 'name');

        $this->assertContains('message.send', $names);
        $this->assertContains('media.download', $names);
        $this->assertContains('media.upload', $names);
        $this->assertContains('contact.upsert', $names);
        $this->assertCount(10, $names);
    }

    public function test_notifications_get_an_empty_accepted_response(): void
    {
        $this->mcp(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
            ->assertStatus(202)
            ->assertNoContent(202);
    }

    public function test_unknown_method_returns_a_jsonrpc_error(): void
    {
        $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'does/not/exist'])
            ->assertOk()
            ->assertJsonPath('error.code', -32601);
    }

    public function test_invalid_json_returns_a_parse_error(): void
    {
        $this->call('POST', '/mcp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-mcp-token',
        ], 'not-json')
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32700);
    }

    public function test_batch_messages_are_answered_with_an_array(): void
    {
        $this->mcp([
            ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/list'],
        ])
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('1.id', 11);
    }
}
