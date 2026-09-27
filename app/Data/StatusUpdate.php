<?php

namespace App\Data;

use App\Enums\MessageStatus;

final readonly class StatusUpdate
{
    public function __construct(
        public string $providerMessageId,
        public MessageStatus $status,
    ) {}
}
