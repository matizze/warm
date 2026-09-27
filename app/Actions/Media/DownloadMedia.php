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
use RuntimeException;

final class DownloadMedia
{
    use AsAction;

    public int $jobTimeout = 300;

    /**
     * Downloads the provider media for a message, stores it and processes it.
     * The storage key is deterministic, so a retry reuses the stored file and
     * the processing already done instead of creating a new media row.
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

        $storageKey = "media/{$messageId}/original";

        // Deterministic key + unique index make the operation idempotent: a
        // retry reuses the stored file and avoids a second provider/AI call.
        $existing = Media::where('storage_key', $storageKey)->first();
        if ($existing !== null) {
            return $this->result($existing);
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

        app(MediaStorage::class)->put($storageKey, $buffer, $mimeType);

        $media = Media::updateOrCreate(
            ['storage_key' => $storageKey],
            [
                'message_id' => $messageId,
                'type' => $message->type->value,
                'filename' => $filename,
                'mime_type' => $mimeType,
                'size_bytes' => strlen($buffer),
                'extracted_text' => null,
                'metadata' => $provider->caption ? ['caption' => $provider->caption] : [],
            ],
        );

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
