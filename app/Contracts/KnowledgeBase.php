<?php

namespace App\Contracts;

use App\Data\KnowledgeSnippet;

/**
 * Retrieval port for the attendance flow. The core only knows this contract;
 * the concrete source (the WARM message archive today, an approved base later)
 * is injected by the container. Implementations return the most relevant
 * snippets first, each with a 0..1 relevance score.
 */
interface KnowledgeBase
{
    /**
     * @return list<KnowledgeSnippet>
     */
    public function search(string $question, ?string $instanceId, int $limit, ?string $excludeMessageId = null): array;
}
