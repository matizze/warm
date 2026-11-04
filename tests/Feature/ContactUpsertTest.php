<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Instance;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContactUpsertTest extends TestCase
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
    private function inbound(string $id, string $jid): array
    {
        return [
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => $jid, 'fromMe' => false, 'id' => $id],
                'pushName' => 'Ana',
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

    public function test_creates_the_contact_and_the_instance_conversation(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');

        $result = $this->callTool($instance, 'contact.upsert', [
            'phoneNumber' => '+55 (11) 98888-7777',
            'name' => 'Ana',
            'observations' => 'Cliente do plano anual',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertTrue($result['payload']['created']);
        $this->assertFalse($result['payload']['updated']);
        $this->assertSame('5511988887777', $result['payload']['contact']['phoneNumber']);
        $this->assertSame('+5511988887777', $result['payload']['contact']['e164']);
        $this->assertSame('5511988887777@c.us', $result['payload']['contact']['jid']);
        $this->assertSame('Ana', $result['payload']['contact']['name']);
        $this->assertSame('support', $result['payload']['contact']['instanceId']);

        $contact = Contact::sole();
        $this->assertSame('5511988887777@s.whatsapp.net', $contact->phone_number);
        $this->assertSame('Cliente do plano anual', $contact->observations);

        $conversation = Conversation::sole();
        $this->assertSame($contact->id, $conversation->contact_id);
        $this->assertSame('support', $conversation->instance_id);
        $this->assertSame($conversation->id, $result['payload']['contact']['conversationId']);
    }

    public function test_upsert_does_not_duplicate_and_updates_the_existing_contact(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');

        $this->callTool($instance, 'contact.upsert', [
            'phoneNumber' => '5511988887777',
            'name' => 'Ana',
        ]);

        $result = $this->callTool($instance, 'contact.upsert', [
            'phoneNumber' => '+55 11 98888-7777',
            'name' => 'Ana Maria',
            'observations' => 'Prefere contato por áudio',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertFalse($result['payload']['created']);
        $this->assertTrue($result['payload']['updated']);
        $this->assertSame('Ana Maria', $result['payload']['contact']['name']);

        $this->assertSame(1, Contact::count());
        $this->assertSame(1, Conversation::count());
        $this->assertSame('Ana Maria', Contact::sole()->name);
    }

    public function test_invalid_or_empty_phone_is_a_validation_error_and_writes_nothing(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');

        foreach (['', '   ', 'abc', '+55 11', '0123456789', '1234567890123456'] as $phoneNumber) {
            $result = $this->callTool($instance, 'contact.upsert', ['phoneNumber' => $phoneNumber]);

            $this->assertTrue($result['isError'], "phone [{$phoneNumber}] should be rejected");
            $this->assertArrayHasKey('error', $result['payload']);
            $this->assertStringContainsString('Telefone inválido', $result['payload']['error']);
        }

        $this->assertSame(0, Contact::count());
        $this->assertSame(0, Conversation::count());
    }

    public function test_a_contact_of_another_instance_cannot_be_read_or_changed(): void
    {
        $support = $this->makeInstance('support', 'mcp-support');
        $personal = $this->makeInstance('personal', 'mcp-personal');

        $this->callTool($support, 'contact.upsert', [
            'phoneNumber' => '5511988887777',
            'name' => 'Ana',
        ]);

        $result = $this->callTool($personal, 'contact.upsert', [
            'phoneNumber' => '5511988887777',
            'name' => 'Intruso',
        ]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('another instance', $result['payload']['error']);

        $this->assertSame('Ana', Contact::sole()->name);
        $this->assertSame(1, Conversation::count());
        $this->assertFalse(Conversation::where('instance_id', 'personal')->exists());
    }

    public function test_a_legacy_provider_jid_contact_is_reused_instead_of_duplicated(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');
        $contact = Contact::create(['phone_number' => '5511977776666@s.whatsapp.net', 'name' => null]);
        Conversation::create(['type' => 'contact', 'contact_id' => $contact->id, 'instance_id' => 'support']);

        $result = $this->callTool($instance, 'contact.upsert', [
            'phoneNumber' => '+55 (11) 97777-6666',
            'name' => 'Bruno',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertFalse($result['payload']['created']);
        $this->assertTrue($result['payload']['updated']);
        $this->assertSame($contact->id, $result['payload']['contact']['id']);
        $this->assertSame(1, Contact::count());
        $this->assertSame('Bruno', $contact->fresh()->name);
    }

    public function test_a_contact_saved_before_the_first_inbound_does_not_duplicate(): void
    {
        Queue::fake();
        $instance = $this->makeInstance('support', 'mcp-support', ['webhook_token' => 'wh-support']);

        $this->callTool($instance, 'contact.upsert', [
            'phoneNumber' => '+55 (11) 98888-7777',
            'name' => 'Ana',
        ]);

        $this->postJson('/instance/support/webhook', $this->inbound('S-1', '5511988887777@s.whatsapp.net'), [
            'x-webhook-token' => 'wh-support',
        ])->assertOk()->assertJson(['status' => 'ok', 'duplicate' => false]);

        $contact = Contact::sole();
        $this->assertSame('5511988887777@s.whatsapp.net', $contact->phone_number);
        $this->assertSame('Ana', $contact->name);

        $conversation = Conversation::sole();
        $this->assertSame($contact->id, $conversation->contact_id);
        $this->assertSame('support', $conversation->instance_id);

        $this->assertSame(1, Message::count());
        $this->assertSame($conversation->id, Message::sole()->conversation_id);
    }

    public function test_a_legacy_bare_digits_contact_is_normalized_to_the_canonical_key(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');
        $contact = Contact::create(['phone_number' => '5511977776666', 'name' => null]);
        Conversation::create(['type' => 'contact', 'contact_id' => $contact->id, 'instance_id' => 'support']);

        $result = $this->callTool($instance, 'contact.upsert', [
            'phoneNumber' => '+55 (11) 97777-6666',
            'name' => 'Bruno',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertFalse($result['payload']['created']);
        $this->assertSame($contact->id, $result['payload']['contact']['id']);
        $this->assertSame(1, Contact::count());
        $this->assertSame('5511977776666@s.whatsapp.net', $contact->fresh()->phone_number);
        $this->assertSame('5511977776666', $result['payload']['contact']['phoneNumber']);
    }

    public function test_the_lookup_is_deterministic_when_both_key_formats_exist(): void
    {
        $instance = $this->makeInstance('support', 'mcp-support');

        $legacy = Contact::create(['phone_number' => '5511966665555', 'name' => 'Legado']);
        $canonical = Contact::create(['phone_number' => '5511966665555@s.whatsapp.net', 'name' => 'Canônico']);

        $result = $this->callTool($instance, 'contact.upsert', [
            'phoneNumber' => '5511966665555',
            'name' => 'Ana',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertSame($canonical->id, $result['payload']['contact']['id']);
        $this->assertSame('Ana', $canonical->fresh()->name);
        $this->assertSame('Legado', $legacy->fresh()->name);
        $this->assertSame(2, Contact::count());
    }

    public function test_the_created_contact_is_visible_in_contact_and_conversation_search(): void
    {
        $support = $this->makeInstance('support', 'mcp-support');
        $personal = $this->makeInstance('personal', 'mcp-personal');

        $this->callTool($support, 'contact.upsert', [
            'phoneNumber' => '5511988887777',
            'name' => 'Ana',
        ]);

        $byPhone = $this->callTool($support, 'contact.search', ['phoneNumber' => '5511988887777']);
        $this->assertCount(1, $byPhone['payload']);
        $this->assertSame('Ana', $byPhone['payload'][0]['name']);

        $byName = $this->callTool($support, 'contact.search', ['search' => 'Ana']);
        $this->assertCount(1, $byName['payload']);

        $conversations = $this->callTool($support, 'conversation.search', ['search' => 'Ana']);
        $this->assertCount(1, $conversations['payload']);
        $this->assertSame('support', $conversations['payload'][0]['instance_id']);

        $otherInstance = $this->callTool($personal, 'conversation.search', ['search' => 'Ana']);
        $this->assertCount(0, $otherInstance['payload']);
    }

    public function test_the_tool_is_listed_on_every_instance(): void
    {
        $support = $this->makeInstance('support', 'mcp-support');
        $personal = $this->makeInstance('personal', 'mcp-personal');

        foreach ([$support, $personal] as $instance) {
            $names = collect($this->postJson("/instance/{$instance->instance_id}/mcp", [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ], ['Authorization' => "Bearer {$instance->mcp_token}"])->assertOk()->json('result.tools'))
                ->pluck('name')
                ->all();

            $this->assertContains('contact.upsert', $names);
            $this->assertCount(10, $names);
        }
    }
}
