<?php

namespace App\Actions\Search;

use App\Actions\Messages\IndexMessage;
use App\Contracts\Embedder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

final class ReindexMessages
{
    use AsAction;

    public string $commandSignature = 'warm:reindex
        {--force : Reindex every message, even already-indexed ones}
        {--batch=100 : Messages per batch}
        {--dry-run : Count what would be done, no writes or API calls}';

    public string $commandDescription = 'Backfill/refresh message search_text and embeddings (pgvector)';

    /**
     * @param  array{force?: bool, batch?: int, dryRun?: bool}  $options
     * @return array{scanned: int, textIndexed: int, embeddingIndexed: int, skipped: int, failed: int, seconds: float}
     */
    public function handle(array $options = []): array
    {
        $force = (bool) ($options['force'] ?? false);
        $batchSize = (int) ($options['batch'] ?? 100);
        $dryRun = (bool) ($options['dryRun'] ?? false);

        // Lexical reindexing works without embeddings; only the vector column
        // is dimension-sensitive. Resizing it is an explicit schema change,
        // never a side effect of reindexing.
        $embeddingsEnabled = app(Embedder::class)->enabled();
        $start = microtime(true);

        if ($embeddingsEnabled) {
            $target = (int) config('warm.embeddings.dimensions');
            $current = $this->currentEmbeddingDimension();

            if ($current !== null && $current !== $target) {
                throw new RuntimeException(
                    "Dimensão de embedding incompatível: {$current} no banco, {$target} configurada. ".
                    'Ajuste o schema antes de reindexar.'
                );
            }
        }

        $scanned = $textIndexed = $embeddingIndexed = $skipped = $failed = 0;
        $after = null;

        while (true) {
            $rows = $this->batch($after, $batchSize);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $scanned++;
                $after = ['createdAt' => $row->created_at, 'id' => $row->id];

                $complete = $row->has_search_text && (! $embeddingsEnabled || $row->has_embedding);
                if (! $force && $complete) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $textIndexed++;

                    continue;
                }

                try {
                    $result = IndexMessage::run($row->id);

                    if (! $result['textIndexed']) {
                        $skipped++;

                        continue;
                    }

                    $textIndexed++;
                    if ($result['embeddingIndexed']) {
                        $embeddingIndexed++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    logger()->warning('Falha na reindexação', [
                        'message_id' => $row->id,
                        'exception' => $e::class,
                    ]);
                }
            }

            if (count($rows) < $batchSize) {
                break;
            }
        }

        return [
            'scanned' => $scanned,
            'textIndexed' => $textIndexed,
            'embeddingIndexed' => $embeddingIndexed,
            'skipped' => $skipped,
            'failed' => $failed,
            'seconds' => round(microtime(true) - $start, 2),
        ];
    }

    public function asCommand(Command $command): int
    {
        $command->info('Starting reindex...');

        try {
            $summary = $this->handle([
                'force' => (bool) $command->option('force'),
                'batch' => (int) $command->option('batch'),
                'dryRun' => (bool) $command->option('dry-run'),
            ]);
        } catch (Throwable $e) {
            $command->error($e->getMessage());

            return Command::FAILURE;
        }

        $command->info(sprintf(
            'Reindex done in %ss — scanned=%d text=%d embeddings=%d skipped=%d failed=%d',
            $summary['seconds'],
            $summary['scanned'],
            $summary['textIndexed'],
            $summary['embeddingIndexed'],
            $summary['skipped'],
            $summary['failed'],
        ));

        return $summary['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array{createdAt: string, id: string}|null  $after
     * @return list<object>
     */
    private function batch(?array $after, int $limit): array
    {
        if ($after === null) {
            return DB::select(
                'SELECT id, created_at, (embedding IS NOT NULL) AS has_embedding, (search_text IS NOT NULL) AS has_search_text
                 FROM messages ORDER BY created_at, id LIMIT ?',
                [$limit],
            );
        }

        return DB::select(
            'SELECT id, created_at, (embedding IS NOT NULL) AS has_embedding, (search_text IS NOT NULL) AS has_search_text
             FROM messages WHERE (created_at, id) > (?, ?) ORDER BY created_at, id LIMIT ?',
            [$after['createdAt'], $after['id'], $limit],
        );
    }

    private function currentEmbeddingDimension(): ?int
    {
        $row = DB::selectOne(
            "SELECT format_type(atttypid, atttypmod) AS coltype
             FROM pg_attribute
             WHERE attrelid = 'messages'::regclass AND attname = 'embedding' AND attnum > 0 AND NOT attisdropped"
        );

        if ($row === null || ! preg_match('/vector\((\d+)\)/', (string) $row->coltype, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
