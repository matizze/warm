<?php

namespace App\Data;

final readonly class ProviderMedia
{
    public function __construct(
        public string $base64,
        public ?string $mimetype = null,
        public ?string $fileName = null,
        public ?string $caption = null,
    ) {}
}
