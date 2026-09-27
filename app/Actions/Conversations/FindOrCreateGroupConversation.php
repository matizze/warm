<?php

namespace App\Actions\Conversations;

use App\Actions\Groups\FindOrCreateGroup;
use App\Enums\ConversationType;
use App\Models\Conversation;
use Lorisleiva\Actions\Concerns\AsAction;

final class FindOrCreateGroupConversation
{
    use AsAction;

    public function handle(string $providerGroupId): Conversation
    {
        $group = FindOrCreateGroup::run($providerGroupId);

        return Conversation::firstOrCreate([
            'group_id' => $group->id,
            'type' => ConversationType::Group->value,
        ]);
    }
}
