<?php

namespace App\Providers;

use App\Attendance\MessageKnowledgeBase;
use App\Contracts\AnswerGenerator;
use App\Contracts\EmbeddingProvider;
use App\Contracts\KnowledgeBase;
use App\Contracts\Normalizer;
use App\Contracts\WhatsAppProvider;
use App\Embeddings\EmbeddingService;
use App\Integrations\Evolution\EvolutionNormalizer;
use App\Integrations\Evolution\EvolutionProvider;
use App\Integrations\OpenAI\OpenAIAnswerGenerator;
use App\Integrations\OpenAI\OpenAIAudioProcessor;
use App\Integrations\OpenAI\OpenAIEmbeddingProvider;
use App\Integrations\OpenAI\OpenAIImageProcessor;
use App\Integrations\WhatsAppProviderResolver;
use App\Models\Instance;
use App\Processing\PdfProcessor;
use App\Processing\ProcessingService;
use Illuminate\Support\ServiceProvider;
use Lorisleiva\Actions\Facades\Actions;
use RuntimeException;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WhatsAppProvider::class, function () {
            // The default instance is the continuation of the former
            // single-instance (env-driven) setup. Until the instances table is
            // migrated (or if the database is unreachable) fall back to env.
            try {
                $config = Instance::default()?->providerConfig() ?? config('warm.evolution');
            } catch (Throwable) {
                $config = config('warm.evolution');
            }

            return match ($provider = config('warm.provider')) {
                'evolution' => new EvolutionProvider($config),
                default => throw new RuntimeException("Unsupported WhatsApp provider: {$provider}"),
            };
        });

        $this->app->singleton(WhatsAppProviderResolver::class);

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

        $this->app->singleton(KnowledgeBase::class, fn () => new MessageKnowledgeBase);

        $this->app->singleton(AnswerGenerator::class, fn () => new OpenAIAnswerGenerator(config('warm.attendance')));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            Actions::registerCommands();
        }
    }
}
