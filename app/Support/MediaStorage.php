<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Thin wrapper over the "warm" disk so application code depends on a single
 * seam instead of the Storage facade directly.
 */
final class MediaStorage
{
    private function disk(): Filesystem
    {
        return Storage::disk(config('warm.storage.disk', 'warm'));
    }

    public function put(string $key, string $contents, ?string $mimeType = null): void
    {
        $options = $mimeType ? ['ContentType' => $mimeType] : [];

        if (! $this->disk()->put($key, $contents, $options)) {
            throw new RuntimeException('Não foi possível armazenar a mídia.');
        }
    }

    public function get(string $key): string
    {
        return $this->disk()->get($key);
    }

    public function getSignedUrl(string $key): string
    {
        $ttl = (int) config('warm.storage.signed_url_ttl', 3600);

        return $this->disk()->temporaryUrl($key, now()->addSeconds($ttl));
    }
}
