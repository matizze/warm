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

    public function test_hybrid_applies_offset_once(): void
    {
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $message = $this->message("fatura {$i}");
            $message->forceFill(['created_at' => now()->subMinutes(6 - $i)])->save();
            $ids[$i] = $message->id;
        }

        $results = SearchMessages::run(['search' => 'fatura', 'offset' => 2, 'limit' => 10], SearchMode::Hybrid);

        // The two most recent messages are skipped once; with the old double
        // offset they were skipped twice and only one result came back.
        $this->assertCount(3, $results);
        $this->assertSame([$ids[3], $ids[2], $ids[1]], array_column($results, 'id'));
    }

    public function test_hybrid_falls_back_to_lexical_when_embedding_throws(): void
    {
        $this->fakeEmbeddings();
        Http::fake(['*/embeddings' => Http::response([], 500)]);

        $this->message('fatura paga');

        $results = SearchMessages::run(['search' => 'fatura', 'limit' => 10], SearchMode::Hybrid);

        $this->assertCount(1, $results);
    }

    public function test_semantic_mode_returns_only_semantic_matches(): void
    {
        $this->fakeEmbeddings();
        $this->messageWithEmbedding('churrasco com amigos');

        $results = SearchMessages::run(['search' => 'advogados'], SearchMode::Semantic);

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('score', $results[0]);
    }

    public function test_lexical_mode_applies_offset(): void
    {
        $ids = [];
        for ($i = 1; $i <= 4; $i++) {
            $message = $this->message("fatura {$i}");
            $message->forceFill(['created_at' => now()->subMinutes(5 - $i)])->save();
            $ids[$i] = $message->id;
        }

        $results = SearchMessages::run(['search' => 'fatura', 'offset' => 2, 'limit' => 10], SearchMode::Lexical);

        $this->assertCount(2, $results);
        $this->assertSame([$ids[2], $ids[1]], array_column($results, 'id'));
    }

    private function message(string $content): Message
    {
        $contact = Contact::firstOrCreate(
            ['phone_number' => '5511777777777'],
            ['name' => 'Lexical'],
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
}
