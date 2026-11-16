<?php

namespace App\Actions\Messages;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Persists a message sent through the provider. Idempotent by the provider
 * message id: the webhook echo (`send.message`) and the send path can race
 * without creating two rows.
 */
final class PersistOutgoingMessage
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        Conversation $conversation,
        MessageType $type,
        ?string $content,
        MessageStatus $status,
        ?string $providerMessageId = null,
        array $metadata = [],
    ): Message {
        $now = now();
        $confirmed = in_array($status, MessageStatus::confirmed(), true);

        $attributes = [
            'conversation_id' => $conversation->id,
            'instance_id' => $conversation->instance_id,
            'direction' => MessageDirection::Outbound,
            'type' => $type,
            'sender_contact_id' => null,
            'content' => $content,
            'metadata' => $metadata,
            'status' => $status,
            'sent_at' => $confirmed ? $now : null,
            'delivered_at' => in_array($status, [MessageStatus::Delivered, MessageStatus::Read], true) ? $now : null,
            'read_at' => $status === MessageStatus::Read ? $now : null,
        ];

        if ($providerMessageId === null) {
            return Message::create($attributes);
        }

        return Message::firstOrCreate(
            ['provider_message_id' => $providerMessageId],
            $attributes,
        );
    }
}
