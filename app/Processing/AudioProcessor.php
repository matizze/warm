<?php

namespace App\Processing;

use App\Integrations\OpenAI\OpenAIClient;
use RuntimeException;

/**
 * Transcribes audio with OpenAI Whisper.
 */
final class AudioProcessor implements MediaProcessor
{
    public function __construct(
        private readonly OpenAIClient $client,
        private readonly string $model,
    ) {}

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
        if (! $this->client->configured()) {
            throw new RuntimeException('OPENAI_API_KEY is not set; cannot transcribe');
        }

        $text = $this->client->transcribe($this->model, $buffer, $context->filename);

        return new ProcessedContent(
            kind: 'transcription',
            text: $text,
            metadata: ['processor' => $this->name()],
        );
    }
}
