<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationType;
use App\Models\Conversation;
use Lorisleiva\Actions\Concerns\AsAction;

final class FindOrCreateConversation
{
    use AsAction;

    public function handle(string $contactId): Conversation
    {
        return Conversation::firstOrCreate(
            ['contact_id' => $contactId, 'type' => ConversationType::Contact->value],
        );
    }
}
