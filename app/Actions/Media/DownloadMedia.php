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
use Throwable;

final class DownloadMedia
{
    use AsAction;

    public int $jobTimeout = 300;

    /**
     * Downloads the provider media for a message, stores it and processes it.
     * The storage key is deterministic, so a retry reuses the stored file and
     * the processing already done instead of creating a new media row.
     *
     * Failures propagate so the queue can retry the job; the final failure
     * state is recorded by the queue worker through jobFailed().
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

        $media = $this->download($message);

        $this->markDownloadDone($message);

        return $media->present();
    }

    /**
     * Invoked by the queue worker when the download job ultimately fails.
     * Records `metadata.download = {status, error, attempts}` on the message
     * so a silent provider failure leaves an actionable trace (selective retry
     * and monitoring) instead of just vanishing from search.
     */
    public function jobFailed(Throwable $e, string $messageId): void
    {
        $message = Message::find($messageId);
        if ($message === null) {
            return;
        }

        $this->markDownloadFailed($message, $e);
    }

    private function download(Message $message): Media
    {
        $messageId = $message->id;
        $storageKey = "media/{$messageId}/original";

        // Deterministic key + unique index make the operation idempotent: a
        // retry reuses the stored file and avoids a second provider/AI call.
        $existing = Media::where('storage_key', $storageKey)->first();
        if ($existing !== null) {
            return $existing;
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

        return $media;
    }

    private function markDownloadFailed(Message $message, Throwable $e): void
    {
        $metadata = $message->metadata ?? [];
        $previous = $metadata['download'] ?? [];

        $metadata['download'] = [
            'status' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 500),
            'attempts' => (int) ($previous['attempts'] ?? 0) + 1,
        ];

        $message->forceFill(['metadata' => $metadata])->save();
    }

    private function markDownloadDone(Message $message): void
    {
        $metadata = $message->metadata ?? [];
        $metadata['download'] = ['status' => 'done'];
        $message->forceFill(['metadata' => $metadata])->save();
    }

    private function resolveRemoteJid(string $conversationId): string
    {
        $recipient = Conversation::with(['contact', 'group'])
            ->find($conversationId)?->resolveProviderRecipient();

        if (! $recipient) {
            throw new RuntimeException("Could not resolve remoteJid for conversation {$conversationId}");
        }

        return $recipient;
    }
}
