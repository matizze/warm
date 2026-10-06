<?php

namespace Tests\Unit;

use App\Contracts\EmbeddingProvider;
use App\Contracts\MediaProcessor;
use App\Contracts\Normalizer;
use App\Contracts\WhatsAppProvider;
use App\Integrations\Evolution\EvolutionNormalizer;
use App\Integrations\Evolution\EvolutionProvider;
use App\Integrations\OpenAI\OpenAIEmbeddingProvider;
use App\Processing\ProcessingService;
use ReflectionProperty;
use Tests\TestCase;

class AdapterWiringTest extends TestCase
{
    public function test_whatsapp_provider_resolves_to_evolution_adapter(): void
    {
        $this->assertInstanceOf(EvolutionProvider::class, app(WhatsAppProvider::class));
    }

    public function test_normalizer_resolves_to_evolution_adapter(): void
    {
        $this->assertInstanceOf(EvolutionNormalizer::class, app(Normalizer::class));
    }

    public function test_embedding_provider_resolves_to_openai_adapter(): void
    {
        $this->assertInstanceOf(OpenAIEmbeddingProvider::class, app(EmbeddingProvider::class));
    }

    public function test_processing_service_is_composed_of_media_processor_adapters(): void
    {
        $property = new ReflectionProperty(ProcessingService::class, 'processors');
        $processors = $property->getValue(app(ProcessingService::class));

        $this->assertIsIterable($processors);

        $names = [];
        foreach ($processors as $processor) {
            $this->assertInstanceOf(MediaProcessor::class, $processor);
            $names[] = $processor->name();
        }

        $this->assertEqualsCanonicalizing(['pdf', 'audio', 'image'], $names);
    }
}
