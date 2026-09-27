<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

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

    public function ensureBucket(): void
    {
        // S3 buckets are provisioned out of band (mc/rustfs). Local needs nothing.
    }

    public function put(string $key, string $contents, ?string $mimeType = null): void
    {
        $options = $mimeType ? ['ContentType' => $mimeType] : [];

        $this->disk()->put($key, $contents, $options);
    }

    public function get(string $key): string
    {
        return $this->disk()->get($key);
    }

    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    public function getSignedUrl(string $key): string
    {
        $ttl = (int) config('warm.storage.signed_url_ttl', 3600);

        try {
            return $this->disk()->temporaryUrl($key, now()->addSeconds($ttl));
        } catch (Throwable) {
            // Local driver has no temporary URLs; fall back to the plain path.
            return $this->disk()->path($key);
        }
    }
}
