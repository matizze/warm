<?php

namespace App\Http\Controllers;

use App\Actions\Media\UploadMedia;
use App\Support\Media\MediaType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Multipart upload for files the agent already has locally, the path that
 * avoids base64 inside a tool call. Authentication and instance resolution
 * happen in middleware (`mcp.auth` + `instance.resolve`), exactly like the MCP
 * endpoint; the upload itself reuses UploadMedia.
 */
final class UploadMediaController
{
    public function __invoke(Request $request): JsonResponse
    {
        $maxRequest = (int) config('warm.media.max_request_bytes', 0);
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);

        if ($maxRequest > 0 && $contentLength > $maxRequest) {
            return response()->json([
                'error' => 'Corpo da requisição acima do limite de '.MediaType::humanSize($maxRequest).'.',
            ], 413);
        }

        try {
            $validated = $request->validate([
                'file' => ['required', 'file', 'max:'.max(1, intdiv($maxRequest, 1024))],
                'type' => ['sometimes', 'nullable', 'string', 'in:image,audio,video,document'],
                'filename' => ['sometimes', 'nullable', 'string', 'max:255'],
                'mimeType' => ['sometimes', 'nullable', 'string', 'max:255'],
                'messageId' => ['sometimes', 'nullable', 'uuid'],
            ], [
                'file.required' => 'Envie o arquivo no campo file.',
                'file.file' => 'Arquivo inválido.',
                'file.max' => 'Arquivo acima do limite de upload.',
                'type.in' => 'Tipo de mídia inválido: use image, audio, video ou document.',
                'messageId.uuid' => 'messageId inválido.',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->validator->errors()->first()], 422);
        }

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            return response()->json(['error' => 'Envie o arquivo no campo file.'], 422);
        }

        try {
            $type = $validated['type'] ?? $this->inferType($file->getMimeType());

            $result = UploadMedia::run([
                'type' => $type,
                'contents' => (string) $file->get(),
                'filename' => $validated['filename'] ?? $file->getClientOriginalName(),
                'mimeType' => $validated['mimeType'] ?? null,
                'messageId' => $validated['messageId'] ?? null,
            ]);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($result, 201);
    }

    private function inferType(?string $mimeType): string
    {
        return MediaType::categoryForMime((string) $mimeType) ?? 'document';
    }
}
