<?php

namespace App\Data;

use App\Enums\ConversationType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use DateTimeImmutable;

/**
 * Provider-agnostic inbound contract. Produced by a normalizer and consumed
 * by the application; it never exposes a provider-specific payload.
 *
 * The provider conversation id (e.g. a WhatsApp JID) is opaque to the domain:
 * `conversationType` tells whether it maps to a contact or a group.
 */
final readonly class IncomingMessage
{
    public function __construct(
        public string $providerId,
        public string $providerConversationId,
        public ConversationType $conversationType,
        public string $senderContactId,
        public ?string $senderName,
        public MessageDirection $direction,
        public MessageType $type,
        public ?string $content,
        public DateTimeImmutable $timestamp,
        public ?string $mediaUrl = null,
        public ?string $mediaType = null,
        public array $metadata = [],
    ) {}
}
