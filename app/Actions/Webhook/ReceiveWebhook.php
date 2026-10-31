<?php

namespace App\Actions\Webhook;

use App\Actions\Attendance\AnswerCustomerQuestion;
use App\Actions\Conversations\TouchConversation;
use App\Actions\Media\DownloadMedia;
use App\Actions\Messages\ApplyStatusUpdate;
use App\Actions\Messages\IndexMessage;
use App\Actions\Messages\PersistIncomingMessage;
use App\Contracts\Normalizer;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

final class ReceiveWebhook
{
    use AsAction;

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();

        /** @var Instance|null $instance */
        $instance = $request->attributes->get('warm.instance') ?? Instance::default();

        // Context shared by every log line produced while handling this
        // payload, including exceptions reported by the central handler.
        Log::withContext([
            'event' => $payload['event'] ?? null,
            'remote_jid' => $payload['data']['key']['remoteJid'] ?? null,
            'instance_id' => $instance?->instance_id,
        ]);

        $normalizer = app(Normalizer::class);

        $status = $normalizer->normalizeStatus($payload);
        if ($status !== null) {
            Log::withContext([
                'provider_message_id' => $status->providerMessageId,
                'status' => $status->status->value,
            ]);

            ApplyStatusUpdate::run($status->providerMessageId, $status->status, $instance);

            return response()->json(['status' => 'ok', 'update' => $status->status->value]);
        }

        $incoming = $normalizer->normalize($payload);
        if ($incoming === null) {
            return response()->json(['status' => 'ignored']);
        }

        $message = DB::transaction(function () use ($incoming, $instance) {
            $message = PersistIncomingMessage::run($incoming, $instance);

            if ($message === null) {
                return null;
            }

            TouchConversation::run($message->conversation_id, $incoming->timestamp);

            return $message;
        });

        if ($message === null) {
            return response()->json(['status' => 'ok', 'duplicate' => true]);
        }

        // Dispatch only after the transaction committed so a failed commit
        // cannot leave jobs for a message that never persisted.
        if ($message->type->isMedia()) {
            DownloadMedia::dispatch($message->id);
        } else {
            IndexMessage::dispatch($message->id);
        }

        // Customer text questions go through the attendance flow. It is queued
        // so the webhook stays fast; the flow itself guards on the kill switch,
        // the configured scope and the confidence threshold.
        if (config('warm.attendance.enabled')
            && $message->direction === MessageDirection::Inbound
            && $message->type === MessageType::Text) {
            AnswerCustomerQuestion::dispatch($message->id);
        }

        return response()->json(['status' => 'ok', 'duplicate' => false]);
    }
}
