<?php

namespace App\Models;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperMessage
 */
class Message extends Model
{
    use HasUuids;

    /**
     * Messages are immutable: they only carry created_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'conversation_id',
        'provider_message_id',
        'direction',
        'type',
        'sender_contact_id',
        'content',
        'metadata',
        'status',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $hidden = [
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'type' => MessageType::class,
            'status' => MessageStatus::class,
            'metadata' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'sender_contact_id');
    }

    /**
     * Lexical search across the raw content and the indexed search_text
     * (which also contains media extracted text / transcription / description).
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('content', 'ilike', $like)
                ->orWhere('search_text', 'ilike', $like);
        });
    }
}
