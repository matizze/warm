<?php

namespace Tests\Unit;

use App\Enums\MessageStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MessageStatusTest extends TestCase
{
    /**
     * @return array<string, array{?string, MessageStatus}>
     */
    public static function providerStatuses(): array
    {
        return [
            'accepted' => ['ACCEPTED', MessageStatus::Accepted],
            'pending' => ['PENDING', MessageStatus::Accepted],
            'queued' => ['QUEUED', MessageStatus::Accepted],
            'sent' => ['SENT', MessageStatus::Sent],
            'server ack' => ['SERVER_ACK', MessageStatus::Sent],
            'delivered' => ['DELIVERED', MessageStatus::Delivered],
            'delivery ack' => ['DELIVERY_ACK', MessageStatus::Delivered],
            'read' => ['READ', MessageStatus::Read],
            'played' => ['PLAYED', MessageStatus::Read],
            'failed' => ['FAILED', MessageStatus::Failed],
            'error' => ['ERROR', MessageStatus::Failed],
            'canonical value' => ['delivered', MessageStatus::Delivered],
            'unknown' => ['SOMETHING_NEW', MessageStatus::Accepted],
            'missing' => [null, MessageStatus::Accepted],
        ];
    }

    #[DataProvider('providerStatuses')]
    public function test_maps_provider_status(?string $providerStatus, MessageStatus $expected): void
    {
        $this->assertSame($expected, MessageStatus::fromProvider($providerStatus));
    }
}
