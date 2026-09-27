<?php

namespace App\Models;

use App\Support\MediaStorage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperMedia
 */
class Media extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'media';

    protected $fillable = [
        'message_id',
        'type',
        'filename',
        'mime_type',
        'size_bytes',
        'storage_key',
        'extracted_text',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Single response shape for media actions (media.download / media.upload).
     *
     * @return array{mediaId: string, storageKey: string, mimeType: ?string, sizeBytes: ?int, filename: ?string, extractedText: ?string, processing: mixed, signedUrl: string}
     */
    public function present(): array
    {
        return [
            'mediaId' => $this->id,
            'storageKey' => $this->storage_key,
            'mimeType' => $this->mime_type,
            'sizeBytes' => $this->size_bytes,
            'filename' => $this->filename,
            'extractedText' => $this->extracted_text,
            'processing' => $this->metadata['processing'] ?? null,
            'signedUrl' => app(MediaStorage::class)->getSignedUrl($this->storage_key),
        ];
    }
}
