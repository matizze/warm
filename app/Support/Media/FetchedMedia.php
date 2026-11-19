<?php

namespace App\Support\Media;

/**
 * A media file downloaded from a remote URL, still in memory and not yet
 * validated against the per-type rules.
 */
final readonly class FetchedMedia
{
    public function __construct(
        public string $contents,
        public string $mimeType,
        public ?string $filename,
    ) {}
}
