<?php

namespace App\Actions\Conversations;

use App\Actions\Groups\FindOrCreateGroup;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Instance;
use Lorisleiva\Actions\Concerns\AsAction;

final class FindOrCreateGroupConversation
{
    use AsAction;

    public function handle(string $providerGroupId, ?Instance $instance = null): Conversation
    {
        $group = FindOrCreateGroup::run($providerGroupId);

        $instance ??= Instance::default();

        $attributes = [
            'group_id' => $group->id,
            'type' => ConversationType::Group->value,
        ];

        if ($instance !== null) {
            $attributes['instance_id'] = $instance->instance_id;
        }

        return Conversation::firstOrCreate($attributes);
    }
}
