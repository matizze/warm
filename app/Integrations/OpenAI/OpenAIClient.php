<?php

namespace App\Integrations\OpenAI;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shared HTTP gateway for the OpenAI credentials and base URL. The OpenAI
 * adapters (embeddings, audio, vision) delegate their transport here so the
 * key/base-URL handling exists in a single place.
 */
final class OpenAIClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
    ) {}

    public function configured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @return list<float>|null
     */
    public function embeddings(string $model, string $input, int $dimensions): ?array
    {
        $response = $this->request()
            ->timeout(60)
            ->post($this->url('/embeddings'), [
                'model' => $model,
                'input' => mb_substr($input, 0, 8000),
                'dimensions' => $dimensions,
            ])
            ->throw();

        $embedding = $response->json('data.0.embedding');

        return is_array($embedding) ? $embedding : null;
    }

    public function transcribe(string $model, string $buffer, string $filename): string
    {
        $response = $this->request()
            ->timeout(120)
            ->attach('file', $buffer, $filename)
            ->post($this->url('/audio/transcriptions'), ['model' => $model])
            ->throw();

        return trim((string) $response->json('text'));
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    public function chat(string $model, array $messages, bool $jsonObject = false): string
    {
        $payload = ['model' => $model, 'messages' => $messages];
        if ($jsonObject) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->request()
            ->timeout(120)
            ->post($this->url('/chat/completions'), $payload)
            ->throw();

        return (string) $response->json('choices.0.message.content', '');
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->apiKey)->connectTimeout(5);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
