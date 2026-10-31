<?php

namespace App\Models;

use App\Enums\ClientChannelRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a conversation to a client and gives it a role. The conversation is
 * the reversible key: the same conversation always resolves to at most one
 * client, and deactivating the row stops new progress entries for it.
 *
 * @mixin IdeHelperClientChannel
 */
class ClientChannel extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_id',
        'conversation_id',
        'role',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'role' => ClientChannelRole::class,
            'active' => 'boolean',
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeRole(Builder $query, ClientChannelRole $role): Builder
    {
        return $query->where('role', $role->value);
    }
}
