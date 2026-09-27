<?php

namespace App\Providers;

use App\Contracts\WhatsAppProvider;
use App\Embeddings\EmbeddingService;
use App\Integrations\Evolution\EvolutionNormalizer;
use App\Integrations\Evolution\EvolutionProvider;
use App\Integrations\Normalizer;
use App\Processing\AudioProcessor;
use App\Processing\ImageProcessor;
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

        $this->app->singleton(Normalizer::class, fn () => new Normalizer(
            (string) config('warm.provider'),
            new EvolutionNormalizer,
        ));

        $this->app->singleton(EmbeddingService::class, fn () => new EmbeddingService(config('warm.embeddings')));

        $this->app->singleton(ProcessingService::class, fn () => new ProcessingService([
            new PdfProcessor,
            new AudioProcessor(config('warm.processing')),
            new ImageProcessor(config('warm.processing')),
        ]));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            Actions::registerCommands();
        }
    }
}
