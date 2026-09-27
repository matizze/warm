<?php

namespace App\Data;

final readonly class SendResult
{
    public function __construct(
        public string $status,
        public ?string $providerMessageId = null,
    ) {}
}
