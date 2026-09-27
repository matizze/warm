# WARM — Media Storage (S3) Implementation

Implements the `storage_key` + `media.download` / `media.upload` design from the README.

## Architecture
- **Port**: `domain/services/storage-service.ts` → `StorageService` (put/get/getSignedUrl/delete/ensureBucket)
- **Adapters**:
  - `infrastructure/storage/s3-storage.ts` → `S3Storage` (AWS SDK v3, works with AWS S3 AND MinIO)
  - `infrastructure/storage/local-storage.ts` → `LocalStorage` (filesystem, dev fallback)
  - `infrastructure/storage/factory.ts` → picks by `STORAGE_DRIVER` (default `s3`)
- **Orchestration**: `application/media/media-service.ts` → `MediaService(query, whatsapp, storage)`
  - `download(messageId)` → provider.getMedia → Buffer → storage.put → media row → signedUrl
  - `upload({type, base64, filename, mimeType, messageId?})` → storage.put → media row (messageId nullable)
  - `resolveForSend(mediaId)` → storage.get → base64 (for sending)

## Provider media download (Evolution)
`POST /chat/getBase64FromMediaMessage/{instance}` body `{ message: { key: { id, remoteJid, fromMe } } }`
returns `{ mediaType, fileName, caption, size, mimetype, base64 }`.
Added `getMedia(providerMessageId, remoteJid, fromMe)` to the `WhatsAppService` port + Evolution adapter.
remoteJid resolved from `message.metadata.remoteJid`, fallback to contact.phoneNumber via conversation.

## Sending media
`OutgoingMessage` now has `mediaBase64`, `fileName`, `mimetype`. Evolution `sendMedia` uses
`media: mediaBase64 || mediaUrl`. `message.send{mediaId}` reads from storage → base64 → provider.
(NOTE: presigned URL is NOT used for sending because remote providers can't reach localhost/MinIO.)

## Schema change (IMPORTANT)
`media.message_id` is now **nullable** (uploads happen before a message exists).
For pre-existing DBs run: `ALTER TABLE media ALTER COLUMN message_id DROP NOT NULL;`

## Row mapping fix (IMPORTANT bug found + fixed)
Postgres returns snake_case columns; entities are camelCase. `rows as Message` casts were wrong
(providerMessageId etc. were undefined). Added `infrastructure/persistence/mappers.ts`
(mapContact/mapGroup/mapConversation/mapMessage/mapMedia) and used them in ALL QueryService reads.
MCP outputs are now camelCase.

## Inbound auto-download
`createWebhookRouter(queryService, mediaService?)` — after persisting a media-type inbound message
(image/audio/video/document/sticker) it calls `mediaService.download(message.id)` best-effort
(errors are logged, never break the webhook).

## Config / env
- `STORAGE_DRIVER=s3|local` (default s3), `STORAGE_LOCAL_PATH` (local dev dir, e.g. ./.warm-media)
- `S3_ENDPOINT` (omit for real AWS), `S3_REGION`, `S3_BUCKET`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`,
  `S3_FORCE_PATH_STYLE` (true for MinIO), `MEDIA_SIGNED_URL_TTL`
- dotenv: entrypoints now `import "dotenv/config"` — DO NOT use `export $(cat .env | xargs)`
  (it splits values with spaces, e.g. instance "Matizze Suporte" became "Matizze").

## docker-compose
Added `minio` service (ports 9000/9001) + `miniodata` volume; warm uses S3_ENDPOINT=http://minio:9000.
LIMITATION: this sandbox's registry cannot pull `minio/minio` (access denied) nor quay.
So live testing here used STORAGE_DRIVER=local. In a normal environment MinIO works; for prod use AWS S3.

## Verified end-to-end (live, local driver)
- inbound image auto-downloaded → .warm-media/media/<msgId>/<providerId>.png + media row
- MCP media.download → {mediaId, storageKey, mimeType, sizeBytes, signedUrl}
- MCP media.upload (base64) → mediaId
- MCP message.send{mediaId} → resolved from storage → Evolution send → {status:PENDING, providerMessageId}
- `pnpm typecheck` clean; `docker compose build warm` OK

## Still TODO
- confirm S3Storage against a real MinIO/AWS (code path not exercised here)
- media metadata: width/height/duration not extracted
- transcription / image description
- presigned URLs exposed to MCP are local path in `local` driver (fine for dev)
