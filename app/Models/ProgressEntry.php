<?php

namespace App\Models;

use App\Enums\ProgressEntryStatus;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "lançamento de andamento": the transcribed text of a team audio, linked to
 * the client it was about (or kept pending when the client is unknown).
 *
 * @mixin IdeHelperProgressEntry
 */
class ProgressEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_id',
        'conversation_id',
        'instance_id',
        'source_message_id',
        'author_contact_id',
        'content',
        'status',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProgressEntryStatus::class,
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_id', 'instance_id');
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'author_contact_id');
    }

    public function scopeForClient(Builder $query, Client|string $client): Builder
    {
        return $query->where('client_id', $client instanceof Client ? $client->id : $client);
    }

    public function scopeOnDay(Builder $query, DateTimeInterface|string $day): Builder
    {
        return $query->whereDate('occurred_at', $day);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ProgressEntryStatus::Pending->value);
    }

    public function scopeRecorded(Builder $query): Builder
    {
        return $query->where('status', ProgressEntryStatus::Recorded->value);
    }
}
