<?php

namespace App\Actions\Media;

use App\Models\Media;
use App\Processing\ProcessingContext;
use App\Processing\ProcessingService;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class ProcessMedia
{
    use AsAction;

    /**
     * Processes media content (PDF text, audio transcription, image
     * description) best-effort: a processor failure is a non-critical
     * fallback, so the real cause is captured in metadata via rescue() and the
     * media is never lost. Processing failures never fail the surrounding job.
     */
    public function handle(Media $media, string $buffer): Media
    {
        if (! config('warm.processing.enabled')) {
            return $media;
        }

        $context = new ProcessingContext(
            type: (string) $media->type,
            mimeType: (string) ($media->mime_type ?? ''),
            filename: (string) ($media->filename ?? ''),
        );

        $results = rescue(
            fn (): array => app(ProcessingService::class)->process($buffer, $context),
            fn (Throwable $e): ?array => $this->recordFailure($media, $e),
            false,
        );

        if ($results === null) {
            return $media;
        }

        $metadata = $media->metadata ?? [];

        if ($results === []) {
            $metadata['processing'] = ['status' => 'skipped', 'processor' => null];
            $media->forceFill(['extracted_text' => '', 'metadata' => $metadata])->save();

            return $media;
        }

        $extracted = implode("\n\n", array_filter(array_map(fn ($r) => $r->text, $results)));
        $metadata['processing'] = [
            'status' => 'done',
            'kinds' => array_map(fn ($r) => $r->kind, $results),
            'details' => array_map(fn ($r) => ['kind' => $r->kind, ...$r->metadata], $results),
        ];
        $media->forceFill(['extracted_text' => $extracted, 'metadata' => $metadata])->save();

        return $media;
    }

    /**
     * Logs the cause and persists the failure on the media row. Returns null,
     * which is the rescue() marker the caller uses to stop processing.
     */
    private function recordFailure(Media $media, Throwable $e): ?array
    {
        logger()->warning('Falha ao processar mídia', [
            'media_id' => $media->id,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        $metadata = $media->metadata ?? [];
        $metadata['processing'] = [
            'status' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 500),
        ];
        $media->forceFill(['metadata' => $metadata])->save();

        return null;
    }
}
