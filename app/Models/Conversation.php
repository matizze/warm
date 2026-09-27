<?php

namespace App\Models;

use App\Enums\ConversationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin IdeHelperConversation
 */
class Conversation extends Model
{
    use HasUuids;

    protected $fillable = [
        'type',
        'contact_id',
        'group_id',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'last_message_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Resolves the provider address (phone number or group JID) used to
     * reach this conversation. Single source of truth for sending and for
     * downloading media from the provider.
     */
    public function resolveProviderRecipient(): ?string
    {
        return $this->contact?->phone_number
            ?? $this->group?->provider_group_id;
    }
}
