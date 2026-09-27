<?php

namespace App\Integrations\OpenAI;

use App\Contracts\MediaProcessor;
use App\Processing\ProcessedContent;
use App\Processing\ProcessingContext;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Transcribes audio with OpenAI Whisper.
 */
final class OpenAIAudioProcessor implements MediaProcessor
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'audio';
    }

    public function supports(ProcessingContext $context): bool
    {
        return $context->type === 'audio' || str_starts_with($context->mimeType, 'audio/');
    }

    public function process(string $buffer, ProcessingContext $context): ?ProcessedContent
    {
        $key = $this->config['openai_key'] ?? '';
        if ($key === '') {
            throw new RuntimeException('OPENAI_API_KEY is not set; cannot transcribe');
        }

        $response = Http::withToken($key)
            ->timeout(120)
            ->attach('file', $buffer, $context->filename)
            ->post($this->config['openai_base_url'].'/audio/transcriptions', [
                'model' => $this->config['transcription_model'],
            ])
            ->throw();

        return new ProcessedContent(
            kind: 'transcription',
            text: trim((string) $response->json('text')),
            metadata: ['processor' => $this->name()],
        );
    }
}
