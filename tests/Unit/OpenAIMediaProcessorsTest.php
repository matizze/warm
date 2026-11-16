<?php

namespace Tests\Unit;

use App\Integrations\OpenAI\OpenAIAudioProcessor;
use App\Integrations\OpenAI\OpenAIImageProcessor;
use App\Processing\ProcessingContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OpenAIMediaProcessorsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'openai_key' => 'sk-test',
            'openai_base_url' => 'http://localhost:1234/v1',
            'transcription_model' => 'whisper-1',
            'vision_model' => 'gpt-4o-mini',
        ];
    }

    public function test_audio_processor_transcribes_audio(): void
    {
        Http::fake([
            '*/audio/transcriptions' => Http::response(['text' => 'Ola WARM']),
        ]);

        $context = new ProcessingContext(type: 'audio', mimeType: 'audio/ogg', filename: 'note.ogg');
        $result = (new OpenAIAudioProcessor($this->config()))->process('audio-bytes', $context);

        $this->assertNotNull($result);
        $this->assertSame('transcription', $result->kind);
        $this->assertSame('Ola WARM', $result->text);

        Http::assertSent(fn ($request) => $request->url() === 'http://localhost:1234/v1/audio/transcriptions');
    }

    public function test_audio_processor_skips_without_key(): void
    {
        Log::spy();

        $context = new ProcessingContext(type: 'audio', mimeType: 'audio/ogg', filename: 'note.ogg');

        $this->assertNull((new OpenAIAudioProcessor(['openai_key' => '']))->process('audio-bytes', $context));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'OPENAI_API_KEY ausente'));
    }

    public function test_image_processor_describes_image_and_extracts_ocr(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"description":"Um contrato","text":"CLÁUSULA 1"}']]],
            ]),
        ]);

        $context = new ProcessingContext(type: 'image', mimeType: 'image/png', filename: 'scan.png');
        $result = (new OpenAIImageProcessor($this->config()))->process('img-bytes', $context);

        $this->assertNotNull($result);
        $this->assertSame('description', $result->kind);
        $this->assertStringContainsString('Um contrato', $result->text);
        $this->assertStringContainsString('CLÁUSULA 1', $result->text);

        Http::assertSent(fn ($request) => $request->url() === 'http://localhost:1234/v1/chat/completions');
    }

    public function test_image_processor_returns_null_without_key(): void
    {
        Log::spy();

        $context = new ProcessingContext(type: 'image', mimeType: 'image/png', filename: 'scan.png');

        $this->assertNull((new OpenAIImageProcessor(['openai_key' => '']))->process('img', $context));

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'OPENAI_API_KEY ausente'));
    }
}
