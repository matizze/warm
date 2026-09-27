<?php

namespace Tests\Feature;

use App\Actions\Media\DownloadMedia;
use App\Contracts\WhatsAppProvider;
use App\Data\OutgoingMessage;
use App\Data\ProviderMedia;
use App\Data\SendResult;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Media;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class CountingMediaProvider implements WhatsAppProvider
{
    public int $mediaCalls = 0;

    public function sendMessage(OutgoingMessage $message): SendResult
    {
        return new SendResult('PENDING');
    }

    public function getMedia(string $providerMessageId, string $remoteJid, bool $fromMe): ProviderMedia
    {
        $this->mediaCalls++;

        return new ProviderMedia(
            base64: base64_encode('hello warm'),
            mimetype: 'text/plain',
            fileName: 'hello.txt',
        );
    }
}

class FailingMediaProvider implements WhatsAppProvider
{
    public function sendMessage(OutgoingMessage $message): SendResult
    {
        return new SendResult('PENDING');
    }

    public function getMedia(string $providerMessageId, string $remoteJid, bool $fromMe): ProviderMedia
    {
        throw new RuntimeException('provedor indisponivel');
    }
}

class DownloadMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_download_is_resumable_and_does_not_duplicate(): void
    {
        Queue::fake();
        config(['warm.storage.disk' => 'local']);

        $contact = Contact::create(['phone_number' => '5511777777777', 'name' => 'Ana']);
        $conversation = Conversation::create(['type' => 'contact', 'contact_id' => $contact->id]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'provider_message_id' => 'PM-1',
            'direction' => 'inbound',
            'type' => 'image',
            'sender_contact_id' => $contact->id,
            'content' => null,
            'metadata' => [],
            'status' => 'sent',
        ]);

        $fake = new CountingMediaProvider;
        app()->instance(WhatsAppProvider::class, $fake);

        try {
            DownloadMedia::run($message->id);
            DownloadMedia::run($message->id);

            $this->assertSame(1, $fake->mediaCalls);
            $this->assertSame(1, Media::count());
            $this->assertTrue(Storage::disk('local')->exists("media/{$message->id}/original"));
        } finally {
            Storage::disk('local')->deleteDirectory("media/{$message->id}");
        }
    }

    private function inboundMediaMessage(): Message
    {
        $contact = Contact::create(['phone_number' => '5511777777777', 'name' => 'Ana']);
        $conversation = Conversation::create(['type' => 'contact', 'contact_id' => $contact->id]);

        return Message::create([
            'conversation_id' => $conversation->id,
            'provider_message_id' => 'PM-2',
            'direction' => 'inbound',
            'type' => 'image',
            'sender_contact_id' => $contact->id,
            'content' => null,
            'metadata' => [],
            'status' => 'sent',
        ]);
    }

    public function test_download_failure_records_state_on_the_message(): void
    {
        Queue::fake();
        config(['warm.storage.disk' => 'local']);

        $message = $this->inboundMediaMessage();
        app()->instance(WhatsAppProvider::class, new FailingMediaProvider);

        try {
            DownloadMedia::run($message->id);
            $this->fail('Expected DownloadMedia to rethrow the provider failure');
        } catch (RuntimeException $e) {
            $this->assertSame('provedor indisponivel', $e->getMessage());
        }

        $message->refresh();
        $this->assertSame('failed', $message->metadata['download']['status']);
        $this->assertSame('provedor indisponivel', $message->metadata['download']['error']);
        $this->assertSame(1, $message->metadata['download']['attempts']);
    }

    public function test_download_retry_increments_failure_attempts(): void
    {
        Queue::fake();
        config(['warm.storage.disk' => 'local']);

        $message = $this->inboundMediaMessage();
        app()->instance(WhatsAppProvider::class, new FailingMediaProvider);

        foreach ([1, 2] as $attempt) {
            try {
                DownloadMedia::run($message->id);
                $this->fail('Expected DownloadMedia to rethrow the provider failure');
            } catch (RuntimeException $e) {
                // expected
            }

            $this->assertSame($attempt, $message->refresh()->metadata['download']['attempts']);
        }
    }

    public function test_download_success_records_done_state(): void
    {
        Queue::fake();
        config(['warm.storage.disk' => 'local']);

        $message = $this->inboundMediaMessage();
        app()->instance(WhatsAppProvider::class, new CountingMediaProvider);

        try {
            DownloadMedia::run($message->id);
        } finally {
            Storage::disk('local')->deleteDirectory("media/{$message->id}");
        }

        $this->assertSame('done', $message->refresh()->metadata['download']['status']);
    }
}
