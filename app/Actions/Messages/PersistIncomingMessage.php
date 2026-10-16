<?php

namespace App\Actions\Messages;

use App\Actions\Contacts\FindOrCreateContact;
use App\Actions\Conversations\FindOrCreateConversation;
use App\Actions\Conversations\FindOrCreateGroupConversation;
use App\Data\IncomingMessage;
use App\Enums\ConversationType;
use App\Enums\MessageStatus;
use App\Models\Instance;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final class PersistIncomingMessage
{
    use AsAction;

    /**
     * Persists an inbound/echo message and returns it, or null when the
     * provider message id was already stored (idempotency).
     */
    public function handle(IncomingMessage $incoming, ?Instance $instance = null): ?Message
    {
        $instance ??= Instance::default();

        $contact = FindOrCreateContact::run($incoming->senderContactId, $incoming->senderName);

        $conversation = $incoming->conversationType === ConversationType::Group
            ? FindOrCreateGroupConversation::run($incoming->providerConversationId, $instance)
            : FindOrCreateConversation::run($contact->id, $instance);

        $metadata = array_filter([
            ...$incoming->metadata,
            'mediaUrl' => $incoming->mediaUrl,
            'mediaType' => $incoming->mediaType,
        ], fn ($value) => $value !== null);

        $id = (string) Str::uuid();

        // ON CONFLICT DO NOTHING keeps this idempotent without throwing, so it
        // is safe to call inside a surrounding transaction.
        $inserted = DB::table('messages')->insertOrIgnore([
            'id' => $id,
            'conversation_id' => $conversation->id,
            'instance_id' => $conversation->instance_id,
            'provider_message_id' => $incoming->providerId,
            'direction' => $incoming->direction->value,
            'type' => $incoming->type->value,
            'sender_contact_id' => $contact->id,
            'content' => $incoming->content,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'status' => MessageStatus::Sent->value,
            'created_at' => now(),
        ]);

        return $inserted === 0 ? null : Message::find($id);
    }
}
