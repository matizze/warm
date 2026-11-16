<?php

namespace App\Actions\Media;

use App\Actions\Messages\IndexMessage;
use App\Models\Media;
use App\Support\MediaStorage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

final class ReprocessMedia
{
    use AsAction;

    private const PROCESSING_STATUSES = ['failed', 'skipped', 'done'];

    private const BATCH_SIZE = 100;

    public string $commandSignature = 'warm:media:reprocess
        {--type= : Only media of this type (audio, image, pdf, ...)}
        {--status= : Only media whose processing status is failed, skipped or done}
        {--from= : Only media created on or after this date (Y-m-d)}
        {--to= : Only media created on or before this date (Y-m-d)}
        {--messages= : Comma-separated message ids}
        {--limit= : Maximum number of media to reprocess}
        {--dry-run : Count what would be reprocessed, no reads, writes or API calls}';

    public string $commandDescription = 'Reprocess stored media (transcription, image description, PDF text) and reindex the messages';

    /**
     * Re-runs ProcessMedia over media already in storage, reading the buffer
     * from the deterministic storage_key, and reindexes the owning message.
     * Best-effort per item: one failure is recorded and does not abort the batch.
     *
     * @param  array{type?: ?string, status?: ?string, from?: ?string, to?: ?string, messages?: ?string, limit?: ?int, dryRun?: bool}  $options
     * @return array{scanned: int, reprocessed: int, done: int, failed: int, skipped: int, seconds: float}
     */
    public function handle(array $options = []): array
    {
        if (! config('warm.processing.enabled')) {
            throw new RuntimeException('Processamento desabilitado (PROCESSING_ENABLED=false): nada a reprocessar.');
        }

        $dryRun = (bool) ($options['dryRun'] ?? false);
        $limit = $this->limit($options['limit'] ?? null);
        $status = $options['status'] ?? null;
        $status = is_string($status) && $status !== '' ? $status : null;

        if ($status !== null && ! in_array($status, self::PROCESSING_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Status inválido: '.$status.'. Use failed, skipped ou done.'
            );
        }

        $start = microtime(true);
        $storage = app(MediaStorage::class);

        $scanned = $reprocessed = $done = $failed = $skipped = 0;

        $this->query($options, $status)->chunkById(self::BATCH_SIZE, function ($rows) use (
            &$scanned,
            &$reprocessed,
            &$done,
            &$failed,
            &$skipped,
            $storage,
            $dryRun,
            $limit,
        ) {
            foreach ($rows as $media) {
                if ($limit !== null && $scanned >= $limit) {
                    return false;
                }

                $scanned++;

                if ($dryRun) {
                    $reprocessed++;

                    continue;
                }

                try {
                    $buffer = $storage->get($media->storage_key);
                    $processed = ProcessMedia::run($media, $buffer);
                    $reprocessed++;

                    if ($media->message_id !== null) {
                        IndexMessage::run($media->message_id);
                    }

                    match ($processed->metadata['processing']['status'] ?? 'skipped') {
                        'done' => $done++,
                        'failed' => $failed++,
                        default => $skipped++,
                    };
                } catch (Throwable $e) {
                    $failed++;
                    logger()->warning('Falha ao reprocessar mídia', [
                        'media_id' => $media->id,
                        'storage_key' => $media->storage_key,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return true;
        });

        return [
            'scanned' => $scanned,
            'reprocessed' => $reprocessed,
            'done' => $done,
            'failed' => $failed,
            'skipped' => $skipped,
            'seconds' => round(microtime(true) - $start, 2),
        ];
    }

    public function asCommand(Command $command): int
    {
        $command->info('Reprocessando mídia armazenada...');

        try {
            $summary = $this->handle([
                'type' => $command->option('type'),
                'status' => $command->option('status'),
                'from' => $command->option('from'),
                'to' => $command->option('to'),
                'messages' => $command->option('messages'),
                'limit' => $command->option('limit'),
                'dryRun' => (bool) $command->option('dry-run'),
            ]);
        } catch (Throwable $e) {
            $command->error($e->getMessage());

            return Command::FAILURE;
        }

        $command->info(sprintf(
            'Reprocessamento concluído em %ss — scanned=%d reprocessed=%d done=%d failed=%d skipped=%d',
            $summary['seconds'],
            $summary['scanned'],
            $summary['reprocessed'],
            $summary['done'],
            $summary['failed'],
            $summary['skipped'],
        ));

        return $summary['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array{type?: ?string, status?: ?string, from?: ?string, to?: ?string, messages?: ?string, limit?: ?int, dryRun?: bool}  $options
     */
    private function query(array $options, ?string $status): Builder
    {
        $query = Media::query();

        $type = $options['type'] ?? null;
        if (is_string($type) && $type !== '') {
            $query->where('type', $type);
        }

        if ($status !== null) {
            $query->where('metadata->processing->status', $status);
        }

        $from = $options['from'] ?? null;
        if (is_string($from) && $from !== '') {
            $query->whereDate('created_at', '>=', $from);
        }

        $to = $options['to'] ?? null;
        if (is_string($to) && $to !== '') {
            $query->whereDate('created_at', '<=', $to);
        }

        $messageIds = $this->messageIds($options['messages'] ?? null);
        if ($messageIds !== []) {
            $query->whereIn('message_id', $messageIds);
        }

        return $query;
    }

    /**
     * @return list<string>
     */
    private function messageIds(mixed $messages): array
    {
        if (! is_string($messages) || trim($messages) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $messages)),
            fn (string $id): bool => $id !== '',
        ));
    }

    private function limit(mixed $limit): ?int
    {
        if ($limit === null || $limit === '') {
            return null;
        }

        $value = (int) $limit;
        if ($value < 1) {
            throw new InvalidArgumentException('Limit deve ser um inteiro maior que zero.');
        }

        return $value;
    }
}
