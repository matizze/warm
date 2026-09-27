<?php

namespace App\Actions\Media;

use App\Models\Media;
use App\Support\MediaStorage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class UploadMedia
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        $buffer = base64_decode((string) $input['base64'], true);
        if ($buffer === false) {
            throw new RuntimeException('Invalid base64 payload');
        }

        $filename = (string) $input['filename'];
        $mimeType = (string) ($input['mimeType'] ?? 'application/octet-stream');
        $storageKey = 'media/uploads/'.Str::uuid()."/{$filename}";

        app(MediaStorage::class)->put($storageKey, $buffer, $mimeType);

        $media = Media::create([
            'message_id' => $input['messageId'] ?? null,
            'type' => (string) $input['type'],
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($buffer),
            'storage_key' => $storageKey,
            'extracted_text' => null,
            'metadata' => [],
        ]);

        $media = ProcessMedia::run($media, $buffer);

        return [
            'mediaId' => $media->id,
            'storageKey' => $media->storage_key,
            'mimeType' => $media->mime_type,
            'sizeBytes' => $media->size_bytes,
            'filename' => $media->filename,
            'extractedText' => $media->extracted_text,
            'processing' => $media->metadata['processing'] ?? null,
            'signedUrl' => app(MediaStorage::class)->getSignedUrl($media->storage_key),
        ];
    }
}
