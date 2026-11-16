<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Accepted = 'accepted';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /**
     * Maps the provider status (raw or already canonical) to the delivery
     * states exposed by `message.send`. Unknown values stay `accepted`: a
     * message is never reported as delivered without an explicit provider ack.
     */
    public static function fromProvider(?string $status): self
    {
        return match (strtoupper(trim((string) $status))) {
            'ACCEPTED', 'PENDING', 'QUEUED', 'SUBMITTED' => self::Accepted,
            'SENT', 'SERVER_ACK' => self::Sent,
            'DELIVERED', 'DELIVERY_ACK' => self::Delivered,
            'READ', 'PLAYED' => self::Read,
            'FAILED', 'ERROR' => self::Failed,
            default => self::Accepted,
        };
    }

    /**
     * Delivery states that only exist after the provider confirms them.
     *
     * @return list<self>
     */
    public static function confirmed(): array
    {
        return [self::Sent, self::Delivered, self::Read];
    }
}
