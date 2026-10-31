<?php

namespace App\Integrations\OpenAI;

use App\Contracts\AnswerGenerator;
use App\Data\KnowledgeSnippet;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI chat adapter for the attendance reply.
 *
 * The system prompt is the guardrail in code: answer only from the provided
 * knowledge, never invent, and reply exactly ABSTAIN when the knowledge is
 * not enough. The tone is injected (configurable) instead of a fixed wording.
 */
final class OpenAIAnswerGenerator implements AnswerGenerator
{
    public function __construct(private readonly array $config) {}

    public function enabled(): bool
    {
        return ($this->config['enabled'] ?? false) && ($this->config['openai_key'] ?? '') !== '';
    }

    /**
     * @param  list<KnowledgeSnippet>  $knowledge
     */
    public function answer(string $question, array $knowledge, string $tone): ?string
    {
        if (! $this->enabled() || $knowledge === []) {
            return null;
        }

        $response = Http::withToken($this->config['openai_key'])
            ->timeout(60)
            ->post($this->config['openai_base_url'].'/chat/completions', [
                'model' => $this->config['answer_model'] ?? 'gpt-4o-mini',
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt($tone)],
                    ['role' => 'user', 'content' => $this->userPrompt($question, $knowledge)],
                ],
            ])
            ->throw();

        $answer = trim((string) $response->json('choices.0.message.content', ''));

        if ($answer === '' || mb_strtoupper($answer) === 'ABSTAIN') {
            return null;
        }

        return $answer;
    }

    private function systemPrompt(string $tone): string
    {
        return implode("\n", [
            'Você é o atendimento de uma empresa. Responda à dúvida do cliente em português do Brasil.',
            'Use SOMENTE as informações do CONHECIMENTO fornecido. Nunca invente, não complete lacunas e não estime prazos.',
            'Se o conhecimento não for suficiente para responder com segurança, responda exatamente: ABSTAIN',
            "Tom: {$tone}",
        ]);
    }

    /**
     * @param  list<KnowledgeSnippet>  $knowledge
     */
    private function userPrompt(string $question, array $knowledge): string
    {
        $items = array_map(
            fn (KnowledgeSnippet $snippet) => '- '.$snippet->content,
            $knowledge,
        );

        return implode("\n", [
            'CONHECIMENTO:',
            implode("\n", $items),
            '',
            'DÚVIDA DO CLIENTE:',
            $question,
        ]);
    }
}
