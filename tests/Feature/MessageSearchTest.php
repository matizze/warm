<?php

namespace Tests\Feature;

use App\Actions\Messages\IndexMessage;
use App\Actions\Messages\SearchMessages;
use App\Enums\SearchMode;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessageSearchTest extends TestCase
{
    use RefreshDatabase;

    private function fakeEmbeddings(): void
    {
        Http::fake([
            '*/embeddings' => Http::response([
                'data' => [['embedding' => array_fill(0, 1536, 0.01)]],
            ], 200),
        ]);

        config([
            'warm.embeddings.enabled' => true,
            'warm.embeddings.openai_key' => 'sk-test',
            'warm.embeddings.dimensions' => 1536,
        ]);
    }

    private function messageWithEmbedding(string $content): Message
    {
        $contact = Contact::firstOrCreate(
            ['phone_number' => '5511999999999'],
            ['name' => 'Teste'],
        );
        $conversation = Conversation::firstOrCreate([
            'contact_id' => $contact->id,
            'type' => 'contact',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'type' => 'text',
            'sender_contact_id' => $contact->id,
            'content' => $content,
            'status' => 'sent',
        ]);

        IndexMessage::run($message->id);

        return $message->refresh();
    }

    public function test_hybrid_fusion_uses_rrf_score_not_cosine_similarity(): void
    {
        $this->fakeEmbeddings();
        $this->messageWithEmbedding('churrasco com amigos');

        // The query shares no words with the message, so it is found only by
        // the semantic branch. Its score must be the RRF contribution (~0.016),
        // not the cosine similarity (~1.0).
        $results = SearchMessages::run(['search' => 'advogados', 'limit' => 10], SearchMode::Hybrid);

        $this->assertCount(1, $results);
        $this->assertLessThan(0.5, $results[0]['score']);
    }

    public function test_hybrid_respects_limit_on_fused_results(): void
    {
        $this->fakeEmbeddings();
        $this->messageWithEmbedding('um');
        $this->messageWithEmbedding('dois');
        $this->messageWithEmbedding('tres');

        $results = SearchMessages::run(['search' => 'zzz', 'limit' => 2], SearchMode::Hybrid);

        $this->assertCount(2, $results);
    }
}
