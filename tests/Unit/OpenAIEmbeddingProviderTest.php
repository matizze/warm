<?php

namespace Tests\Unit;

use App\Integrations\OpenAI\OpenAIEmbeddingProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIEmbeddingProviderTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function provider(array $overrides = []): OpenAIEmbeddingProvider
    {
        return new OpenAIEmbeddingProvider(array_merge([
            'enabled' => true,
            'model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'openai_key' => 'sk-test',
            'openai_base_url' => 'http://localhost:1234/v1',
        ], $overrides));
    }

    public function test_disabled_when_no_key_is_configured(): void
    {
        $this->assertFalse($this->provider(['openai_key' => ''])->enabled());
    }

    public function test_enabled_when_key_is_present(): void
    {
        $this->assertTrue($this->provider()->enabled());
    }

    public function test_embed_returns_null_when_disabled(): void
    {
        Http::fake();

        $this->assertNull($this->provider(['openai_key' => ''])->embed('hello'));
    }

    public function test_embed_posts_to_embeddings_endpoint_and_returns_vector(): void
    {
        Http::fake([
            '*/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]]),
        ]);

        $embedding = $this->provider()->embed('  hello world  ');

        $this->assertSame([0.1, 0.2, 0.3], $embedding);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://localhost:1234/v1/embeddings'
                && $request['model'] === 'text-embedding-3-small'
                && $request['input'] === 'hello world'
                && $request['dimensions'] === 3;
        });
    }
}
