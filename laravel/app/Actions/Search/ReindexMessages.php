<?php

namespace App\Actions\Search;

use App\Actions\Messages\IndexMessage;
use App\Embeddings\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Concerns\AsCommand;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Throwable;

final class ReindexMessages
{
    use AsAction;
    use AsCommand;

    public string $commandSignature = 'warm:reindex
        {--force : Re-embed every message, even already-indexed ones}
        {--batch=100 : Messages per batch}
        {--dry-run : Count what would be done, no writes or API calls}';

    public string $commandDescription = 'Backfill/refresh message search_text and embeddings (pgvector)';

    /**
     * @param  array{force?: bool, batch?: int, dryRun?: bool}  $options
     * @return array{scanned: int, indexed: int, skipped: int, failed: int, resized: bool, seconds: float}
     */
    public function handle(array $options = []): array
    {
        $force = (bool) ($options['force'] ?? false);
        $batchSize = (int) ($options['batch'] ?? 100);
        $dryRun = (bool) ($options['dryRun'] ?? false);

        if (! app(EmbeddingService::class)->enabled()) {
            throw new RuntimeException('Embeddings disabled: set OPENAI_API_KEY and EMBEDDING_ENABLED=true');
        }

        $start = microtime(true);
        $targetDimensions = (int) config('warm.embeddings.dimensions');
        $currentDimensions = $this->currentEmbeddingDimension();
        $resized = false;

        if ($currentDimensions !== null && $currentDimensions !== $targetDimensions) {
            if (! $dryRun) {
                $this->resizeEmbeddingColumn($targetDimensions);
            }
            $resized = true;
            $force = true;
        }

        $scanned = $indexed = $skipped = $failed = 0;
        $after = null;

        while (true) {
            $rows = $this->batch($after, $batchSize);
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $scanned++;
                $after = ['createdAt' => $row->created_at, 'id' => $row->id];

                if (! $force && $row->has_embedding && $row->has_search_text) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $indexed++;

                    continue;
                }

                try {
                    IndexMessage::run($row->id) ? $indexed++ : $skipped++;
                } catch (Throwable $e) {
                    $failed++;
                    logger()->warning("Reindex failed for {$row->id}: {$e->getMessage()}");
                }
            }

            if (count($rows) < $batchSize) {
                break;
            }
        }

        return [
            'scanned' => $scanned,
            'indexed' => $indexed,
            'skipped' => $skipped,
            'failed' => $failed,
            'resized' => $resized,
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
            'Reindex done in %ss — scanned=%d indexed=%d skipped=%d failed=%d%s',
            $summary['seconds'],
            $summary['scanned'],
            $summary['indexed'],
            $summary['skipped'],
            $summary['failed'],
            $summary['resized'] ? ' (column resized)' : '',
        ));

        return Command::SUCCESS;
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

    private function resizeEmbeddingColumn(int $dimensions): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_messages_embedding');
        DB::statement('UPDATE messages SET embedding = NULL WHERE embedding IS NOT NULL');
        DB::statement("ALTER TABLE messages ALTER COLUMN embedding TYPE vector({$dimensions})");
        DB::statement('CREATE INDEX idx_messages_embedding ON messages USING hnsw (embedding vector_cosine_ops)');
    }
}
