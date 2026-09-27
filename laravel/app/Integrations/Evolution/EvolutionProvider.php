<?php

namespace App\Integrations\Evolution;

use App\Contracts\WhatsAppProvider;
use App\Data\OutgoingMessage;
use App\Data\ProviderMedia;
use App\Data\SendResult;
use App\Enums\MessageType;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class EvolutionProvider implements WhatsAppProvider
{
    private readonly string $baseUrl;

    private readonly string $apiKey;

    private readonly string $instance;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string) $config['url'], '/');
        $this->apiKey = (string) $config['api_key'];
        $this->instance = rawurlencode((string) $config['instance']);
    }

    public function sendMessage(OutgoingMessage $message): SendResult
    {
        if (in_array($message->type, [MessageType::Image, MessageType::Audio, MessageType::Video, MessageType::Document], true)) {
            $result = $this->request('POST', "/message/sendMedia/{$this->instance}", [
                'number' => $message->conversationId,
                'mediatype' => $message->type->value,
                'media' => $message->mediaBase64 ?? $message->mediaUrl,
                'mimetype' => $message->mimetype,
                'fileName' => $message->fileName,
                'caption' => $message->content,
            ]);

            return $this->toResult($result);
        }

        $result = $this->request('POST', "/message/sendText/{$this->instance}", [
            'number' => $message->conversationId,
            'text' => $message->content,
        ]);

        return $this->toResult($result);
    }

    public function editMessage(string $messageId, string $content): SendResult
    {
        $result = $this->request('POST', "/message/updateMessage/{$this->instance}", [
            'id' => $messageId,
            'text' => $content,
        ]);

        return $this->toResult($result);
    }

    public function sendSticker(string $stickerId, string $conversationId): SendResult
    {
        $result = $this->request('POST', "/message/sendSticker/{$this->instance}", [
            'number' => $conversationId,
            'sticker' => $stickerId,
        ]);

        return $this->toResult($result);
    }

    public function react(string $messageId, string $emoji): SendResult
    {
        $result = $this->request('POST', "/message/sendReaction/{$this->instance}", [
            'key' => ['id' => $messageId],
            'reaction' => $emoji,
        ]);

        return $this->toResult($result);
    }

    public function deleteMessage(string $messageId): SendResult
    {
        $result = $this->request('POST', "/chat/deleteMessageForEveryone/{$this->instance}", [
            'id' => $messageId,
        ]);

        return $this->toResult($result);
    }

    public function reply(string $messageId, string $content): SendResult
    {
        $result = $this->request('POST', "/message/sendText/{$this->instance}", [
            'number' => $messageId,
            'text' => $content,
        ]);

        return $this->toResult($result);
    }

    public function getMedia(string $providerMessageId, string $remoteJid, bool $fromMe): ProviderMedia
    {
        $response = $this->request('POST', "/chat/getBase64FromMediaMessage/{$this->instance}", [
            'message' => [
                'key' => [
                    'id' => $providerMessageId,
                    'remoteJid' => $remoteJid,
                    'fromMe' => $fromMe,
                ],
            ],
        ]);

        $base64 = $response['base64'] ?? null;
        if (! is_string($base64) || $base64 === '') {
            throw new RuntimeException("Evolution returned no media for message {$providerMessageId}");
        }

        return new ProviderMedia(
            base64: $base64,
            mimetype: $response['mimetype'] ?? null,
            fileName: $response['fileName'] ?? null,
            caption: $response['caption'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $response = $this->client()->send($method, "{$this->baseUrl}{$path}", [
            'json' => $body ?? [],
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Evolution request failed ({$response->status()}): {$response->body()}"
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : ['raw' => $response->body()];
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'Content-Type' => 'application/json',
            'apikey' => $this->apiKey,
        ])->timeout(30);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function toResult(array $result): SendResult
    {
        return new SendResult(
            status: (string) ($result['status'] ?? 'sent'),
            providerMessageId: $result['key']['id'] ?? null,
        );
    }
}
