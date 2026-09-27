<?php

namespace App\Processing;

use Illuminate\Support\Facades\Http;

/**
 * Describes images and extracts visible text (OCR) via OpenAI vision.
 */
final class ImageProcessor implements MediaProcessor
{
    private const PROMPT = 'Analise a imagem. Responda SOMENTE em JSON com as chaves: '
        .'"description" (descrição objetiva da imagem em português) e '
        .'"text" (todo o texto visível na imagem, ou string vazia se não houver).';

    public function __construct(private readonly array $config) {}

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
        $key = $this->config['openai_key'] ?? '';
        if ($key === '') {
            return null;
        }

        $dataUrl = 'data:'.($context->mimeType ?: 'image/jpeg').';base64,'.base64_encode($buffer);

        $response = Http::withToken($key)
            ->timeout(120)
            ->post($this->config['openai_base_url'].'/chat/completions', [
                'model' => $this->config['vision_model'],
                'response_format' => ['type' => 'json_object'],
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => self::PROMPT],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ],
                ]],
            ])
            ->throw();

        $raw = (string) $response->json('choices.0.message.content', '{}');

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
                'model' => $this->config['vision_model'],
                'processor' => $this->name(),
                'ocr' => $ocr !== '' ? $ocr : null,
            ],
        );
    }
}
