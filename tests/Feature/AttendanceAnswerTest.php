<?php

namespace Tests\Feature;

use App\Actions\Attendance\AnswerCustomerQuestion;
use App\Contracts\AnswerGenerator;
use App\Contracts\KnowledgeBase;
use App\Data\KnowledgeSnippet;
use App\Enums\AttendanceDecision;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\AttendanceCase;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Instance;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceAnswerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'warm.attendance.enabled' => true,
            'warm.attendance.dry_run' => true,
            'warm.attendance.instances' => ['support'],
            'warm.attendance.conversations' => [],
            'warm.attendance.min_confidence' => 0.6,
            'warm.attendance.knowledge_limit' => 5,
            'warm.attendance.tone' => 'Tom de teste',
        ]);
    }

    private function makeInstance(string $id = 'support'): Instance
    {
        return Instance::factory()->create([
            'instance_id' => $id,
            'evolution_url' => 'https://evolution-'.$id.'.test',
            'api_key' => 'key-'.$id,
            'webhook_token' => 'wh-'.$id,
        ]);
    }

    private function inboundMessage(Instance $instance, string $question): Message
    {
        $contact = Contact::create(['phone_number' => '5527999999999', 'name' => 'Cliente']);

        $conversation = Conversation::create([
            'type' => 'contact',
            'contact_id' => $contact->id,
            'instance_id' => $instance->instance_id,
        ]);

        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'type' => MessageType::Text,
            'sender_contact_id' => $contact->id,
            'content' => $question,
            'status' => MessageStatus::Sent,
        ]);
    }

    /**
     * @param  list<KnowledgeSnippet>  $snippets
     */
    private function fakeKnowledge(array $snippets): void
    {
        app()->instance(KnowledgeBase::class, new class($snippets) implements KnowledgeBase
        {
            /** @param list<KnowledgeSnippet> $snippets */
            public function __construct(private readonly array $snippets) {}

            public function search(string $question, ?string $instanceId, int $limit, ?string $excludeMessageId = null): array
            {
                return array_slice($this->snippets, 0, $limit);
            }
        });
    }

    private function fakeGenerator(?string $answer): void
    {
        app()->instance(AnswerGenerator::class, new class($answer) implements AnswerGenerator
        {
            public function __construct(private readonly ?string $answer) {}

            public function enabled(): bool
            {
                return true;
            }

            public function answer(string $question, array $knowledge, string $tone): ?string
            {
                return $this->answer;
            }
        });
    }

    private function snippet(float $score = 0.92): KnowledgeSnippet
    {
        return new KnowledgeSnippet(
            content: 'Nosso horário de atendimento é de segunda a sexta, das 9h às 18h.',
            score: $score,
            messageId: (string) Str::uuid(),
        );
    }

    public function test_known_question_is_answered_in_the_same_conversation_in_dry_run(): void
    {
        $instance = $this->makeInstance();
        $message = $this->inboundMessage($instance, 'Qual o horário de atendimento?');

        $this->fakeKnowledge([$this->snippet(0.92)]);
        $this->fakeGenerator('Atendemos de segunda a sexta, das 9h às 18h.');

        $case = AnswerCustomerQuestion::run($message->id);

        $this->assertNotNull($case);
        $this->assertSame(AttendanceDecision::Answered, $case->decision);
        $this->assertSame($message->conversation_id, $case->conversation_id);
        $this->assertSame('Atendemos de segunda a sexta, das 9h às 18h.', $case->answer);
        $this->assertTrue($case->dry_run);
        $this->assertFalse($case->sent);

        // Dry-run: nothing left the building.
        $this->assertSame(0, Message::where('direction', MessageDirection::Outbound)->count());
    }

    public function test_question_outside_knowledge_is_not_answered_but_recorded(): void
    {
        $instance = $this->makeInstance();
        $message = $this->inboundMessage($instance, 'Vocês trabalham no feriado?');

        $this->fakeKnowledge([]);
        $this->fakeGenerator('Resposta que não deveria existir');

        $case = AnswerCustomerQuestion::run($message->id);

        $this->assertNotNull($case);
        $this->assertSame(AttendanceDecision::NoKnowledge, $case->decision);
        $this->assertNull($case->answer);
        $this->assertSame('no_confident_knowledge', $case->error);
        $this->assertSame(0, Message::where('direction', MessageDirection::Outbound)->count());
    }

    public function test_low_confidence_knowledge_is_not_answered(): void
    {
        $instance = $this->makeInstance();
        $message = $this->inboundMessage($instance, 'Qual o horário de atendimento?');

        $this->fakeKnowledge([$this->snippet(0.2)]);
        $this->fakeGenerator('Resposta que não deveria existir');

        $case = AnswerCustomerQuestion::run($message->id);

        $this->assertNotNull($case);
        $this->assertSame(AttendanceDecision::NoKnowledge, $case->decision);
        $this->assertNull($case->answer);
    }

    public function test_generator_abstaining_does_not_answer(): void
    {
        $instance = $this->makeInstance();
        $message = $this->inboundMessage($instance, 'Qual o horário de atendimento?');

        $this->fakeKnowledge([$this->snippet(0.95)]);
        $this->fakeGenerator(null);

        $case = AnswerCustomerQuestion::run($message->id);

        $this->assertNotNull($case);
        $this->assertSame(AttendanceDecision::NoKnowledge, $case->decision);
        $this->assertSame('generator_abstained', $case->error);
    }

    public function test_kill_switch_turns_the_flow_off(): void
    {
        config(['warm.attendance.enabled' => false]);

        $instance = $this->makeInstance();
        $message = $this->inboundMessage($instance, 'Qual o horário de atendimento?');

        $this->fakeKnowledge([$this->snippet(0.99)]);
        $this->fakeGenerator('Resposta que não deveria existir');

        $case = AnswerCustomerQuestion::run($message->id);

        $this->assertNull($case);
        $this->assertSame(0, AttendanceCase::count());
        $this->assertSame(0, Message::where('direction', MessageDirection::Outbound)->count());
    }

    public function test_out_of_scope_conversation_is_not_processed(): void
    {
        config(['warm.attendance.instances' => ['another-number']]);

        $instance = $this->makeInstance();
        $message = $this->inboundMessage($instance, 'Qual o horário de atendimento?');

        $this->fakeKnowledge([$this->snippet(0.99)]);
        $this->fakeGenerator('Resposta que não deveria existir');

        $this->assertNull(AnswerCustomerQuestion::run($message->id));
        $this->assertSame(0, AttendanceCase::count());
    }

    public function test_reprocessing_the_same_message_does_not_duplicate(): void
    {
        $instance = $this->makeInstance();
        $message = $this->inboundMessage($instance, 'Qual o horário de atendimento?');

        $this->fakeKnowledge([$this->snippet(0.92)]);
        $this->fakeGenerator('Atendemos de segunda a sexta.');

        AnswerCustomerQuestion::run($message->id);
        AnswerCustomerQuestion::run($message->id);

        $this->assertSame(1, AttendanceCase::count());
    }

    public function test_webhook_runs_the_flow_inline_in_dry_run(): void
    {
        $instance = $this->makeInstance();

        $this->fakeKnowledge([$this->snippet(0.9)]);
        $this->fakeGenerator('Atendemos de segunda a sexta, das 9h às 18h.');

        $this->postJson('/instance/support/webhook', $this->inboundPayload('Q-1', 'Qual o horário de atendimento?'), ['x-webhook-token' => 'wh-support'])
            ->assertOk();

        $case = AttendanceCase::sole();

        $this->assertSame(AttendanceDecision::Answered, $case->decision);
        $this->assertSame('support', $case->instance_id);
        $this->assertSame(Message::where('direction', MessageDirection::Inbound)->sole()->conversation_id, $case->conversation_id);
        $this->assertFalse($case->sent);
    }

    public function test_non_dry_run_sends_through_the_conversation_instance(): void
    {
        config(['warm.attendance.dry_run' => false]);

        Http::preventStrayRequests();
        Http::fake([
            'https://evolution-support.test/*' => Http::response(['status' => 'PENDING', 'key' => ['id' => 'srv-9']]),
        ]);

        $instance = $this->makeInstance();
        $message = $this->inboundMessage($instance, 'Qual o horário de atendimento?');

        $this->fakeKnowledge([$this->snippet(0.93)]);
        $this->fakeGenerator('Atendemos de segunda a sexta, das 9h às 18h.');

        $case = AnswerCustomerQuestion::run($message->id);

        $this->assertNotNull($case);
        $this->assertSame(AttendanceDecision::Answered, $case->decision);
        $this->assertFalse($case->dry_run);
        $this->assertTrue($case->sent);
        $this->assertSame('srv-9', $case->sent_provider_message_id);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/message/sendText/support')
            && $request->header('apikey')[0] === 'key-support');
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundPayload(string $id, string $text): array
    {
        return [
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5527999999999@s.whatsapp.net', 'fromMe' => false, 'id' => $id],
                'pushName' => 'Cliente',
                'message' => ['conversation' => $text],
                'messageType' => 'conversation',
                'messageTimestamp' => 1758000000,
            ],
        ];
    }
}
