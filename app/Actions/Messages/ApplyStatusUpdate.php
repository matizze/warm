<?php

namespace App\Actions\Messages;

use App\Enums\MessageStatus;
use App\Models\Message;
use Lorisleiva\Actions\Concerns\AsAction;

final class ApplyStatusUpdate
{
    use AsAction;

    public function handle(string $providerMessageId, MessageStatus $status): void
    {
        $message = Message::where('provider_message_id', $providerMessageId)->first();
        if ($message === null) {
            return;
        }

        $message->status = $status;

        if (! $message->sent_at && in_array($status, [MessageStatus::Sent, MessageStatus::Delivered, MessageStatus::Read], true)) {
            $message->sent_at = now();
        }

        if (! $message->delivered_at && in_array($status, [MessageStatus::Delivered, MessageStatus::Read], true)) {
            $message->delivered_at = now();
        }

        if (! $message->read_at && $status === MessageStatus::Read) {
            $message->read_at = now();
        }

        $message->save();
    }
}
