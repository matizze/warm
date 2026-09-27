<?php

namespace App\Actions\Messages;

use App\Embeddings\EmbeddingService;
use App\Models\Media;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Concerns\AsJob;
use Throwable;

final class IndexMessage
{
    use AsAction;
    use AsJob;

    public function handle(string $messageId): bool
    {
        if (! Str::isUuid($messageId)) {
            return false;
        }

        $message = Message::find($messageId);
        if ($message === null) {
            return false;
        }

        $media = Media::where('message_id', $messageId)->latest('created_at')->first();

        $searchText = trim(implode("\n", array_filter([
            $message->content,
            $media?->extracted_text,
        ], fn ($part) => is_string($part) && trim($part) !== '')));

        if ($searchText === '') {
            return false;
        }

        $embedding = null;
        try {
            $embedding = app(EmbeddingService::class)->embed($searchText);
        } catch (Throwable $e) {
            logger()->warning("Embedding failed for message {$messageId}: {$e->getMessage()}");
        }

        if ($embedding !== null) {
            DB::update(
                'UPDATE messages SET search_text = ?, embedding = ?::vector WHERE id = ?',
                [$searchText, EmbeddingService::toVectorLiteral($embedding), $messageId],
            );
        } else {
            DB::update('UPDATE messages SET search_text = ? WHERE id = ?', [$searchText, $messageId]);
        }

        return true;
    }
}
