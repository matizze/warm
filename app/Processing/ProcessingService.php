<?php

namespace App\Processing;

use App\Contracts\MediaProcessor;
use RuntimeException;
use Throwable;

/**
 * Runs every processor whose supports() matches and aggregates the results.
 */
final class ProcessingService
{
    /**
     * @param  iterable<MediaProcessor>  $processors
     */
    public function __construct(private readonly iterable $processors) {}

    /**
     * @return list<ProcessedContent>
     */
    public function process(string $buffer, ProcessingContext $context): array
    {
        $results = [];

        foreach ($this->processors as $processor) {
            if (! $processor->supports($context)) {
                continue;
            }

            try {
                $result = $processor->process($buffer, $context);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "Processor [{$processor->name()}] falhou: {$e->getMessage()}",
                    previous: $e,
                );
            }

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }
}
