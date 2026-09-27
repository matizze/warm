<?php

namespace App\Data;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use DateTimeImmutable;

/**
 * Provider-agnostic inbound contract. Produced by a normalizer and consumed
 * by the application; it never exposes a provider-specific payload.
 */
final readonly class IncomingMessage
{
    public function __construct(
        public string $providerId,
        public string $conversationId,
        public string $senderContactId,
        public MessageDirection $direction,
        public MessageType $type,
        public ?string $content,
        public DateTimeImmutable $timestamp,
        public ?string $mediaUrl = null,
        public ?string $mediaType = null,
        public ?string $quotedMsgId = null,
        public array $metadata = [],
    ) {}
}
