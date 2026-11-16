<?php

namespace Tests\Feature;

use App\Actions\Media\ReprocessMedia;
use App\Contracts\MediaProcessor;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Media;
use App\Models\Message;
use App\Processing\ProcessedContent;
use App\Processing\ProcessingContext;
use App\Processing\ProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ReprocessMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'warm.processing.enabled' => true,
            'warm.storage.disk' => 'local',
        ]);

        Storage::fake('local');
    }

    private function message(string $content = 'mensagem'): Message
    {
        $contact = Contact::create(['phone_number' => '5511666666666', 'name' => 'Reprocess']);
        $conversation = Conversation::create(['type' => 'contact', 'contact_id' => $contact->id]);

        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'audio',
            'sender_contact_id' => $contact->id,
            'content' => $content,
            'status' => 'sent',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function media(array $attributes = []): Media
    {
        return Media::create(array_merge([
            'type' => 'audio',
            'filename' => 'sample.ogg',
            'mime_type' => 'audio/ogg',
            'storage_key' => 'media/'.Str::uuid().'/original',
            'size_bytes' => 4,
            'metadata' => [],
        ], $attributes));
    }

    private function transcriberFor(string $text = 'texto transcrito'): void
    {
        $processor = new class($text) implements MediaProcessor
        {
            public function __construct(private readonly string $text) {}

            public function name(): string
            {
                return 'audio';
            }

            public function supports(ProcessingContext $context): bool
            {
                return $context->type === 'audio';
            }

            public function process(string $buffer, ProcessingContext $context): ?ProcessedContent
            {
                return new ProcessedContent(kind: 'transcription', text: $this->text, metadata: ['processor' => 'audio']);
            }
        };

        app()->instance(ProcessingService::class, new ProcessingService([$processor]));
    }

    public function test_reprocesses_stored_media_and_reindexes_the_message(): void
    {
        $this->transcriberFor();

        $message = $this->message();
        $media = $this->media(['message_id' => $message->id]);
        Storage::disk('local')->put($media->storage_key, 'audio-bytes');

        $summary = ReprocessMedia::run([]);

        $this->assertSame(1, $summary['scanned']);
        $this->assertSame(1, $summary['reprocessed']);
        $this->assertSame(1, $summary['done']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(0, $summary['skipped']);

        $this->assertSame('texto transcrito', $media->refresh()->extracted_text);
        $this->assertSame('done', $media->metadata['processing']['status']);
        $this->assertStringContainsString('texto transcrito', (string) $message->refresh()->search_text);
    }

    public function test_selects_media_by_type_status_and_message(): void
    {
        $message = $this->message();

        $this->media(['type' => 'audio', 'metadata' => ['processing' => ['status' => 'failed']], 'message_id' => $message->id]);
        $this->media(['type' => 'image', 'mime_type' => 'image/png', 'metadata' => ['processing' => ['status' => 'failed']]]);
        $this->media(['type' => 'audio', 'metadata' => ['processing' => ['status' => 'done']]]);

        $this->assertSame(2, ReprocessMedia::run(['type' => 'audio', 'dryRun' => true])['scanned']);
        $this->assertSame(2, ReprocessMedia::run(['status' => 'failed', 'dryRun' => true])['scanned']);
        $this->assertSame(1, ReprocessMedia::run(['messages' => $message->id, 'dryRun' => true])['scanned']);
        $this->assertSame(1, ReprocessMedia::run(['limit' => 1, 'dryRun' => true])['scanned']);
    }

    public function test_dry_run_counts_without_reading_or_writing(): void
    {
        $media = $this->media(['metadata' => ['processing' => ['status' => 'failed']]]);

        $summary = ReprocessMedia::run(['status' => 'failed', 'dryRun' => true]);

        $this->assertSame(1, $summary['scanned']);
        $this->assertSame(1, $summary['reprocessed']);
        $this->assertSame(0, $summary['failed']);

        $this->assertNull($media->refresh()->extracted_text);
        $this->assertSame('failed', $media->metadata['processing']['status']);
    }

    public function test_media_without_file_is_counted_as_failure_without_breaking_the_batch(): void
    {
        $this->transcriberFor();

        $stored = $this->media(['metadata' => ['processing' => ['status' => 'failed']]]);
        Storage::disk('local')->put($stored->storage_key, 'audio-bytes');

        $missing = $this->media(['metadata' => ['processing' => ['status' => 'failed']]]);

        $summary = ReprocessMedia::run(['status' => 'failed']);

        $this->assertSame(2, $summary['scanned']);
        $this->assertSame(1, $summary['reprocessed']);
        $this->assertSame(1, $summary['done']);
        $this->assertSame(1, $summary['failed']);

        $this->assertSame('texto transcrito', $stored->refresh()->extracted_text);
        $this->assertNull($missing->refresh()->extracted_text);
    }

    public function test_refuses_to_run_when_processing_is_disabled(): void
    {
        config(['warm.processing.enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Processamento desabilitado');

        ReprocessMedia::run([]);
    }

    public function test_rejects_an_unknown_status_filter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Status inválido');

        ReprocessMedia::run(['status' => 'whatever']);
    }
}
