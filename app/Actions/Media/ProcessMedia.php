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
     * description) best-effort: failures are captured in metadata, never thrown.
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

        $metadata = $media->metadata ?? [];

        try {
            $results = app(ProcessingService::class)->process($buffer, $context);

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
        } catch (Throwable $e) {
            $metadata['processing'] = ['status' => 'failed', 'error' => $e->getMessage()];
            $media->forceFill(['metadata' => $metadata])->save();

            return $media;
        }
    }
}
