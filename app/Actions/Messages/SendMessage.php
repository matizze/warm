<?php

namespace App\Actions\Messages;

use App\Actions\Conversations\FindOrCreateConversation;
use App\Actions\Conversations\TouchConversation;
use App\Data\OutgoingMessage;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Integrations\WhatsAppProviderResolver;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Instance;
use App\Models\Media;
use App\Models\Message;
use App\Support\MediaStorage;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final class SendMessage
{
    use AsAction;

    /**
     * Sends through an existing conversation (`conversationId`) or directly to
     * a contact (`contactId`). The contact path validates the stored phone,
     * reuses the contact's conversation on the calling instance or creates it,
     * so a contact with no inbound history can still be reached.
     *
     * Expected failures are returned as a `status: error` payload; a provider
     * failure propagates as a typed `ProviderException` that the MCP layer
     * maps back to `status: error`.
     *
     * @param  array<string, mixed>  $input
     * @return array{status: string, messageId?: ?string, providerMessageId: ?string, providerStatus?: ?string, conversationId?: ?string, contactId?: ?string, error?: string, idempotentReplay?: bool}
     */
    public function handle(array $input, ?Instance $contextInstance = null): array
    {
        $type = MessageType::tryFrom((string) ($input['type'] ?? 'text')) ?? MessageType::Text;

        $hasConversation = isset($input['conversationId']);
        $hasContact = isset($input['contactId']);

        if ($hasConversation === $hasContact) {
            return $this->error('Informe exatamente um entre conversationId e contactId.');
        }

        return $hasContact
            ? $this->sendByContact((string) $input['contactId'], $input, $type, $contextInstance)
            : $this->sendByConversation((string) $input['conversationId'], $input, $type, $contextInstance);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: string, messageId?: ?string, providerMessageId: ?string, providerStatus?: ?string, conversationId?: ?string, contactId?: ?string, error?: string, idempotentReplay?: bool}
     */
    private function sendByConversation(string $conversationId, array $input, MessageType $type, ?Instance $contextInstance): array
    {
        $conversation = $this->resolveConversation($conversationId);
        if ($conversation === null) {
            return $this->error("conversation not found: {$conversationId}");
        }

        // The MCP context scopes tool calls to one instance: a client of one
        // number must not be able to send through another number's conversation.
        if ($contextInstance !== null && $conversation->instance_id !== $contextInstance->instance_id) {
            return $this->error("conversation {$conversation->id} belongs to another instance");
        }

        $recipient = $conversation->resolveProviderRecipient();
        if ($recipient === null) {
            return $this->error("conversation not found: {$conversationId}");
        }

        return $this->dispatch($conversation, $conversation->instance, $input, $type, $recipient, 'conversationId');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: string, messageId?: ?string, providerMessageId: ?string, providerStatus?: ?string, conversationId?: ?string, contactId?: ?string, error?: string, idempotentReplay?: bool}
     */
    private function sendByContact(string $contactId, array $input, MessageType $type, ?Instance $contextInstance): array
    {
        $contact = Str::isUuid($contactId) ? Contact::find($contactId) : null;
        if ($contact === null) {
            return $this->error("contact not found: {$contactId}");
        }

        // Validate the phone before creating the conversation: a contact with
        // no usable number must not leave a conversation behind.
        $digits = PhoneNumber::normalize($contact->phone_number);
        if ($digits === null) {
            return $this->error("contact {$contact->id} has no valid phone number");
        }

        $instance = $contextInstance ?? Instance::default();
        if ($instance === null) {
            return $this->error('no instance available to send by contact');
        }

        $conversation = FindOrCreateConversation::run($contact->id, $instance);

        return $this->dispatch(
            $conversation,
            $instance,
            $input,
            $type,
            PhoneNumber::contactJid($digits),
            'contactId',
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: string, messageId?: ?string, providerMessageId: ?string, providerStatus?: ?string, conversationId?: ?string, contactId?: ?string, error?: string, idempotentReplay?: bool}
     */
    private function dispatch(
        Conversation $conversation,
        ?Instance $instance,
        array $input,
        MessageType $type,
        string $recipient,
        string $sentBy,
    ): array {
        $idempotencyKey = isset($input['idempotencyKey']) && trim((string) $input['idempotencyKey']) !== ''
            ? trim((string) $input['idempotencyKey'])
            : null;

        if ($idempotencyKey !== null) {
            $existing = Message::query()
                ->where('conversation_id', $conversation->id)
                ->whereRaw("metadata->>'idempotencyKey' = ?", [$idempotencyKey])
                ->first();

            if ($existing !== null) {
                return $this->payload($existing, idempotentReplay: true);
            }
        }

        $mediaBase64 = null;
        $mimetype = null;
        $fileName = null;

        if (! empty($input['mediaId'])) {
            $resolved = $this->resolveMedia((string) $input['mediaId']);
            if ($resolved === null) {
                return $this->error("media not found: {$input['mediaId']}");
            }

            $mediaBase64 = $resolved['base64'];
            $mimetype = $resolved['mimetype'];
            $fileName = $resolved['fileName'];
        }

        $provider = app(WhatsAppProviderResolver::class)->forInstance($instance);

        $result = $provider->sendMessage(new OutgoingMessage(
            recipient: $recipient,
            type: $type,
            content: isset($input['content']) ? (string) $input['content'] : null,
            mediaBase64: $mediaBase64,
            fileName: $fileName,
            mimetype: $mimetype,
        ));

        $status = MessageStatus::fromProvider($result->status);

        $message = PersistOutgoingMessage::run(
            conversation: $conversation,
            type: $type,
            content: isset($input['content']) ? (string) $input['content'] : null,
            status: $status,
            providerMessageId: $result->providerMessageId,
            metadata: array_filter([
                'providerStatus' => $result->providerStatus,
                'idempotencyKey' => $idempotencyKey,
                'sentBy' => $sentBy,
            ], fn ($value) => $value !== null),
        );

        TouchConversation::run($conversation->id, now());

        $payload = $this->payload($message);

        if ($status === MessageStatus::Failed) {
            $payload['error'] = $result->error ?? 'O provedor recusou a mensagem.';
        }

        return $payload;
    }

    /**
     * @return array{status: string, messageId: string, providerMessageId: ?string, providerStatus: ?string, conversationId: string, contactId: ?string, idempotentReplay?: bool}
     */
    private function payload(Message $message, bool $idempotentReplay = false): array
    {
        $metadata = is_array($message->metadata) ? $message->metadata : [];

        $payload = [
            'status' => $message->status->value,
            'messageId' => $message->id,
            'providerMessageId' => $message->provider_message_id,
            'providerStatus' => $metadata['providerStatus'] ?? null,
            'conversationId' => $message->conversation_id,
            'contactId' => $message->conversation?->contact_id,
        ];

        if ($idempotentReplay) {
            $payload['idempotentReplay'] = true;
        }

        return $payload;
    }

    /**
     * @return array{status: string, providerMessageId: null, error: string}
     */
    private function error(string $message): array
    {
        return [
            'status' => 'error',
            'providerMessageId' => null,
            'error' => $message,
        ];
    }

    private function resolveConversation(string $conversationId): ?Conversation
    {
        if (! Str::isUuid($conversationId)) {
            return null;
        }

        return Conversation::with(['contact', 'group', 'instance'])->find($conversationId);
    }

    /**
     * @return array{base64: string, mimetype: ?string, fileName: ?string}|null
     */
    private function resolveMedia(string $mediaId): ?array
    {
        if (! Str::isUuid($mediaId)) {
            return null;
        }

        $media = Media::find($mediaId);
        if ($media === null) {
            return null;
        }

        $buffer = app(MediaStorage::class)->get($media->storage_key);

        return [
            'base64' => base64_encode($buffer),
            'mimetype' => $media->mime_type,
            'fileName' => $media->filename,
        ];
    }
}
