<?php

namespace Tests\Unit;

use App\Embeddings\EmbeddingService;
use Tests\TestCase;

class EmbeddingServiceTest extends TestCase
{
    public function test_disabled_when_no_key_is_configured(): void
    {
        config([
            'warm.embeddings.enabled' => true,
            'warm.embeddings.openai_key' => '',
        ]);

        $this->assertFalse(app(EmbeddingService::class)->enabled());
    }

    public function test_enabled_when_key_is_present_in_config(): void
    {
        config([
            'warm.embeddings.enabled' => true,
            'warm.embeddings.openai_key' => 'sk-test',
            'warm.embeddings.openai_base_url' => 'http://localhost:1234/v1',
        ]);

        $this->assertTrue(app(EmbeddingService::class)->enabled());
    }
}
