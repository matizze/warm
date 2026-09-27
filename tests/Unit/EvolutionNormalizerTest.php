<?php

namespace Tests\Unit;

use App\Enums\ConversationType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Integrations\Evolution\EvolutionNormalizer;
use Tests\TestCase;

class EvolutionNormalizerTest extends TestCase
{
    private EvolutionNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new EvolutionNormalizer;
    }

    public function test_normalizes_group_message_using_participant_as_sender(): void
    {
        $incoming = $this->normalizer->normalize([
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '123456789@g.us', 'id' => 'GRP-1'],
                'participant' => '5551999999999@s.whatsapp.net',
                'message' => ['conversation' => 'ola grupo'],
                'messageTimestamp' => 1758000000,
            ],
        ]);

        $this->assertNotNull($incoming);
        $this->assertSame(ConversationType::Group, $incoming->conversationType);
        $this->assertSame('5551999999999@s.whatsapp.net', $incoming->senderContactId);
        $this->assertSame(MessageDirection::Inbound, $incoming->direction);
        $this->assertSame(MessageType::Text, $incoming->type);
        $this->assertSame('ola grupo', $incoming->content);
    }

    public function test_normalizes_outbound_message(): void
    {
        $incoming = $this->normalizer->normalize([
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5511988888888@s.whatsapp.net', 'fromMe' => true, 'id' => 'OUT-1'],
                'message' => ['conversation' => 'enviei'],
                'messageTimestamp' => 1758000000,
            ],
        ]);

        $this->assertNotNull($incoming);
        $this->assertSame(MessageDirection::Outbound, $incoming->direction);
        $this->assertSame(ConversationType::Contact, $incoming->conversationType);
    }

    public function test_detects_media_type_and_extracts_media_url(): void
    {
        $incoming = $this->normalizer->normalize([
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5511988888888@s.whatsapp.net', 'id' => 'IMG-1'],
                'message' => [
                    'imageMessage' => ['url' => 'https://cdn/x.jpg', 'mimetype' => 'image/jpeg', 'caption' => 'foto'],
                ],
                'messageTimestamp' => 1758000000,
            ],
        ]);

        $this->assertNotNull($incoming);
        $this->assertSame(MessageType::Image, $incoming->type);
        $this->assertSame('foto', $incoming->content);
        $this->assertSame('https://cdn/x.jpg', $incoming->mediaUrl);
        $this->assertSame('image/jpeg', $incoming->mediaType);
    }

    public function test_maps_document_with_caption_and_sticker_types(): void
    {
        $document = $this->normalizer->normalize([
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5511988888888@s.whatsapp.net', 'id' => 'DOC-1'],
                'message' => ['documentWithCaptionMessage' => ['message' => ['documentMessage' => ['caption' => 'contrato']]]],
                'messageTimestamp' => 1758000000,
            ],
        ]);

        $this->assertNotNull($document);
        $this->assertSame(MessageType::Document, $document->type);

        $sticker = $this->normalizer->normalize([
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5511988888888@s.whatsapp.net', 'id' => 'STK-1'],
                'message' => ['stickerMessage' => ['url' => 'https://cdn/s.webp']],
                'messageTimestamp' => 1758000000,
            ],
        ]);

        $this->assertNotNull($sticker);
        $this->assertSame(MessageType::Sticker, $sticker->type);
        $this->assertTrue($sticker->type->isMedia());
        $this->assertFalse($sticker->type->needsMediaEndpoint());
    }

    public function test_normalize_status_play_and_error(): void
    {
        $played = $this->normalizer->normalizeStatus([
            'event' => 'messages.update',
            'data' => ['keyId' => 'M-1', 'status' => 'PLAYED'],
        ]);

        $this->assertNotNull($played);
        $this->assertSame('M-1', $played->providerMessageId);
        $this->assertSame(MessageStatus::Read, $played->status);

        $failed = $this->normalizer->normalizeStatus([
            'event' => 'messages.update',
            'data' => ['keyId' => 'M-2', 'status' => 'ERROR'],
        ]);

        $this->assertNotNull($failed);
        $this->assertSame(MessageStatus::Failed, $failed->status);
    }

    public function test_ignores_unknown_event_and_unknown_type(): void
    {
        $this->assertNull($this->normalizer->normalize(['event' => 'connection.update', 'data' => []]));
        $this->assertNull($this->normalizer->normalize([
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5511988888888@s.whatsapp.net', 'id' => 'UNK-1'],
                'message' => ['pollCreationMessage' => []],
                'messageTimestamp' => 1758000000,
            ],
        ]));
    }

    public function test_defaults_timestamp_when_missing(): void
    {
        $incoming = $this->normalizer->normalize([
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => '5511988888888@s.whatsapp.net', 'id' => 'TS-1'],
                'message' => ['conversation' => 'sem timestamp'],
            ],
        ]);

        $this->assertNotNull($incoming);
        $this->assertLessThanOrEqual(now(), $incoming->timestamp);
    }
}
