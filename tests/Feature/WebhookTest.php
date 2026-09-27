<?php

namespace Tests\Feature;

use App\Actions\Media\DownloadMedia;
use App\Actions\Messages\IndexMessage;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lorisleiva\Actions\Decorators\JobDecorator;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private function headers(): array
    {
        return ['x-webhook-token' => 'test-token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function inbound(string $id, string $text = 'Ola WARM, contrato assinado'): array
    {
        return [
            'event' => 'messages.upsert',
            'instance' => 'instance1',
            'data' => [
                'key' => [
                    'remoteJid' => '5527999999999@s.whatsapp.net',
                    'fromMe' => false,
                    'id' => $id,
                ],
                'pushName' => 'Henrique',
                'message' => ['conversation' => $text],
                'messageType' => 'conversation',
                'messageTimestamp' => 1758000000,
            ],
        ];
    }

    public function test_health_endpoint(): void
    {
        $this->get('/health')->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_rejects_missing_webhook_token(): void
    {
        $this->postJson('/webhook', [])->assertStatus(401);
    }

    public function test_empty_token_is_allowed_in_testing_environment(): void
    {
        config(['warm.webhook_token' => '']);

        $this->postJson('/webhook', ['event' => 'connection.update', 'data' => []])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
    }

    public function test_ignores_unsupported_event(): void
    {
        $this->withHeaders($this->headers())
            ->postJson('/webhook', ['event' => 'connection.update', 'data' => []])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);
    }

    public function test_persists_inbound_message_and_deduplicates(): void
    {
        Queue::fake();

        $this->withHeaders($this->headers())
            ->postJson('/webhook', $this->inbound('MSG-1'))
            ->assertOk()
            ->assertJson(['status' => 'ok', 'duplicate' => false]);

        $this->withHeaders($this->headers())
            ->postJson('/webhook', $this->inbound('MSG-1'))
            ->assertOk()
            ->assertJson(['status' => 'ok', 'duplicate' => true]);

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('contacts', [
            'phone_number' => '5527999999999@s.whatsapp.net',
            'name' => 'Henrique',
        ]);

        $message = Message::first();
        $this->assertStringContainsString('contrato', (string) $message->content);
        $this->assertNotNull($message->conversation->last_message_at);

        Queue::assertPushed(JobDecorator::class, fn (JobDecorator $job) => $job->decorates(IndexMessage::class));
    }

    public function test_media_message_dispatches_download_media(): void
    {
        Queue::fake();

        $payload = $this->inbound('IMG-1');
        $payload['data']['message'] = [
            'imageMessage' => ['url' => 'https://cdn/x.jpg', 'mimetype' => 'image/jpeg', 'caption' => 'foto'],
        ];

        $this->withHeaders($this->headers())
            ->postJson('/webhook', $payload)
            ->assertOk()
            ->assertJson(['status' => 'ok', 'duplicate' => false]);

        $message = Message::where('provider_message_id', 'IMG-1')->firstOrFail();

        Queue::assertPushed(JobDecorator::class, fn (JobDecorator $job) => $job->decorates(DownloadMedia::class));
        Queue::assertNotPushed(JobDecorator::class, fn (JobDecorator $job) => $job->decorates(IndexMessage::class));
        $this->assertNotNull($message);
    }

    public function test_applies_status_update(): void
    {
        Queue::fake();

        $this->withHeaders($this->headers())->postJson('/webhook', $this->inbound('MSG-2'))->assertOk();

        $this->withHeaders($this->headers())
            ->postJson('/webhook', ['event' => 'messages.update', 'data' => ['keyId' => 'MSG-2', 'status' => 'READ']])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'update' => 'read']);

        $message = Message::where('provider_message_id', 'MSG-2')->first();
        $this->assertSame('read', $message->status->value);
        $this->assertNotNull($message->sent_at);
        $this->assertNotNull($message->read_at);
    }
}
