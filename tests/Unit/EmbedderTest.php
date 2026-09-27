<?php

namespace Tests\Unit;

use App\Contracts\Embedder;
use App\Integrations\OpenAI\OpenAIEmbedder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbedderTest extends TestCase
{
    public function test_container_binds_the_port_to_the_openai_adapter(): void
    {
        $this->assertInstanceOf(OpenAIEmbedder::class, app(Embedder::class));
    }

    public function test_disabled_when_no_key_is_configured(): void
    {
        config([
            'warm.embeddings.enabled' => true,
            'warm.openai.api_key' => '',
        ]);

        $this->assertFalse(app(Embedder::class)->enabled());
    }

    public function test_enabled_when_key_is_present_in_config(): void
    {
        config([
            'warm.embeddings.enabled' => true,
            'warm.openai.api_key' => 'sk-test',
        ]);

        $this->assertTrue(app(Embedder::class)->enabled());
    }

    public function test_disabled_when_embeddings_are_turned_off_even_with_a_key(): void
    {
        config([
            'warm.embeddings.enabled' => false,
            'warm.openai.api_key' => 'sk-test',
        ]);

        $this->assertFalse(app(Embedder::class)->enabled());
    }

    public function test_embed_returns_null_without_calling_the_provider_when_disabled(): void
    {
        Http::preventStrayRequests();
        config([
            'warm.embeddings.enabled' => false,
            'warm.openai.api_key' => 'sk-test',
        ]);

        $this->assertNull(app(Embedder::class)->embed('texto'));
        Http::assertNothingSent();
    }

    public function test_embed_calls_the_configured_endpoint_and_returns_the_vector(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]]),
        ]);
        config([
            'warm.embeddings.enabled' => true,
            'warm.embeddings.model' => 'text-embedding-3-small',
            'warm.embeddings.dimensions' => 3,
            'warm.openai.api_key' => 'sk-test',
            'warm.openai.base_url' => 'https://api.openai.test/v1',
        ]);

        $embedding = app(Embedder::class)->embed('  contrato assinado  ');

        $this->assertSame([0.1, 0.2, 0.3], $embedding);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.test/v1/embeddings'
            && $request->hasHeader('Authorization', 'Bearer sk-test')
            && $request['model'] === 'text-embedding-3-small'
            && $request['input'] === 'contrato assinado');
    }
}
