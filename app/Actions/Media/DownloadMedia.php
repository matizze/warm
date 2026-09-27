<?php

namespace App\Actions\Media;

use App\Actions\Messages\IndexMessage;
use App\Contracts\WhatsAppProvider;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Media;
use App\Models\Message;
use App\Support\MediaStorage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Concerns\AsJob;
use RuntimeException;

final class DownloadMedia
{
    use AsAction;
    use AsJob;

    /**
     * Downloads the provider media for a message, stores it and processes it.
     *
     * @return array<string, mixed>
     */
    public function handle(string $messageId): array
    {
        if (! Str::isUuid($messageId)) {
            throw new RuntimeException("Message not found: {$messageId}");
        }

        $message = Message::find($messageId);
        if ($message === null) {
            throw new RuntimeException("Message not found: {$messageId}");
        }

        if (! $message->provider_message_id) {
            throw new RuntimeException("Message {$messageId} has no provider message id");
        }

        $remoteJid = $message->metadata['remoteJid'] ?? $this->resolveRemoteJid($message->conversation_id);
        $fromMe = $message->direction === MessageDirection::Outbound;

        $provider = app(WhatsAppProvider::class)->getMedia($message->provider_message_id, $remoteJid, $fromMe);

        $buffer = base64_decode($provider->base64, true);
        if ($buffer === false) {
            throw new RuntimeException("Invalid base64 media for message {$messageId}");
        }

        $filename = $provider->fileName ?: "{$message->provider_message_id}.bin";
        $mimeType = $provider->mimetype ?: 'application/octet-stream';
        $storageKey = "media/{$messageId}/{$filename}";

        app(MediaStorage::class)->put($storageKey, $buffer, $mimeType);

        $media = Media::create([
            'message_id' => $messageId,
            'type' => $message->type->value,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($buffer),
            'storage_key' => $storageKey,
            'extracted_text' => null,
            'metadata' => $provider->caption ? ['caption' => $provider->caption] : [],
        ]);

        $media = ProcessMedia::run($media, $buffer);

        // Index the message once its media text is available.
        IndexMessage::dispatch($messageId);

        return $this->result($media);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Media $media): array
    {
        return [
            'mediaId' => $media->id,
            'storageKey' => $media->storage_key,
            'mimeType' => $media->mime_type,
            'sizeBytes' => $media->size_bytes,
            'filename' => $media->filename,
            'extractedText' => $media->extracted_text,
            'processing' => $media->metadata['processing'] ?? null,
            'signedUrl' => app(MediaStorage::class)->getSignedUrl($media->storage_key),
        ];
    }

    private function resolveRemoteJid(string $conversationId): string
    {
        $conversation = Conversation::find($conversationId);

        $jid = $conversation?->contact?->phone_number
            ?? $conversation?->group?->provider_group_id;

        if (! $jid) {
            throw new RuntimeException("Could not resolve remoteJid for conversation {$conversationId}");
        }

        return $jid;
    }
}
