<?php

namespace App\Actions\Conversations;

use App\Models\Conversation;
use DateTimeInterface;
use Lorisleiva\Actions\Concerns\AsAction;

final class TouchConversation
{
    use AsAction;

    public function handle(string $conversationId, DateTimeInterface $at): void
    {
        Conversation::whereKey($conversationId)
            ->where(function ($query) use ($at) {
                $query->whereNull('last_message_at')
                    ->orWhere('last_message_at', '<', $at);
            })
            ->update([
                'last_message_at' => $at,
                'updated_at' => $at,
            ]);
    }
}
