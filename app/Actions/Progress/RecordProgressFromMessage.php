<?php

namespace App\Actions\Progress;

use App\Enums\ClientChannelRole;
use App\Enums\MessageType;
use App\Enums\ProgressEntryStatus;
use App\Models\ClientChannel;
use App\Models\Media;
use App\Models\Message;
use App\Models\ProgressEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordProgressFromMessage
{
    use AsAction;

    /**
     * Turns a team audio into a progress entry.
     *
     * The conversation the audio arrived in is the convention that identifies
     * the client: only conversations declared as progress channels (see
     * ClientChannel) qualify. A progress channel without a client yields a
     * pending entry instead of silently dropping the audio.
     *
     * Idempotent by source message: reprocessing the same audio returns the
     * existing entry instead of creating a second one.
     */
    public function handle(string $messageId): ?ProgressEntry
    {
        if (! Str::isUuid($messageId)) {
            return null;
        }

        $message = Message::find($messageId);
        if ($message === null || $message->type !== MessageType::Audio) {
            return null;
        }

        $channel = ClientChannel::query()
            ->active()
            ->role(ClientChannelRole::Progress)
            ->where('conversation_id', $message->conversation_id)
            ->first();

        if ($channel === null) {
            return null;
        }

        $media = Media::where('message_id', $messageId)->latest('created_at')->first();
        $content = trim((string) ($media?->extracted_text ?? ''));
        $status = $channel->client_id === null
            ? ProgressEntryStatus::Pending
            : ProgressEntryStatus::Recorded;

        if ($status === ProgressEntryStatus::Pending) {
            Log::warning('Andamento sem cliente identificável', [
                'message_id' => $messageId,
                'conversation_id' => $message->conversation_id,
                'instance_id' => $message->instance_id,
            ]);
        }

        $id = (string) Str::uuid();

        $inserted = DB::table('progress_entries')->insertOrIgnore([
            'id' => $id,
            'client_id' => $channel->client_id,
            'conversation_id' => $message->conversation_id,
            'instance_id' => $message->instance_id,
            'source_message_id' => $message->id,
            'author_contact_id' => $message->sender_contact_id,
            'content' => $content !== '' ? $content : null,
            'status' => $status->value,
            'occurred_at' => $message->created_at,
            'metadata' => json_encode([
                'media_id' => $media?->id,
                'transcription' => $media?->metadata['processing'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return ProgressEntry::where('source_message_id', $message->id)->first();
        }

        return ProgressEntry::find($id);
    }
}
