<?php

namespace App\Attendance;

use App\Actions\Messages\SearchMessages;
use App\Contracts\KnowledgeBase;
use App\Data\KnowledgeSnippet;
use App\Enums\SearchMode;

/**
 * Default knowledge source: the WARM message archive of the same instance,
 * searched by meaning (embeddings). The answer is anchored on what was
 * actually said to the customer, and each snippet carries the similarity
 * score the guardrail uses to decide whether it is safe to answer.
 *
 * With embeddings disabled there is no score to trust, so nothing is
 * returned and the flow declines to answer instead of guessing.
 */
final class MessageKnowledgeBase implements KnowledgeBase
{
    /**
     * @return list<KnowledgeSnippet>
     */
    public function search(string $question, ?string $instanceId, int $limit, ?string $excludeMessageId = null): array
    {
        if (trim($question) === '' || $limit < 1) {
            return [];
        }

        $results = SearchMessages::run([
            'search' => $question,
            'instance_id' => $instanceId,
            'limit' => $limit + ($excludeMessageId !== null ? 1 : 0),
        ], SearchMode::Semantic);

        $snippets = [];

        foreach ($results as $result) {
            $messageId = $result['id'] ?? null;
            if ($messageId !== null && $messageId === $excludeMessageId) {
                continue;
            }

            $content = trim((string) ($result['content'] ?? $result['search_text'] ?? ''));
            if ($content === '') {
                continue;
            }

            $snippets[] = new KnowledgeSnippet(
                content: $content,
                score: (float) ($result['score'] ?? 0.0),
                messageId: is_string($messageId) ? $messageId : null,
                conversationId: isset($result['conversation_id']) ? (string) $result['conversation_id'] : null,
            );

            if (count($snippets) >= $limit) {
                break;
            }
        }

        return $snippets;
    }
}
