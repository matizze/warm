<?php

namespace App\Providers;

use App\Contracts\Embedder;
use App\Contracts\MessageNormalizer;
use App\Contracts\WhatsAppProvider;
use App\Integrations\Evolution\EvolutionNormalizer;
use App\Integrations\Evolution\EvolutionProvider;
use App\Integrations\OpenAI\OpenAIClient;
use App\Integrations\OpenAI\OpenAIEmbedder;
use App\Processing\AudioProcessor;
use App\Processing\ImageProcessor;
use App\Processing\PdfProcessor;
use App\Processing\ProcessingService;
use Illuminate\Support\ServiceProvider;
use Lorisleiva\Actions\Facades\Actions;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Composition root: the only place that knows which concrete adapter
     * implements each port for the configured provider.
     */
    public function register(): void
    {
        $this->app->singleton(WhatsAppProvider::class, function () {
            return match ($provider = config('warm.provider')) {
                'evolution' => new EvolutionProvider(config('warm.evolution')),
                default => throw new RuntimeException("Unsupported WhatsApp provider: {$provider}"),
            };
        });

        $this->app->singleton(MessageNormalizer::class, function () {
            return match ($provider = config('warm.provider')) {
                'evolution' => new EvolutionNormalizer,
                default => throw new RuntimeException("Unsupported message normalizer for provider: {$provider}"),
            };
        });

        $this->app->singleton(OpenAIClient::class, fn () => new OpenAIClient(
            (string) config('warm.openai.api_key'),
            (string) config('warm.openai.base_url'),
        ));

        $this->app->singleton(Embedder::class, function () {
            return new OpenAIEmbedder(
                $this->app->make(OpenAIClient::class),
                (bool) config('warm.embeddings.enabled'),
                (string) config('warm.embeddings.model'),
                (int) config('warm.embeddings.dimensions'),
            );
        });

        $this->app->singleton(ProcessingService::class, function () {
            $client = $this->app->make(OpenAIClient::class);

            return new ProcessingService([
                new PdfProcessor,
                new AudioProcessor($client, (string) config('warm.processing.transcription_model')),
                new ImageProcessor($client, (string) config('warm.processing.vision_model')),
            ]);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            Actions::registerCommands();
        }
    }
}
