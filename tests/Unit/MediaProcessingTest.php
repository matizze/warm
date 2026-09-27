<?php

namespace Tests\Unit;

use App\Processing\ProcessingContext;
use App\Processing\ProcessingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediaProcessingTest extends TestCase
{
    private function context(string $type, string $mimeType): ProcessingContext
    {
        return new ProcessingContext(type: $type, mimeType: $mimeType, filename: "arquivo.{$type}");
    }

    public function test_describes_an_image_through_the_shared_openai_client(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'description' => 'um gato preto',
                    'text' => 'MIAU',
                ])]]],
            ]),
        ]);
        config([
            'warm.openai.api_key' => 'sk-test',
            'warm.openai.base_url' => 'https://api.openai.test/v1',
            'warm.processing.vision_model' => 'gpt-4o-mini',
        ]);

        $results = app(ProcessingService::class)->process('binary-image', $this->context('image', 'image/jpeg'));

        $this->assertCount(1, $results);
        $this->assertSame('description', $results[0]->kind);
        $this->assertStringContainsString('um gato preto', $results[0]->text);
        $this->assertStringContainsString('MIAU', $results[0]->text);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-test'));
    }

    public function test_transcribes_audio_through_the_shared_openai_client(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/audio/transcriptions' => Http::response(['text' => 'olá, tudo bem?']),
        ]);
        config([
            'warm.openai.api_key' => 'sk-test',
            'warm.openai.base_url' => 'https://api.openai.test/v1',
            'warm.processing.transcription_model' => 'whisper-1',
        ]);

        $results = app(ProcessingService::class)->process('binary-audio', $this->context('audio', 'audio/ogg'));

        $this->assertCount(1, $results);
        $this->assertSame('transcription', $results[0]->kind);
        $this->assertSame('olá, tudo bem?', $results[0]->text);
    }

    public function test_image_processing_is_skipped_without_openai_credentials(): void
    {
        Http::preventStrayRequests();
        config(['warm.openai.api_key' => '']);

        $results = app(ProcessingService::class)->process('binary-image', $this->context('image', 'image/jpeg'));

        $this->assertSame([], $results);
        Http::assertNothingSent();
    }
}
