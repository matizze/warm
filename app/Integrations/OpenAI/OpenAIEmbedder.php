<?php

namespace App\Integrations\OpenAI;

use App\Contracts\Embedder;

/**
 * Embedder adapter backed by the OpenAI embeddings API.
 */
final class OpenAIEmbedder implements Embedder
{
    public function __construct(
        private readonly OpenAIClient $client,
        private readonly bool $enabled,
        private readonly string $model,
        private readonly int $dimensions,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled && $this->client->configured();
    }

    /**
     * @return list<float>|null
     */
    public function embed(string $text): ?array
    {
        $input = trim($text);
        if (! $this->enabled() || $input === '') {
            return null;
        }

        return $this->client->embeddings($this->model, $input, $this->dimensions);
    }
}
