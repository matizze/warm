<?php

namespace App\Actions\Messages;

use App\Data\OutgoingMessage;
use App\Enums\MessageType;
use App\Integrations\WhatsAppProviderResolver;
use App\Models\Conversation;
use App\Models\Instance;
use App\Models\Media;
use App\Support\MediaStorage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final class SendMessage
{
    use AsAction;

    /**
     * Expected failures (unknown conversation/media) are returned as a
     * `status: error` payload; a provider failure propagates as a typed
     * `ProviderException` that the MCP layer maps back to `status: error`.
     *
     * @param  array<string, mixed>  $input
     * @return array{status: string, providerMessageId: ?string, error?: string}
     */
    public function handle(array $input, ?Instance $contextInstance = null): array
    {
        $type = MessageType::tryFrom((string) ($input['type'] ?? 'text')) ?? MessageType::Text;

        $conversation = $this->resolveConversation((string) $input['conversationId']);
        if ($conversation === null) {
            return [
                'status' => 'error',
                'providerMessageId' => null,
                'error' => "conversation not found: {$input['conversationId']}",
            ];
        }

        // The MCP context scopes tool calls to one instance: a client of one
        // number must not be able to send through another number's conversation.
        if ($contextInstance !== null && $conversation->instance_id !== $contextInstance->instance_id) {
            return [
                'status' => 'error',
                'providerMessageId' => null,
                'error' => "conversation {$conversation->id} belongs to another instance",
            ];
        }

        $recipient = $conversation->resolveProviderRecipient();

        if ($recipient === null) {
            return [
                'status' => 'error',
                'providerMessageId' => null,
                'error' => "conversation not found: {$input['conversationId']}",
            ];
        }

        $mediaBase64 = null;
        $mimetype = null;
        $fileName = null;

        if (! empty($input['mediaId'])) {
            $resolved = $this->resolveMedia((string) $input['mediaId']);
            if ($resolved === null) {
                return [
                    'status' => 'error',
                    'providerMessageId' => null,
                    'error' => "media not found: {$input['mediaId']}",
                ];
            }

            $mediaBase64 = $resolved['base64'];
            $mimetype = $resolved['mimetype'];
            $fileName = $resolved['fileName'];
        }

        // Reply through the instance the conversation belongs to.
        $provider = app(WhatsAppProviderResolver::class)->forInstance($conversation->instance);

        $result = $provider->sendMessage(new OutgoingMessage(
            recipient: $recipient,
            type: $type,
            content: isset($input['content']) ? (string) $input['content'] : null,
            mediaBase64: $mediaBase64,
            fileName: $fileName,
            mimetype: $mimetype,
        ));

        return ['status' => $result->status, 'providerMessageId' => $result->providerMessageId];
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
