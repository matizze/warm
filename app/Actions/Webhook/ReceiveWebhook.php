<?php

namespace App\Actions\Webhook;

use App\Actions\Conversations\TouchConversation;
use App\Actions\Media\DownloadMedia;
use App\Actions\Messages\ApplyStatusUpdate;
use App\Actions\Messages\IndexMessage;
use App\Actions\Messages\PersistIncomingMessage;
use App\Contracts\Normalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class ReceiveWebhook
{
    use AsAction;

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        $normalizer = app(Normalizer::class);

        $status = $normalizer->normalizeStatus($payload);
        if ($status !== null) {
            try {
                ApplyStatusUpdate::run($status->providerMessageId, $status->status);

                return response()->json(['status' => 'ok', 'update' => $status->status->value]);
            } catch (Throwable $e) {
                Log::error('Status update failed', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'provider_message_id' => $status->providerMessageId,
                    'status' => $status->status->value,
                ]);

                return response()->json(['status' => 'error'], 500);
            }
        }

        try {
            $incoming = $normalizer->normalize($payload);
        } catch (Throwable $e) {
            Log::warning('Ignoring unsupported webhook payload', [
                'event' => $payload['event'] ?? null,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'ignored']);
        }

        if ($incoming === null) {
            return response()->json(['status' => 'ignored']);
        }

        try {
            $message = DB::transaction(function () use ($incoming) {
                $message = PersistIncomingMessage::run($incoming);

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
        } catch (Throwable $e) {
            Log::error('Webhook error', [
                'event' => $payload['event'] ?? null,
                'remote_jid' => $payload['data']['key']['remoteJid'] ?? null,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => 'ok', 'duplicate' => false]);
    }
}
