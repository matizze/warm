<?php

namespace App\Integrations\Evolution;

use App\Contracts\Normalizer;
use App\Data\IncomingMessage;
use App\Data\StatusUpdate;
use App\Enums\ConversationType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use DateTimeImmutable;

/**
 * Converts Evolution webhook payloads into WARM's internal contracts.
 * This is the only place allowed to know Evolution's payload shape.
 */
final class EvolutionNormalizer implements Normalizer
{
    /** Message body key => WARM message type. */
    private const TYPE_MAP = [
        'conversation' => MessageType::Text,
        'extendedTextMessage' => MessageType::Text,
        'imageMessage' => MessageType::Image,
        'audioMessage' => MessageType::Audio,
        'videoMessage' => MessageType::Video,
        'documentMessage' => MessageType::Document,
        'documentWithCaptionMessage' => MessageType::Document,
        'stickerMessage' => MessageType::Sticker,
        'locationMessage' => MessageType::Location,
        'contactMessage' => MessageType::Contact,
        'contactsArrayMessage' => MessageType::Contact,
    ];

    private const SUPPORTED_EVENTS = ['messages.upsert', 'send.message'];

    private const STATUS_MAP = [
        'PENDING' => MessageStatus::Pending,
        'SERVER_ACK' => MessageStatus::Sent,
        'DELIVERY_ACK' => MessageStatus::Delivered,
        'READ' => MessageStatus::Read,
        'PLAYED' => MessageStatus::Read,
        'ERROR' => MessageStatus::Failed,
    ];

    public function normalize(array $payload): ?IncomingMessage
    {
        $event = strtolower((string) ($payload['event'] ?? ''));
        if ($event !== '' && ! in_array($event, self::SUPPORTED_EVENTS, true)) {
            return null;
        }

        $data = $payload['data'] ?? null;
        $key = is_array($data) ? ($data['key'] ?? null) : null;
        if (! is_array($data) || ! is_array($key)) {
            return null;
        }

        $remoteJid = $key['remoteJid'] ?? null;
        if (! is_string($remoteJid) || $remoteJid === '') {
            return null;
        }

        $message = is_array($data['message'] ?? null) ? $data['message'] : [];
        $type = $this->detectType($message);
        if ($type === null) {
            return null;
        }

        $isGroup = str_ends_with($remoteJid, '@g.us');
        $timestampSeconds = $data['messageTimestamp'] ?? null;
        $timestampMs = is_numeric($timestampSeconds) ? ((int) $timestampSeconds) * 1000 : (int) (microtime(true) * 1000);
        $providerId = $key['id'] ?? "{$remoteJid}-{$timestampMs}";
        $media = $this->extractMedia($message);

        return new IncomingMessage(
            providerId: (string) $providerId,
            providerConversationId: $remoteJid,
            conversationType: $isGroup ? ConversationType::Group : ConversationType::Contact,
            senderContactId: $isGroup
                ? (string) ($data['participant'] ?? $key['participant'] ?? $remoteJid)
                : $remoteJid,
            senderName: is_string($data['pushName'] ?? null) && $data['pushName'] !== ''
                ? $data['pushName']
                : null,
            direction: ($key['fromMe'] ?? false) ? MessageDirection::Outbound : MessageDirection::Inbound,
            type: $type,
            content: $this->extractText($message),
            timestamp: (new DateTimeImmutable)->setTimestamp(intdiv($timestampMs, 1000)),
            mediaUrl: $media['url'],
            mediaType: $media['mimetype'],
            metadata: [
                'isGroup' => $isGroup,
                'remoteJid' => $remoteJid,
                'messageType' => $data['messageType'] ?? null,
            ],
        );
    }

    public function normalizeStatus(array $payload): ?StatusUpdate
    {
        if (strtolower((string) ($payload['event'] ?? '')) !== 'messages.update') {
            return null;
        }

        $data = $payload['data'] ?? null;
        $keyId = is_array($data) ? ($data['keyId'] ?? null) : null;
        if (! is_string($keyId) || $keyId === '') {
            return null;
        }

        $status = self::STATUS_MAP[$data['status'] ?? ''] ?? MessageStatus::Sent;

        return new StatusUpdate($keyId, $status);
    }

    private function detectType(array $message): ?MessageType
    {
        foreach (array_keys($message) as $key) {
            if (isset(self::TYPE_MAP[$key])) {
                return self::TYPE_MAP[$key];
            }
        }

        return null;
    }

    private function extractText(array $message): ?string
    {
        if (isset($message['conversation']) && is_string($message['conversation'])) {
            return $message['conversation'];
        }

        foreach (['extendedTextMessage', 'imageMessage', 'videoMessage', 'documentMessage'] as $key) {
            $text = $message[$key]['text'] ?? $message[$key]['caption'] ?? null;
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @return array{url: ?string, mimetype: ?string}
     */
    private function extractMedia(array $message): array
    {
        foreach (['imageMessage', 'audioMessage', 'videoMessage', 'documentMessage', 'stickerMessage'] as $key) {
            $media = $message[$key] ?? null;
            if (is_array($media)) {
                return [
                    'url' => $media['url'] ?? null,
                    'mimetype' => $media['mimetype'] ?? null,
                ];
            }
        }

        return ['url' => null, 'mimetype' => null];
    }
}
