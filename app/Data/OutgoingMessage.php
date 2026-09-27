<?php

namespace App\Data;

use App\Enums\MessageType;

final readonly class OutgoingMessage
{
    public function __construct(
        public string $conversationId,
        public MessageType $type = MessageType::Text,
        public ?string $content = null,
        public ?string $mediaUrl = null,
        public ?string $mediaBase64 = null,
        public ?string $fileName = null,
        public ?string $mimetype = null,
        public ?string $quotedMsgId = null,
    ) {}
}
