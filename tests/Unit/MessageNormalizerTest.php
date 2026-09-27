<?php

namespace Tests\Unit;

use App\Contracts\MessageNormalizer;
use App\Data\StatusUpdate;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Integrations\Evolution\EvolutionNormalizer;
use RuntimeException;
use Tests\TestCase;

class MessageNormalizerTest extends TestCase
{
    public function test_container_binds_the_port_to_the_configured_adapter(): void
    {
        $this->assertInstanceOf(EvolutionNormalizer::class, app(MessageNormalizer::class));
    }

    public function test_returns_null_when_no_adapter_exists_for_the_configured_provider(): void
    {
        config(['warm.provider' => 'z-api']);

        $this->expectException(RuntimeException::class);

        app(MessageNormalizer::class);
    }

    public function test_normalizes_an_evolution_message_payload(): void
    {
        $message = app(MessageNormalizer::class)->normalize([
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'remoteJid' => '5527999999999@s.whatsapp.net',
                    'fromMe' => false,
                    'id' => 'MSG-1',
                ],
                'pushName' => 'Henrique',
                'message' => ['conversation' => 'contrato assinado'],
                'messageTimestamp' => 1758000000,
            ],
        ]);

        $this->assertNotNull($message);
        $this->assertSame('MSG-1', $message->providerId);
        $this->assertSame('contrato assinado', $message->content);
        $this->assertSame(MessageType::Text, $message->type);
    }

    public function test_normalizes_an_evolution_status_payload(): void
    {
        $status = app(MessageNormalizer::class)->normalizeStatus([
            'event' => 'messages.update',
            'data' => ['keyId' => 'MSG-1', 'status' => 'READ'],
        ]);

        $this->assertInstanceOf(StatusUpdate::class, $status);
        $this->assertSame('MSG-1', $status->providerMessageId);
        $this->assertSame(MessageStatus::Read, $status->status);
    }
}
