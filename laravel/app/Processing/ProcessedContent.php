<?php

namespace App\Processing;

final readonly class ProcessedContent
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $kind,
        public string $text,
        public array $metadata = [],
    ) {}
}
