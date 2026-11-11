<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Instance;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContactRenameTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeInstance(string $instanceId, string $token, array $attributes = []): Instance
    {
        return Instance::factory()->create($attributes + [
            'instance_id' => $instanceId,
            'mcp_token' => $token,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function inbound(string $id, string $jid, string $pushName = 'Ana'): array
    {
        return [
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => $jid, 'fromMe' => false, 'id' => $id],
                'pushName' => $pushName,
                'message' => ['conversation' => 'Oi'],
                'messageType' => 'conversation',
                'messageTimestamp' => 1758000000,
            ],
        ];
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

    public function test_renames_a_contact_created_by_the_mcp_tool_by_phone_number(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');

        $created = $this->callTool($instance, 'contact.upsert', [
            'phoneNumber' => '+55 (11) 98888-7777',
            'name' => 'Ana',
        ]);
        $contactId = $created['payload']['contact']['id'];

        $result = $this->callTool($instance, 'contact.rename', [
            'phoneNumber' => '+55 11 98888-7777',
            'name' => 'Ana Souza',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertTrue($result['payload']['updated']);
        $this->assertSame($contactId, $result['payload']['contact']['id']);
        $this->assertSame('Ana Souza', $result['payload']['contact']['name']);
        $this->assertSame('5511988887777', $result['payload']['contact']['phoneNumber']);
        $this->assertSame('+5511988887777', $result['payload']['contact']['e164']);
        $this->assertSame('5511988887777@c.us', $result['payload']['contact']['jid']);

        $this->assertSame(1, Contact::count());
        $this->assertSame(1, Conversation::count());
        $this->assertSame(0, Message::count());
        $this->assertSame('Ana Souza', Contact::sole()->name);
        $this->assertSame('support', Conversation::sole()->instance_id);
    }

    public function test_renames_a_contact_created_by_inbound_ingestion(): void
    {
        Queue::fake();
        $instance = $this->makeInstance('support', 'mcp-support', ['webhook_token' => 'wh-support']);

        $this->postJson('/instance/support/webhook', $this->inbound('S-1', '5511988887777@s.whatsapp.net'), [
            'x-webhook-token' => 'wh-support',
        ])->assertOk();

        $this->assertSame('Ana', Contact::sole()->name);

        $result = $this->callTool($instance, 'contact.rename', [
            'phoneNumber' => '5511988887777@s.whatsapp.net',
            'name' => 'Ana Beatriz',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertTrue($result['payload']['updated']);
        $this->assertSame('Ana Beatriz', $result['payload']['contact']['name']);
        $this->assertSame('Ana Beatriz', Contact::sole()->name);
        $this->assertSame(1, Contact::count());
        $this->assertSame(1, Conversation::count());
        $this->assertSame(1, Message::count());
    }

    public function test_a_manual_rename_is_not_overwritten_by_the_next_inbound_push_name(): void
    {
        Queue::fake();
        $instance = $this->makeInstance('support', 'mcp-support', ['webhook_token' => 'wh-support']);

        $this->postJson('/instance/support/webhook', $this->inbound('S-1', '5511988887777@s.whatsapp.net', 'Ana'), [
            'x-webhook-token' => 'wh-support',
        ])->assertOk();

        $this->callTool($instance, 'contact.rename', [
            'phoneNumber' => '5511988887777',
            'name' => 'Ana Souza',
        ]);

        $this->postJson('/instance/support/webhook', $this->inbound('S-2', '5511988887777@s.whatsapp.net', 'Ana Clara'), [
            'x-webhook-token' => 'wh-support',
        ])->assertOk();

        $contact = Contact::sole();
        $this->assertSame('Ana Souza', $contact->name);
        $this->assertSame('Ana Clara', $contact->push_name);
        $this->assertTrue($contact->name_is_custom);
        $this->assertSame(1, Contact::count());
        $this->assertSame(1, Conversation::count());
        $this->assertSame(2, Message::count());
    }

    public function test_an_inbound_only_contact_keeps_following_the_push_name(): void
    {
        Queue::fake();
        $this->makeInstance('support', 'mcp-support', ['webhook_token' => 'wh-support']);

        $this->postJson('/instance/support/webhook', $this->inbound('S-1', '5511988887777@s.whatsapp.net', 'Ana'), [
            'x-webhook-token' => 'wh-support',
        ])->assertOk();

        $this->assertSame('Ana', Contact::sole()->name);
        $this->assertFalse(Contact::sole()->name_is_custom);

        $this->postJson('/instance/support/webhook', $this->inbound('S-2', '5511988887777@s.whatsapp.net', 'Ana Clara'), [
            'x-webhook-token' => 'wh-support',
        ])->assertOk();

        $contact = Contact::sole();
        $this->assertSame('Ana Clara', $contact->name);
        $this->assertSame('Ana Clara', $contact->push_name);
        $this->assertFalse($contact->name_is_custom);
        $this->assertSame(1, Contact::count());
    }

    public function test_renames_by_contact_id(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');

        $created = $this->callTool($instance, 'contact.upsert', [
            'phoneNumber' => '5511988887777',
            'name' => 'Ana',
        ]);

        $result = $this->callTool($instance, 'contact.rename', [
            'contactId' => $created['payload']['contact']['id'],
            'name' => 'Ana Maria',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertTrue($result['payload']['updated']);
        $this->assertSame($created['payload']['contact']['id'], $result['payload']['contact']['id']);
        $this->assertSame('Ana Maria', Contact::sole()->name);
    }

    public function test_renaming_a_contact_without_a_conversation_creates_nothing(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');
        Contact::create(['phone_number' => '5511977776666@s.whatsapp.net', 'name' => 'Bruno']);

        $result = $this->callTool($instance, 'contact.rename', [
            'phoneNumber' => '+55 (11) 97777-6666',
            'name' => 'Bruno Lima',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertTrue($result['payload']['updated']);
        $this->assertSame('Bruno Lima', Contact::sole()->name);
        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Message::count());
    }

    public function test_an_empty_name_is_rejected_before_any_write(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');

        $created = $this->callTool($instance, 'contact.upsert', [
            'phoneNumber' => '5511988887777',
            'name' => 'Ana',
        ]);
        $contact = Contact::sole();
        $updatedAt = $contact->updated_at;

        $this->travel(1)->minutes();

        foreach (['', '   '] as $name) {
            $result = $this->callTool($instance, 'contact.rename', [
                'contactId' => $created['payload']['contact']['id'],
                'name' => $name,
            ]);

            $this->assertTrue($result['isError']);
            $this->assertStringContainsString('Nome inválido', $result['payload']['error']);
        }

        $contact->refresh();
        $this->assertSame('Ana', $contact->name);
        $this->assertEquals($updatedAt, $contact->updated_at);
        $this->assertSame(1, Contact::count());
        $this->assertSame(1, Conversation::count());
    }

    public function test_rename_is_idempotent_when_the_name_does_not_change(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');

        $created = $this->callTool($instance, 'contact.upsert', [
            'phoneNumber' => '5511988887777',
            'name' => 'Ana',
        ]);

        $first = $this->callTool($instance, 'contact.rename', [
            'contactId' => $created['payload']['contact']['id'],
            'name' => 'Ana Maria',
        ]);
        $afterFirst = Contact::sole()->updated_at;

        $this->travel(1)->minutes();

        $second = $this->callTool($instance, 'contact.rename', [
            'contactId' => $created['payload']['contact']['id'],
            'name' => 'Ana Maria',
        ]);

        $this->assertTrue($first['payload']['updated']);
        $this->assertFalse($second['payload']['updated']);
        $this->assertSame('Ana Maria', $second['payload']['contact']['name']);
        $this->assertTrue(Contact::sole()->name_is_custom);
        $this->assertSame(1, Contact::count());
        $this->assertSame(1, Conversation::count());
        $this->assertEquals($afterFirst, Contact::sole()->updated_at);
    }

    public function test_the_new_name_is_visible_in_contact_and_conversation_search(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');

        $this->callTool($instance, 'contact.upsert', [
            'phoneNumber' => '5511988887777',
            'name' => 'Ana',
        ]);
        $this->callTool($instance, 'contact.rename', [
            'phoneNumber' => '5511988887777',
            'name' => 'Ana Souza',
        ]);

        $byName = $this->callTool($instance, 'contact.search', ['search' => 'Ana Souza']);
        $this->assertCount(1, $byName['payload']);
        $this->assertSame('Ana Souza', $byName['payload'][0]['name']);

        $conversations = $this->callTool($instance, 'conversation.search', ['search' => 'Ana Souza']);
        $this->assertCount(1, $conversations['payload']);
    }

    public function test_a_contact_of_another_instance_cannot_be_renamed(): void
    {
        $support = $this->makeInstance('support', 'mcp-support');
        $personal = $this->makeInstance('personal', 'mcp-personal');

        $this->callTool($support, 'contact.upsert', [
            'phoneNumber' => '5511988887777',
            'name' => 'Ana',
        ]);

        $result = $this->callTool($personal, 'contact.rename', [
            'phoneNumber' => '5511988887777',
            'name' => 'Intruso',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('another instance', $result['payload']['error']);
        $this->assertSame('Ana', Contact::sole()->name);
    }

    public function test_an_unknown_contact_returns_an_error_and_writes_nothing(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');

        $result = $this->callTool($instance, 'contact.rename', [
            'phoneNumber' => '5511966665555',
            'name' => 'Ninguém',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('não encontrado', $result['payload']['error']);
        $this->assertSame(0, Contact::count());
    }
}
