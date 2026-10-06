<?php

namespace Tests\Feature;

use App\Actions\Messages\IndexMessage;
use App\Actions\Search\ReindexMessages;
use App\Contracts\EmbeddingProvider;
use App\Embeddings\EmbeddingService;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReindexMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function message(string $content): Message
    {
        $contact = Contact::firstOrCreate(
            ['phone_number' => '5511888888888'],
            ['name' => 'Reindex'],
        );
        $conversation = Conversation::firstOrCreate([
            'contact_id' => $contact->id,
            'type' => 'contact',
        ]);

        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'sender_contact_id' => $contact->id,
            'content' => $content,
            'status' => 'sent',
        ]);
    }

    public function test_reindex_skips_messages_that_are_already_complete(): void
    {
        $messages = [$this->message('um'), $this->message('dois'), $this->message('tres')];
        foreach ($messages as $message) {
            IndexMessage::run($message->id);
        }

        $summary = ReindexMessages::run([]);

        $this->assertSame(3, $summary['scanned']);
        $this->assertSame(0, $summary['textIndexed']);
        $this->assertSame(3, $summary['skipped']);
        $this->assertSame(0, $summary['failed']);
    }

    public function test_reindex_indexes_only_messages_without_search_text(): void
    {
        $indexed = $this->message('ja indexada');
        IndexMessage::run($indexed->id);

        $this->message('precisa de indexacao');

        $summary = ReindexMessages::run([]);

        $this->assertSame(2, $summary['scanned']);
        $this->assertSame(1, $summary['textIndexed']);
        $this->assertSame(1, $summary['skipped']);

        $this->assertNotNull(Message::where('content', 'precisa de indexacao')->firstOrFail()->search_text);
    }

    public function test_reindex_force_reindexes_everything(): void
    {
        $messages = [$this->message('um'), $this->message('dois')];
        foreach ($messages as $message) {
            IndexMessage::run($message->id);
        }

        $summary = ReindexMessages::run(['force' => true]);

        $this->assertSame(2, $summary['scanned']);
        $this->assertSame(2, $summary['textIndexed']);
        $this->assertSame(0, $summary['skipped']);
    }

    public function test_reindex_dry_run_counts_without_writing(): void
    {
        $this->message('precisa de indexacao');

        $summary = ReindexMessages::run(['dryRun' => true]);

        $this->assertSame(1, $summary['textIndexed']);

        $this->assertNull(Message::firstOrFail()->search_text);
    }

    public function test_reindex_throws_when_embedding_dimension_mismatches(): void
    {
        $provider = new class implements EmbeddingProvider
        {
            public function enabled(): bool
            {
                return true;
            }

            public function embed(string $text): ?array
            {
                return null;
            }
        };

        app()->instance(EmbeddingService::class, new EmbeddingService($provider));
        config(['warm.embeddings.dimensions' => 256]);

        $this->message('qualquer');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dimensão de embedding incompatível');

        ReindexMessages::run([]);
    }
}
