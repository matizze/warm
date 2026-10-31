<?php

namespace App\Contracts;

use App\Data\KnowledgeSnippet;

/**
 * Generation port. Given the customer question and the retrieved knowledge,
 * it produces a grounded reply — or null when it cannot answer safely, so the
 * caller declines to respond instead of inventing (the guardrail).
 */
interface AnswerGenerator
{
    public function enabled(): bool;

    /**
     * @param  list<KnowledgeSnippet>  $knowledge
     */
    public function answer(string $question, array $knowledge, string $tone): ?string;
}
