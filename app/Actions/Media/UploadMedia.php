<?php

namespace App\Actions\Media;

use App\Actions\Messages\IndexMessage;
use App\Models\Media;
use App\Support\Media\MediaType;
use App\Support\Media\RemoteMediaFetcher;
use App\Support\MediaStorage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class UploadMedia
{
    use AsAction;

    /**
     * Accepts the media from one of three sources: a remote `url` (downloaded
     * with the SSRF/size protections), an inline `base64` payload, or raw
     * `contents` already read by the multipart endpoint. Every source goes
     * through the same type, size and mime validation before persisting.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(array $input): array
    {
        $type = MediaType::normalize($input['type'] ?? null);
        $source = $this->resolveSource($input, $type);

        $buffer = $source['contents'];
        MediaType::assertWithinLimit($type, strlen($buffer));

        $mimeType = MediaType::assertMimeMatches(
            $type,
            MediaType::detect($buffer),
            $source['mimeType'] ?? (isset($input['mimeType']) ? (string) $input['mimeType'] : null),
        );

        $filename = $this->sanitizeFilename((string) ($input['filename'] ?? $source['filename'] ?? 'upload'));
        $storageKey = 'media/uploads/'.Str::uuid()."/{$filename}";

        app(MediaStorage::class)->put($storageKey, $buffer, $mimeType);

        $media = Media::create([
            'message_id' => $input['messageId'] ?? null,
            'type' => $type,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($buffer),
            'storage_key' => $storageKey,
            'extracted_text' => null,
            'metadata' => [],
        ]);

        $media = ProcessMedia::run($media, $buffer);

        if ($media->message_id !== null) {
            IndexMessage::dispatch($media->message_id);
        }

        return $media->present();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{contents: string, mimeType: ?string, filename: ?string}
     */
    private function resolveSource(array $input, string $type): array
    {
        $url = isset($input['url']) && $input['url'] !== '' ? (string) $input['url'] : null;
        $base64 = isset($input['base64']) && $input['base64'] !== '' ? (string) $input['base64'] : null;
        $hasContents = array_key_exists('contents', $input);

        $sources = (int) ($url !== null) + (int) ($base64 !== null) + (int) $hasContents;

        if ($sources !== 1) {
            throw new InvalidArgumentException('Informe apenas uma origem para a mídia: url, base64 ou file.');
        }

        if ($url !== null) {
            $fetched = app(RemoteMediaFetcher::class)->fetch($url, $type);

            return [
                'contents' => $fetched->contents,
                'mimeType' => $fetched->mimeType !== '' ? $fetched->mimeType : null,
                'filename' => $fetched->filename,
            ];
        }

        if ($base64 !== null) {
            $buffer = base64_decode($base64, true);

            if ($buffer === false) {
                throw new InvalidArgumentException('Mídia inválida: o base64 não pôde ser decodificado.');
            }

            return [
                'contents' => $buffer,
                'mimeType' => isset($input['mimeType']) ? (string) $input['mimeType'] : null,
                'filename' => null,
            ];
        }

        return [
            'contents' => (string) $input['contents'],
            'mimeType' => isset($input['mimeType']) ? (string) $input['mimeType'] : null,
            'filename' => null,
        ];
    }

    /**
     * Reduces the user-supplied filename to a single safe basename so the
     * storage key cannot escape the media directory (path traversal).
     */
    private function sanitizeFilename(string $filename): string
    {
        $name = basename(str_replace('\\', '/', trim($filename)));

        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, "\0")) {
            throw new InvalidArgumentException('Nome de arquivo inválido.');
        }

        return $name;
    }
}
