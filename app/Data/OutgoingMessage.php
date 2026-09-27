<?php

namespace App\Data;

use App\Enums\MessageType;

/**
 * Outbound contract. `recipient` is the opaque provider address (phone number
 * or group JID) resolved from the internal conversation.
 */
final readonly class OutgoingMessage
{
    public function __construct(
        public string $recipient,
        public MessageType $type = MessageType::Text,
        public ?string $content = null,
        public ?string $mediaUrl = null,
        public ?string $mediaBase64 = null,
        public ?string $fileName = null,
        public ?string $mimetype = null,
    ) {}
}
