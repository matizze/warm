<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Instance;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InstanceWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function makeInstance(array $attributes = []): Instance
    {
        return Instance::factory()->create($attributes + [
            'instance_id' => 'support',
            'evolution_url' => 'https://evolution.test',
            'webhook_token' => 'wh-support',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function inbound(string $id, string $text = 'Ola WARM', string $jid = '5527999999999@s.whatsapp.net'): array
    {
        return [
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => $jid, 'fromMe' => false, 'id' => $id],
                'pushName' => 'Henrique',
                'message' => ['conversation' => $text],
                'messageType' => 'conversation',
                'messageTimestamp' => 1758000000,
            ],
        ];
    }

    public function test_unknown_instance_is_not_found(): void
    {
        $this->postJson('/instance/ghost/webhook', [], ['x-webhook-token' => 'whatever'])
            ->assertStatus(404);
    }

    public function test_inactive_instance_is_not_found(): void
    {
        $this->makeInstance(['active' => false]);

        $this->postJson('/instance/support/webhook', [], ['x-webhook-token' => 'wh-support'])
            ->assertStatus(404);
    }

    public function test_missing_or_wrong_instance_token_is_unauthorized(): void
    {
        $this->makeInstance();

        $this->postJson('/instance/support/webhook', $this->inbound('M-1'))->assertStatus(401);

        $this->postJson('/instance/support/webhook', $this->inbound('M-2'), ['x-webhook-token' => 'wrong'])
            ->assertStatus(401);

        Queue::fake();
        $this->postJson('/instance/support/webhook', $this->inbound('M-3'), ['x-webhook-token' => 'wh-support'])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'duplicate' => false]);
    }

    public function test_instance_webhook_persists_conversation_and_message_under_that_instance(): void
    {
        Queue::fake();
        $this->makeInstance();

        $this->postJson('/instance/support/webhook', $this->inbound('S-1'), ['x-webhook-token' => 'wh-support'])
            ->assertOk();

        $conversation = Conversation::sole();
        $message = Message::sole();

        $this->assertSame('support', $conversation->instance_id);
        $this->assertSame('support', $message->instance_id);
    }

    public function test_legacy_webhook_routes_to_the_default_instance(): void
    {
        Queue::fake();

        $this->postJson('/webhook', $this->inbound('L-1'), ['x-webhook-token' => 'test-token'])
            ->assertOk();

        $default = Instance::default();

        $this->assertSame($default->instance_id, Conversation::sole()->instance_id);
        $this->assertSame($default->instance_id, Message::sole()->instance_id);
    }

    public function test_two_instances_keep_their_own_conversation_for_the_same_contact(): void
    {
        Queue::fake();
        $this->makeInstance(['instance_id' => 'support', 'webhook_token' => 'wh-support']);
        $this->makeInstance(['instance_id' => 'personal', 'webhook_token' => 'wh-personal']);

        $jid = '5527999999999@s.whatsapp.net';

        $this->postJson('/instance/support/webhook', $this->inbound('S-1', 'do suporte', $jid), ['x-webhook-token' => 'wh-support'])
            ->assertOk();
        $this->postJson('/instance/personal/webhook', $this->inbound('P-1', 'do pessoal', $jid), ['x-webhook-token' => 'wh-personal'])
            ->assertOk();

        $this->assertSame(2, Conversation::count());
        $this->assertSame(2, Message::count());
        $this->assertSame(1, Contact::count());

        $byInstance = Conversation::query()->pluck('instance_id')->sort()->values()->all();
        $this->assertSame(['personal', 'support'], $byInstance);
    }

    public function test_default_instance_is_seeded_from_the_environment(): void
    {
        $default = Instance::default();

        $this->assertNotNull($default);
        $this->assertSame(config('warm.evolution.instance'), $default->instance_id);
        $this->assertSame(config('warm.webhook_token'), $default->webhook_token);
        $this->assertSame(config('warm.mcp_token'), $default->mcp_token);
    }

    public function test_conversation_without_an_instance_falls_back_to_the_default(): void
    {
        $contact = Contact::create(['phone_number' => '5511888888888', 'name' => 'Ana']);

        $conversation = Conversation::create(['type' => 'contact', 'contact_id' => $contact->id]);

        $this->assertSame(Instance::default()->instance_id, $conversation->instance_id);
    }
}
