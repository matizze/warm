<?php

namespace App\Data;

/**
 * A piece of knowledge retrieved for grounding an answer, with the relevance
 * score the retrieval gave it. The score is what the guardrail compares
 * against the configured minimum confidence before any reply is generated.
 */
final readonly class KnowledgeSnippet
{
    public function __construct(
        public string $content,
        public float $score,
        public ?string $messageId = null,
        public ?string $conversationId = null,
    ) {}
}
