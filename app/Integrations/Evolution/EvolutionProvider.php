<?php

namespace App\Integrations\Evolution;

use App\Contracts\WhatsAppProvider;
use App\Data\OutgoingMessage;
use App\Data\ProviderMedia;
use App\Data\SendResult;
use App\Enums\MessageStatus;
use App\Exceptions\ProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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
        if ($message->type->needsMediaEndpoint()) {
            $result = $this->request('POST', "/message/sendMedia/{$this->instance}", [
                'number' => $message->recipient,
                'mediatype' => $message->type->value,
                'media' => $message->mediaBase64 ?? $message->mediaUrl,
                'mimetype' => $message->mimetype,
                'fileName' => $message->fileName,
                'caption' => $message->content,
            ]);

            return $this->toResult($result);
        }

        $result = $this->request('POST', "/message/sendText/{$this->instance}", [
            'number' => $message->recipient,
            'text' => $message->content,
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
            throw new ProviderException('O provedor não retornou a mídia solicitada.');
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
            $body = mb_substr((string) $response->body(), 0, 500);

            throw new ProviderException(
                "Falha na comunicação com o provedor ({$method} {$path}): HTTP {$response->status()} — {$body}"
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
        $rawStatus = is_string($result['status'] ?? null) ? $result['status'] : null;
        $status = MessageStatus::fromProvider($rawStatus);

        return new SendResult(
            status: $status->value,
            providerMessageId: $result['key']['id'] ?? null,
            providerStatus: $rawStatus,
            error: $status === MessageStatus::Failed ? $this->providerError($result) : null,
        );
    }

    /**
     * Provider failures can arrive as a 2xx payload with `status: ERROR`; keep
     * the provider's own reason so `message.send` can return it verbatim.
     *
     * @param  array<string, mixed>  $result
     */
    private function providerError(array $result): string
    {
        foreach (['message', 'error', 'response'] as $key) {
            $reason = $result[$key] ?? null;
            if (is_string($reason) && trim($reason) !== '') {
                return mb_substr(trim($reason), 0, 500);
            }
        }

        return 'O provedor recusou a mensagem.';
    }
}
