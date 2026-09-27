<?php

namespace App\Actions\Messages;

use App\Embeddings\EmbeddingService;
use App\Models\Media;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class IndexMessage
{
    use AsAction;

    public int $jobTimeout = 300;

    /**
     * Builds the searchable text and (when embeddings are available) the
     * vector for a message. The return value makes each part explicit so
     * callers like the reindex command can report partial indexing honestly.
     *
     * @return array{textIndexed: bool, embeddingIndexed: bool}
     */
    public function handle(string $messageId): array
    {
        $miss = ['textIndexed' => false, 'embeddingIndexed' => false];

        if (! Str::isUuid($messageId)) {
            return $miss;
        }

        $message = Message::find($messageId);
        if ($message === null) {
            return $miss;
        }

        $media = Media::where('message_id', $messageId)->latest('created_at')->first();

        $searchText = trim(implode("\n", array_filter([
            $message->content,
            $media?->extracted_text,
        ], fn ($part) => is_string($part) && trim($part) !== '')));

        if ($searchText === '') {
            return $miss;
        }

        $embedding = null;
        $embeddings = app(EmbeddingService::class);

        if ($embeddings->enabled()) {
            try {
                $embedding = $embeddings->embed($searchText);
            } catch (Throwable $e) {
                logger()->warning('Falha ao gerar embedding', [
                    'message_id' => $messageId,
                    'exception' => $e::class,
                ]);
            }
        }

        if ($embedding !== null) {
            DB::update(
                'UPDATE messages SET search_text = ?, embedding = ?::vector WHERE id = ?',
                [$searchText, EmbeddingService::toVectorLiteral($embedding), $messageId],
            );
        } else {
            DB::update('UPDATE messages SET search_text = ? WHERE id = ?', [$searchText, $messageId]);
        }

        return ['textIndexed' => true, 'embeddingIndexed' => $embedding !== null];
    }
}
