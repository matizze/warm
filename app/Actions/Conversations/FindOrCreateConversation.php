<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Instance;
use Lorisleiva\Actions\Concerns\AsAction;

final class FindOrCreateConversation
{
    use AsAction;

    public function handle(string $contactId, ?Instance $instance = null): Conversation
    {
        $instance ??= Instance::default();

        $attributes = [
            'contact_id' => $contactId,
            'type' => ConversationType::Contact->value,
        ];

        if ($instance !== null) {
            $attributes['instance_id'] = $instance->instance_id;
        }

        return Conversation::firstOrCreate($attributes);
    }
}
