<?php

namespace App\Embeddings;

use App\Contracts\EmbeddingProvider;

/**
 * Core embedding service. Delegates provider-specific API calls to the
 * injected EmbeddingProvider port and exposes the vector literal helper.
 */
final class EmbeddingService
{
    public function __construct(private readonly EmbeddingProvider $provider) {}

    public function enabled(): bool
    {
        return $this->provider->enabled();
    }

    /**
     * @return list<float>|null
     */
    public function embed(string $text): ?array
    {
        return $this->provider->embed($text);
    }

    /**
     * @param  list<float|int>  $embedding
     */
    public static function toVectorLiteral(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
    }
}
