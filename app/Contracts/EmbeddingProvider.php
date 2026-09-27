<?php

namespace App\Contracts;

/**
 * Embedding port. The core only knows this contract; the concrete provider
 * (OpenAI, local, ...) is injected by the container.
 */
interface EmbeddingProvider
{
    public function enabled(): bool;

    /**
     * @return list<float>|null
     */
    public function embed(string $text): ?array;
}
