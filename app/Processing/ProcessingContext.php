<?php

namespace App\Processing;

final readonly class ProcessingContext
{
    public function __construct(
        public string $type,
        public string $mimeType,
        public string $filename,
    ) {}
}
