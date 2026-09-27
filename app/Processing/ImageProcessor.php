<?php

namespace App\Processing;

use App\Integrations\OpenAI\OpenAIClient;

/**
 * Describes images and extracts visible text (OCR) via OpenAI vision.
 */
final class ImageProcessor implements MediaProcessor
{
    private const PROMPT = 'Analise a imagem. Responda SOMENTE em JSON com as chaves: '
        .'"description" (descrição objetiva da imagem em português) e '
        .'"text" (todo o texto visível na imagem, ou string vazia se não houver).';

    public function __construct(
        private readonly OpenAIClient $client,
        private readonly string $model,
    ) {}

    public function name(): string
    {
        return 'image';
    }

    public function supports(ProcessingContext $context): bool
    {
        return $context->type === 'image' || str_starts_with($context->mimeType, 'image/');
    }

    public function process(string $buffer, ProcessingContext $context): ?ProcessedContent
    {
        if (! $this->client->configured()) {
            return null;
        }

        $dataUrl = 'data:'.($context->mimeType ?: 'image/jpeg').';base64,'.base64_encode($buffer);

        $raw = $this->client->chat($this->model, [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => self::PROMPT],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
            ],
        ]], jsonObject: true);

        $description = '';
        $ocr = '';
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            $description = trim((string) ($parsed['description'] ?? ''));
            $ocr = trim((string) ($parsed['text'] ?? ''));
        } else {
            $description = trim($raw);
        }

        $text = implode("\n", array_filter([
            $description,
            $ocr !== '' ? "Texto na imagem: {$ocr}" : '',
        ]));

        return new ProcessedContent(
            kind: 'description',
            text: $text,
            metadata: [
                'model' => $this->model,
                'processor' => $this->name(),
                'ocr' => $ocr !== '' ? $ocr : null,
            ],
        );
    }
}
