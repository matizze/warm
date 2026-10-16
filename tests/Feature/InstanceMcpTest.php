<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Instance;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class InstanceMcpTest extends TestCase
{
    use RefreshDatabase;

    private function makeInstance(array $attributes = []): Instance
    {
        return Instance::factory()->create($attributes + [
            'instance_id' => 'support',
            'evolution_url' => 'https://evolution.test',
            'mcp_token' => 'mcp-support',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function mcp(string $path, array $payload, array $headers = []): TestResponse
    {
        return $this->postJson($path, $payload, $headers);
    }

    private function conversation(string $instanceId): Conversation
    {
        $contact = Contact::create(['phone_number' => '55'.random_int(100000000, 999999999), 'name' => 'Ana']);

        return Conversation::create([
            'type' => 'contact',
            'contact_id' => $contact->id,
            'instance_id' => $instanceId,
        ]);
    }

    public function test_unknown_instance_is_not_found(): void
    {
        $this->mcp('/instance/ghost/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertStatus(404);
    }

    public function test_inactive_instance_is_not_found(): void
    {
        $this->makeInstance(['active' => false]);

        $this->mcp('/instance/support/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], [
            'Authorization' => 'Bearer mcp-support',
        ])->assertStatus(404);
    }

    public function test_missing_or_wrong_instance_token_is_unauthorized(): void
    {
        $this->makeInstance();

        $ping = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];

        $this->mcp('/instance/support/mcp', $ping)->assertStatus(401);
        $this->mcp('/instance/support/mcp', $ping, ['Authorization' => 'Bearer wrong'])->assertStatus(401);
        $this->mcp('/instance/support/mcp', $ping, ['Authorization' => 'Bearer mcp-support'])->assertOk();
    }

    public function test_instance_mcp_accepts_its_own_token(): void
    {
        $this->makeInstance();

        $this->mcp('/instance/support/mcp', ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'initialize', 'params' => []], [
            'Authorization' => 'Bearer mcp-support',
        ])
            ->assertOk()
            ->assertJsonPath('id', 7)
            ->assertJsonPath('result.serverInfo.name', 'warm');
    }

    public function test_send_is_refused_for_a_conversation_of_another_instance(): void
    {
        $this->makeInstance(['instance_id' => 'support', 'mcp_token' => 'mcp-support']);
        $personal = $this->makeInstance(['instance_id' => 'personal', 'mcp_token' => 'mcp-personal']);

        $conversation = $this->conversation($personal->instance_id);

        $response = $this->mcp('/instance/support/mcp', [
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => ['name' => 'message.send', 'arguments' => ['conversationId' => $conversation->id, 'content' => 'oi']],
        ], ['Authorization' => 'Bearer mcp-support'])
            ->assertOk()
            ->assertJsonPath('result.isError', true);

        $payload = json_decode($response->json('result.content.0.text'), true);
        $this->assertSame('error', $payload['status']);
        $this->assertStringContainsString('another instance', $payload['error']);
    }

    public function test_message_search_only_returns_the_instance_messages(): void
    {
        $this->makeInstance(['instance_id' => 'support', 'mcp_token' => 'mcp-support']);
        $personal = $this->makeInstance(['instance_id' => 'personal', 'mcp_token' => 'mcp-personal']);

        $supportConversation = $this->conversation('support');
        $personalConversation = $this->conversation($personal->instance_id);

        Message::create([
            'conversation_id' => $supportConversation->id,
            'instance_id' => 'support',
            'provider_message_id' => 'S-1',
            'direction' => 'inbound',
            'type' => 'text',
            'content' => 'contrato do suporte',
            'status' => 'sent',
        ]);
        Message::create([
            'conversation_id' => $personalConversation->id,
            'instance_id' => 'personal',
            'provider_message_id' => 'P-1',
            'direction' => 'inbound',
            'type' => 'text',
            'content' => 'contrato do pessoal',
            'status' => 'sent',
        ]);

        $response = $this->mcp('/instance/support/mcp', [
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'tools/call',
            'params' => ['name' => 'message.search', 'arguments' => ['search' => 'contrato', 'mode' => 'lexical']],
        ], ['Authorization' => 'Bearer mcp-support'])->assertOk();

        $results = json_decode($response->json('result.content.0.text'), true);

        $this->assertCount(1, $results);
        $this->assertSame('contrato do suporte', $results[0]['content']);
    }
}
