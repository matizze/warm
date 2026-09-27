<?php

namespace App\Actions\Messages;

use App\Contracts\WhatsAppProvider;
use App\Data\OutgoingMessage;
use App\Enums\MessageType;
use App\Models\Media;
use App\Support\MediaStorage;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final class SendMessage
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: string, providerMessageId: ?string}
     */
    public function handle(array $input): array
    {
        $type = MessageType::tryFrom((string) ($input['type'] ?? 'text')) ?? MessageType::Text;

        $mediaBase64 = null;
        $mimetype = null;
        $fileName = null;

        if (! empty($input['mediaId'])) {
            $resolved = $this->resolveForSend((string) $input['mediaId']);
            if ($resolved === null) {
                return ['status' => 'error', 'providerMessageId' => null, 'error' => "media not found: {$input['mediaId']}"];
            }
            $mediaBase64 = $resolved['base64'];
            $mimetype = $resolved['mimetype'];
            $fileName = $resolved['fileName'];
        }

        $result = app(WhatsAppProvider::class)->sendMessage(new OutgoingMessage(
            conversationId: (string) $input['conversationId'],
            type: $type,
            content: isset($input['content']) ? (string) $input['content'] : null,
            mediaBase64: $mediaBase64,
            fileName: $fileName,
            mimetype: $mimetype,
        ));

        return ['status' => $result->status, 'providerMessageId' => $result->providerMessageId];
    }

    /**
     * @return array{base64: string, mimetype: ?string, fileName: ?string}|null
     */
    private function resolveForSend(string $mediaId): ?array
    {
        if (! Str::isUuid($mediaId)) {
            return null;
        }

        $media = Media::find($mediaId);
        if ($media === null) {
            return null;
        }

        $buffer = app(MediaStorage::class)->get($media->storage_key);

        return [
            'base64' => base64_encode($buffer),
            'mimetype' => $media->mime_type,
            'fileName' => $media->filename,
        ];
    }
}
