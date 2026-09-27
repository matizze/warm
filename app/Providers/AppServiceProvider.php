<?php

namespace App\Providers;

use App\Contracts\EmbeddingProvider;
use App\Contracts\Normalizer;
use App\Contracts\WhatsAppProvider;
use App\Embeddings\EmbeddingService;
use App\Integrations\Evolution\EvolutionNormalizer;
use App\Integrations\Evolution\EvolutionProvider;
use App\Integrations\OpenAI\OpenAIAudioProcessor;
use App\Integrations\OpenAI\OpenAIEmbeddingProvider;
use App\Integrations\OpenAI\OpenAIImageProcessor;
use App\Processing\PdfProcessor;
use App\Processing\ProcessingService;
use Illuminate\Support\ServiceProvider;
use Lorisleiva\Actions\Facades\Actions;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WhatsAppProvider::class, function () {
            return match ($provider = config('warm.provider')) {
                'evolution' => new EvolutionProvider(config('warm.evolution')),
                default => throw new RuntimeException("Unsupported WhatsApp provider: {$provider}"),
            };
        });

        $this->app->singleton(Normalizer::class, function () {
            return match ($provider = config('warm.provider')) {
                'evolution' => new EvolutionNormalizer,
                default => throw new RuntimeException("Unsupported WhatsApp provider: {$provider}"),
            };
        });

        $this->app->singleton(EmbeddingProvider::class, fn () => new OpenAIEmbeddingProvider(config('warm.embeddings')));

        $this->app->singleton(EmbeddingService::class, fn () => new EmbeddingService(app(EmbeddingProvider::class)));

        $this->app->singleton(ProcessingService::class, fn () => new ProcessingService([
            new PdfProcessor,
            new OpenAIAudioProcessor(config('warm.processing')),
            new OpenAIImageProcessor(config('warm.processing')),
        ]));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            Actions::registerCommands();
        }
    }
}
