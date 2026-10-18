<?php

namespace App\Actions\Attendance;

use App\Actions\Messages\SendMessage;
use App\Contracts\AnswerGenerator;
use App\Contracts\KnowledgeBase;
use App\Data\KnowledgeSnippet;
use App\Enums\AttendanceDecision;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\AttendanceCase;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Answers a customer question in the same conversation it arrived on.
 *
 * History 4 (MTZ-222): the customer writes a question the attendance already
 * knows how to answer and gets a clear reply in the same conversation. The
 * guardrail is the core of this action: when there is no safe answer it does
 * NOT reply — it records the case (the human handoff is a separate flow).
 *
 * Every run is scoped to configured instances/conversations and can be turned
 * off entirely by the kill switch. It starts in dry-run: the intended reply is
 * recorded and logged, never sent.
 */
final class AnswerCustomerQuestion
{
    use AsAction;

    public int $jobTimeout = 120;

    public function handle(string $messageId): ?AttendanceCase
    {
        $config = config('warm.attendance');

        if (! ($config['enabled'] ?? false)) {
            return null;
        }

        if (! Str::isUuid($messageId)) {
            return null;
        }

        $message = Message::find($messageId);
        if ($message === null || ! $this->isCustomerQuestion($message)) {
            return null;
        }

        if (! $this->inScope($message, $config)) {
            return null;
        }

        // Idempotent: a queue retry must not create a second case or reply.
        $existing = AttendanceCase::where('message_id', $message->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $dryRun = (bool) ($config['dry_run'] ?? true);
        $knowledge = $this->retrieveKnowledge($message, $config);
        $confidence = $knowledge === [] ? 0.0 : max(array_map(
            fn (KnowledgeSnippet $snippet) => $snippet->score,
            $knowledge,
        ));

        if ($knowledge === [] || $confidence < (float) ($config['min_confidence'] ?? 0.6)) {
            return $this->record($message, AttendanceDecision::NoKnowledge, [
                'confidence' => $confidence,
                'knowledge' => $knowledge,
                'dry_run' => $dryRun,
                'error' => 'no_confident_knowledge',
            ]);
        }

        try {
            $answer = app(AnswerGenerator::class)->answer(
                (string) $message->content,
                $knowledge,
                (string) ($config['tone'] ?? ''),
            );
        } catch (Throwable $e) {
            Log::warning('Atendimento: falha ao gerar resposta', [
                'message_id' => $message->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->record($message, AttendanceDecision::Error, [
                'confidence' => $confidence,
                'knowledge' => $knowledge,
                'dry_run' => $dryRun,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }

        if ($answer === null || trim($answer) === '') {
            return $this->record($message, AttendanceDecision::NoKnowledge, [
                'confidence' => $confidence,
                'knowledge' => $knowledge,
                'dry_run' => $dryRun,
                'error' => 'generator_abstained',
            ]);
        }

        return $this->deliver($message, $answer, $confidence, $knowledge, $dryRun);
    }

    private function isCustomerQuestion(Message $message): bool
    {
        return $message->direction === MessageDirection::Inbound
            && $message->type === MessageType::Text
            && trim((string) $message->content) !== '';
    }

    /**
     * The flow only runs inside the configured scope: either the instance or
     * the specific conversation must be listed. An empty scope means nothing
     * is enabled, so a fresh installation never answers by accident.
     *
     * @param  array<string, mixed>  $config
     */
    private function inScope(Message $message, array $config): bool
    {
        $instances = $config['instances'] ?? [];
        $conversations = $config['conversations'] ?? [];

        if ($instances === [] && $conversations === []) {
            return false;
        }

        return in_array($message->instance_id, $instances, true)
            || in_array($message->conversation_id, $conversations, true);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<KnowledgeSnippet>
     */
    private function retrieveKnowledge(Message $message, array $config): array
    {
        return app(KnowledgeBase::class)->search(
            (string) $message->content,
            $message->instance_id,
            (int) ($config['knowledge_limit'] ?? 5),
            $message->id,
        );
    }

    private function deliver(Message $message, string $answer, float $confidence, array $knowledge, bool $dryRun): AttendanceCase
    {
        if ($dryRun) {
            Log::info('Atendimento (dry-run): resposta pronta, não enviada', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'answer' => $answer,
            ]);

            return $this->record($message, AttendanceDecision::Answered, [
                'confidence' => $confidence,
                'knowledge' => $knowledge,
                'dry_run' => true,
                'sent' => false,
                'answer' => $answer,
            ]);
        }

        try {
            // Replies through the instance the conversation belongs to, so it
            // leaves from the same number the customer wrote to.
            $result = SendMessage::run([
                'conversationId' => $message->conversation_id,
                'content' => $answer,
                'type' => MessageType::Text->value,
            ]);
        } catch (Throwable $e) {
            Log::warning('Atendimento: falha ao enviar resposta', [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->record($message, AttendanceDecision::Error, [
                'confidence' => $confidence,
                'knowledge' => $knowledge,
                'dry_run' => false,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }

        $sent = ($result['status'] ?? 'error') !== 'error';

        return $this->record($message, AttendanceDecision::Answered, [
            'confidence' => $confidence,
            'knowledge' => $knowledge,
            'dry_run' => false,
            'sent' => $sent,
            'answer' => $answer,
            'sent_provider_message_id' => $result['providerMessageId'] ?? null,
            'error' => $sent ? null : ($result['error'] ?? 'send_failed'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(Message $message, AttendanceDecision $decision, array $attributes): AttendanceCase
    {
        /** @var list<KnowledgeSnippet> $knowledge */
        $knowledge = $attributes['knowledge'] ?? [];

        return AttendanceCase::create([
            'conversation_id' => $message->conversation_id,
            'instance_id' => $message->instance_id,
            'message_id' => $message->id,
            'contact_id' => $message->sender_contact_id,
            'question' => (string) $message->content,
            'decision' => $decision,
            'answer' => $attributes['answer'] ?? null,
            'confidence' => $attributes['confidence'] ?? null,
            'knowledge' => array_map(fn (KnowledgeSnippet $snippet) => [
                'message_id' => $snippet->messageId,
                'conversation_id' => $snippet->conversationId,
                'score' => $snippet->score,
                'content' => mb_substr($snippet->content, 0, 500),
            ], $knowledge),
            'dry_run' => (bool) ($attributes['dry_run'] ?? true),
            'sent' => (bool) ($attributes['sent'] ?? false),
            'sent_provider_message_id' => $attributes['sent_provider_message_id'] ?? null,
            'error' => $attributes['error'] ?? null,
        ]);
    }
}
