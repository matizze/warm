<?php

namespace App\Contracts;

/**
 * Text embedding port. The core asks for vectors without knowing which
 * provider (OpenAI, local model, ...) computes them.
 */
interface Embedder
{
    public function enabled(): bool;

    /**
     * @return list<float>|null
     */
    public function embed(string $text): ?array;
}
