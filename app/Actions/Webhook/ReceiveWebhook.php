<?php

namespace App\Actions\Webhook;

use App\Actions\Conversations\TouchConversation;
use App\Actions\Media\DownloadMedia;
use App\Actions\Messages\ApplyStatusUpdate;
use App\Actions\Messages\IndexMessage;
use App\Actions\Messages\PersistIncomingMessage;
use App\Contracts\MessageNormalizer;
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
        $normalizer = app(MessageNormalizer::class);

        $status = $normalizer->normalizeStatus($payload);
        if ($status !== null) {
            try {
                ApplyStatusUpdate::run($status->providerMessageId, $status->status);

                return response()->json(['status' => 'ok', 'update' => $status->status->value]);
            } catch (Throwable $e) {
                Log::error('Status update error: '.$e->getMessage());

                return response()->json(['status' => 'error'], 500);
            }
        }

        try {
            $incoming = $normalizer->normalize($payload);
        } catch (Throwable $e) {
            Log::warning('Ignoring unsupported webhook payload: '.$e->getMessage(), [
                'event' => $payload['event'] ?? null,
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

                if ($incoming->type->isMedia()) {
                    DownloadMedia::dispatch($message->id);
                } else {
                    IndexMessage::dispatch($message->id);
                }

                return $message;
            });
        } catch (Throwable $e) {
            Log::error('Webhook error: '.$e->getMessage());

            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => 'ok', 'duplicate' => $message === null]);
    }
}
