<?php

namespace App\Embeddings;

use Illuminate\Support\Facades\Http;

final class EmbeddingService
{
    public function __construct(private readonly array $config) {}

    public function enabled(): bool
    {
        return ($this->config['enabled'] ?? false) && ($this->config['openai_key'] ?? '') !== '';
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

        $response = Http::withToken($this->config['openai_key'])
            ->timeout(60)
            ->post($this->config['openai_base_url'].'/embeddings', [
                'model' => $this->config['model'],
                'input' => mb_substr($input, 0, 8000),
                'dimensions' => $this->config['dimensions'],
            ])
            ->throw();

        $embedding = $response->json('data.0.embedding');

        return is_array($embedding) ? $embedding : null;
    }

    /**
     * @param  list<float|int>  $embedding
     */
    public static function toVectorLiteral(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
    }
}
