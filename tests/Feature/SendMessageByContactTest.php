<?php

namespace Tests\Feature;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Instance;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendMessageByContactTest extends TestCase
{
    use RefreshDatabase;

    private function makeInstance(string $instanceId, string $token): Instance
    {
        return Instance::factory()->create([
            'instance_id' => $instanceId,
            'mcp_token' => $token,
            'evolution_url' => 'https://evolution-send.test',
            'api_key' => 'key-'.$instanceId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{isError: bool, payload: array<string, mixed>}
     */
    private function callTool(Instance $instance, string $name, array $arguments): array
    {
        $response = $this->postJson("/instance/{$instance->instance_id}/mcp", [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], ['Authorization' => "Bearer {$instance->mcp_token}"])->assertOk();

        return [
            'isError' => (bool) $response->json('result.isError'),
            'payload' => json_decode((string) $response->json('result.content.0.text'), true),
        ];
    }

    private function fakeAccepted(string $providerId = 'prov-contact-1'): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://evolution-send.test/*' => Http::response([
                'status' => 'PENDING',
                'key' => ['id' => $providerId],
            ]),
        ]);
    }

    public function test_sends_to_a_contact_without_conversation_and_creates_it(): void
    {
        $this->fakeAccepted();

        $instance = $this->makeInstance('support', 'mcp-support');
        $contact = Contact::create(['phone_number' => '+55 (11) 98888-7777', 'name' => 'Vivo Sinesio']);

        $result = $this->callTool($instance, 'message.send', [
            'contactId' => $contact->id,
            'content' => 'Primeira mensagem',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertSame('accepted', $result['payload']['status']);
        $this->assertSame('PENDING', $result['payload']['providerStatus']);
        $this->assertSame('prov-contact-1', $result['payload']['providerMessageId']);
        $this->assertSame($contact->id, $result['payload']['contactId']);
        $this->assertNotEmpty($result['payload']['messageId']);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/message/sendText/support')
            && $request['number'] === '5511988887777@s.whatsapp.net'
            && $request['text'] === 'Primeira mensagem'
            && $request->header('apikey')[0] === 'key-support');

        $conversation = Conversation::sole();
        $this->assertSame($contact->id, $conversation->contact_id);
        $this->assertSame('support', $conversation->instance_id);
        $this->assertSame($conversation->id, $result['payload']['conversationId']);

        $message = Message::sole();
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame('support', $message->instance_id);
        $this->assertSame('prov-contact-1', $message->provider_message_id);
        $this->assertSame(MessageDirection::Outbound, $message->direction);
        $this->assertSame(MessageStatus::Accepted, $message->status);
        $this->assertSame('Primeira mensagem', $message->content);
    }

    public function test_reuses_the_existing_conversation_of_the_contact(): void
    {
        $this->fakeAccepted('prov-reuse');

        $instance = $this->makeInstance('support', 'mcp-support');
        $contact = Contact::create(['phone_number' => '5511988887777@s.whatsapp.net', 'name' => 'Ana']);
        $conversation = Conversation::create([
            'type' => 'contact',
            'contact_id' => $contact->id,
            'instance_id' => 'support',
        ]);

        $result = $this->callTool($instance, 'message.send', [
            'contactId' => $contact->id,
            'content' => 'Oi de novo',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertSame($conversation->id, $result['payload']['conversationId']);
        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, Message::count());
        $this->assertSame($conversation->id, Message::sole()->conversation_id);
    }

    public function test_a_contact_with_history_in_another_instance_gets_its_own_conversation(): void
    {
        $this->fakeAccepted('prov-isolated');

        $instance = $this->makeInstance('support', 'mcp-support');
        $this->makeInstance('personal', 'mcp-personal');
        $contact = Contact::create(['phone_number' => '5511988887777@s.whatsapp.net', 'name' => 'Ana']);
        Conversation::create(['type' => 'contact', 'contact_id' => $contact->id, 'instance_id' => 'personal']);

        $result = $this->callTool($instance, 'message.send', [
            'contactId' => $contact->id,
            'content' => 'Oi pelo suporte',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertSame(2, Conversation::count());
        $this->assertSame(1, Conversation::where('instance_id', 'support')->count());
        $this->assertSame(1, Message::where('instance_id', 'support')->count());
        $this->assertSame(0, Message::where('instance_id', 'personal')->count());
    }

    public function test_requires_exactly_one_of_conversation_id_and_contact_id(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');
        $contact = Contact::create(['phone_number' => '5511988887777@s.whatsapp.net', 'name' => 'Ana']);

        $both = $this->callTool($instance, 'message.send', [
            'conversationId' => (string) Str::uuid(),
            'contactId' => $contact->id,
            'content' => 'oi',
        ]);

        $neither = $this->callTool($instance, 'message.send', ['content' => 'oi']);

        foreach ([$both, $neither] as $result) {
            $this->assertTrue($result['isError']);
            $this->assertStringContainsString('exatamente um', $result['payload']['error']);
        }

        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Message::count());
        Http::assertNothingSent();
    }

    public function test_invalid_contact_phone_returns_error_without_side_effects(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');
        $contact = Contact::create(['phone_number' => 'telefone', 'name' => 'Sem número']);

        $result = $this->callTool($instance, 'message.send', [
            'contactId' => $contact->id,
            'content' => 'oi',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('error', $result['payload']['status']);
        $this->assertStringContainsString('no valid phone number', $result['payload']['error']);
        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Message::count());
        Http::assertNothingSent();
    }

    public function test_unknown_contact_returns_error_without_side_effects(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');

        $result = $this->callTool($instance, 'message.send', [
            'contactId' => (string) Str::uuid(),
            'content' => 'oi',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('error', $result['payload']['status']);
        $this->assertStringContainsString('contact not found', $result['payload']['error']);
        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Message::count());
        Http::assertNothingSent();
    }

    public function test_provider_failure_returns_the_reason_without_persisting_anything(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://evolution-send.test/*' => Http::response(['message' => 'instância desconectada'], 503),
        ]);

        $instance = $this->makeInstance('support', 'mcp-support');
        $contact = Contact::create(['phone_number' => '5511988887777@s.whatsapp.net', 'name' => 'Ana']);

        $result = $this->callTool($instance, 'message.send', [
            'contactId' => $contact->id,
            'content' => 'oi',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('error', $result['payload']['status']);
        $this->assertStringContainsString('HTTP 503', $result['payload']['error']);
        $this->assertStringContainsString('inst', $result['payload']['error']);
        $this->assertSame(0, Message::count());
    }

    public function test_delivery_status_from_the_provider_is_persisted_and_returned(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://evolution-send.test/*' => Http::response([
                'status' => 'DELIVERY_ACK',
                'key' => ['id' => 'prov-delivered'],
            ]),
        ]);

        $instance = $this->makeInstance('support', 'mcp-support');
        $contact = Contact::create(['phone_number' => '5511988887777@s.whatsapp.net', 'name' => 'Ana']);

        $result = $this->callTool($instance, 'message.send', [
            'contactId' => $contact->id,
            'content' => 'oi',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertSame('delivered', $result['payload']['status']);
        $this->assertSame('DELIVERY_ACK', $result['payload']['providerStatus']);

        $message = Message::sole();
        $this->assertSame(MessageStatus::Delivered, $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertNotNull($message->delivered_at);
    }

    public function test_provider_rejection_status_is_returned_as_failed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://evolution-send.test/*' => Http::response([
                'status' => 'ERROR',
                'message' => 'template obrigatório fora da janela de 24h',
                'key' => ['id' => 'prov-failed'],
            ]),
        ]);

        $instance = $this->makeInstance('support', 'mcp-support');
        $contact = Contact::create(['phone_number' => '5511988887777@s.whatsapp.net', 'name' => 'Ana']);

        $result = $this->callTool($instance, 'message.send', [
            'contactId' => $contact->id,
            'content' => 'oi',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame('failed', $result['payload']['status']);
        $this->assertSame('ERROR', $result['payload']['providerStatus']);
        $this->assertSame('template obrigatório fora da janela de 24h', $result['payload']['error']);

        $message = Message::sole();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertSame('prov-failed', $message->provider_message_id);
        $this->assertNull($message->sent_at);
    }

    public function test_idempotency_key_does_not_send_or_store_twice(): void
    {
        $this->fakeAccepted('prov-idempotent');

        $instance = $this->makeInstance('support', 'mcp-support');
        $contact = Contact::create(['phone_number' => '5511988887777@s.whatsapp.net', 'name' => 'Ana']);
        $arguments = [
            'contactId' => $contact->id,
            'content' => 'oi',
            'idempotencyKey' => 'retry-abc-123',
        ];

        $first = $this->callTool($instance, 'message.send', $arguments);
        $second = $this->callTool($instance, 'message.send', $arguments);

        $this->assertFalse($first['isError']);
        $this->assertFalse($second['isError']);
        $this->assertSame($first['payload']['messageId'], $second['payload']['messageId']);
        $this->assertTrue($second['payload']['idempotentReplay']);
        $this->assertSame(1, Message::count());
        Http::assertSentCount(1);
    }

    public function test_tools_list_documents_both_send_targets(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');

        $tools = collect($this->postJson("/instance/{$instance->instance_id}/mcp", [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], ['Authorization' => "Bearer {$instance->mcp_token}"])->assertOk()->json('result.tools'));

        $send = $tools->firstWhere('name', 'message.send');

        $this->assertArrayHasKey('conversationId', $send['inputSchema']['properties']);
        $this->assertArrayHasKey('contactId', $send['inputSchema']['properties']);
        $this->assertSame([], $send['inputSchema']['required']);
        $this->assertStringContainsString('contactId', $send['description']);
        $this->assertStringContainsString('template', $send['description']);
    }
}
