<?php

namespace App\Support\Media;

use InvalidArgumentException;

/**
 * Media type, size limit and content-sniffing rules shared by every upload
 * path (MCP `media.upload` and the multipart endpoint).
 */
final class MediaType
{
    /**
     * @var list<string>
     */
    public const TYPES = ['image', 'audio', 'video', 'document'];

    /**
     * @return array<string, int>
     */
    public static function limits(): array
    {
        $limits = config('warm.media.max_bytes', []);

        return is_array($limits) ? array_map('intval', $limits) : [];
    }

    public static function maxBytes(string $type): int
    {
        return self::limits()[$type] ?? 0;
    }

    public static function normalize(mixed $type): string
    {
        $value = strtolower(trim((string) $type));

        if (! in_array($value, self::TYPES, true)) {
            throw new InvalidArgumentException('Tipo de mídia inválido: use image, audio, video ou document.');
        }

        return $value;
    }

    /**
     * Maps a concrete mime type to the storage type it belongs to. Returns
     * null when the mime type says nothing useful about the category.
     */
    public static function categoryForMime(string $mimeType): ?string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'audio/') => 'audio',
            str_starts_with($mimeType, 'video/') => 'video',
            $mimeType === '', $mimeType === 'application/octet-stream' => null,
            default => 'document',
        };
    }

    /**
     * Detects the mime type from the file contents, never from the filename.
     */
    public static function detect(string $contents): string
    {
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        if (! is_string($detected) || $detected === '') {
            return 'application/octet-stream';
        }

        return strtolower(trim(explode(';', $detected)[0]));
    }

    public static function assertWithinLimit(string $type, int $sizeBytes): void
    {
        $max = self::maxBytes($type);

        if ($max > 0 && $sizeBytes > $max) {
            throw new InvalidArgumentException(sprintf(
                'Arquivo acima do limite para %s: %s (máximo %s).',
                $type,
                self::humanSize($sizeBytes),
                self::humanSize($max),
            ));
        }
    }

    /**
     * Checks that the declared type, the declared mime type and the mime type
     * sniffed from the contents agree, and returns the mime type to persist.
     */
    public static function assertMimeMatches(string $type, string $detected, ?string $declared): string
    {
        $detectedCategory = self::categoryForMime($detected);

        // `document` is the catch-all WhatsApp type: an image, audio or video
        // file may legitimately be sent as a document. The reverse is not true.
        if ($type !== 'document' && $detectedCategory !== null && $detectedCategory !== $type) {
            throw new InvalidArgumentException(
                "Mimetype divergente: o conteúdo é {$detected}, incompatível com o tipo {$type}."
            );
        }

        if ($declared === null || trim($declared) === '') {
            return $detected;
        }

        $declaredMime = strtolower(trim(explode(';', $declared)[0]));
        $declaredCategory = self::categoryForMime($declaredMime);

        if ($declaredCategory === null) {
            return $detectedCategory !== null ? $detected : $declaredMime;
        }

        if ($type !== 'document' && $declaredCategory !== $type) {
            throw new InvalidArgumentException(
                "Mimetype divergente: {$declaredMime} não corresponde ao tipo {$type}."
            );
        }

        if ($detectedCategory !== null && $declaredCategory !== null && $declaredMime !== $detected) {
            throw new InvalidArgumentException(
                "Mimetype divergente: informado {$declaredMime}, conteúdo é {$detected}."
            );
        }

        return $declaredMime;
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return "{$bytes} B";
    }
}
