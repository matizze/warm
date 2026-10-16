<?php

namespace App\Actions\Messages;

use App\Embeddings\EmbeddingService;
use App\Enums\SearchMode;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class SearchMessages
{
    use AsAction;

    private const DEFAULT_MIN_SCORE = 0.25;

    private const RRF_K = 60;

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function handle(array $filters, SearchMode $mode = SearchMode::Hybrid): array
    {
        $hasQuery = is_string($filters['search'] ?? null) && trim((string) $filters['search']) !== '';

        if (! $hasQuery || $mode === SearchMode::Lexical) {
            return $this->lexical($filters)->map->toArray()->all();
        }

        $embeddings = app(EmbeddingService::class);
        $vector = $embeddings->enabled() ? $this->tryEmbed($embeddings, (string) $filters['search']) : null;

        if ($mode === SearchMode::Semantic) {
            if ($vector === null) {
                return [];
            }

            $filters['minScore'] ??= self::DEFAULT_MIN_SCORE;

            return $this->semantic($vector, $filters);
        }

        // Hybrid: each branch caps only by limit so the fusion ranks the same
        // window; offset is applied once to the fused result, not in the
        // branches too (which paged twice and silently skipped results, H2).
        $window = $filters;
        unset($window['offset']);
        if ($vector !== null) {
            $window['minScore'] ??= self::DEFAULT_MIN_SCORE;
        }

        $lexical = $this->lexical($window)->map->toArray()->all();
        $semantic = $vector !== null ? $this->semantic($vector, $window) : [];

        return array_slice(
            $this->fuse($lexical, $semantic),
            (int) ($filters['offset'] ?? 0),
            (int) ($filters['limit'] ?? 50),
        );
    }

    /**
     * @return list<float>|null
     */
    private function tryEmbed(EmbeddingService $embeddings, string $search): ?array
    {
        return rescue(
            fn (): ?array => $embeddings->embed($search),
            function (Throwable $e): ?array {
                logger()->warning("Semantic search failed, falling back to lexical: {$e->getMessage()}");

                return null;
            },
            false,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Message>
     */
    private function baseQuery(array $filters): Builder
    {
        return Message::query()
            ->when($filters['instance_id'] ?? null, fn (Builder $q, $v) => $q->where('instance_id', $v))
            ->when($filters['conversationId'] ?? null, fn (Builder $q, $v) => $q->where('conversation_id', $v))
            ->when($filters['contactId'] ?? null, fn (Builder $q, $v) => $q->where('sender_contact_id', $v))
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('type', $v))
            ->when($filters['direction'] ?? null, fn (Builder $q, $v) => $q->where('direction', $v))
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->where('created_at', '<=', $v));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Message>
     */
    private function lexical(array $filters): Collection
    {
        $query = $this->baseQuery($filters);

        $term = $filters['search'] ?? null;
        if (is_string($term) && $term !== '') {
            $query->search($term);
        }

        return $query
            ->orderByDesc('created_at')
            ->limit((int) ($filters['limit'] ?? 50))
            ->offset((int) ($filters['offset'] ?? 0))
            ->get();
    }

    /**
     * @param  list<float|int>  $embedding
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function semantic(array $embedding, array $filters): array
    {
        $vector = EmbeddingService::toVectorLiteral($embedding);

        $rows = $this->baseQuery($filters)
            ->whereNotNull('embedding')
            ->select('messages.*')
            ->selectRaw('1 - (embedding <=> ?::vector) as score', [$vector])
            ->when(
                $filters['minScore'] ?? null,
                fn (Builder $q, $v) => $q->whereRaw('1 - (embedding <=> ?::vector) >= ?', [$vector, $v]),
            )
            ->orderByRaw('embedding <=> ?::vector', [$vector])
            ->limit((int) ($filters['limit'] ?? 50))
            ->offset((int) ($filters['offset'] ?? 0))
            ->get();

        return $rows->map(function (Message $message) {
            $data = $message->toArray();
            $data['score'] = (float) $message->getAttribute('score');

            return $data;
        })->all();
    }

    /**
     * Reciprocal Rank Fusion of lexical and semantic results.
     *
     * @param  list<array<string, mixed>>  $lexical
     * @param  list<array<string, mixed>>  $semantic
     * @return list<array<string, mixed>>
     */
    private function fuse(array $lexical, array $semantic): array
    {
        $merged = [];

        foreach ($lexical as $index => $message) {
            $merged[$message['id']] = array_replace($message, ['score' => 1 / (self::RRF_K + $index + 1)]);
        }

        foreach ($semantic as $index => $message) {
            $contribution = 1 / (self::RRF_K + $index + 1);
            if (isset($merged[$message['id']])) {
                $merged[$message['id']]['score'] += $contribution;
            } else {
                $merged[$message['id']] = array_replace($message, ['score' => $contribution]);
            }
        }

        $result = array_values($merged);
        usort($result, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        return $result;
    }
}
