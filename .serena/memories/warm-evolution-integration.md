# WARM — Evolution v2.3.7 Integration (validated live)

Tested against hosted Evolution: `https://evo.matizze.com.br` (API base; `/manager` is the UI).

## Instances
- `Matizze Suporte` — main, number `5527928346619` (owner 5527928346619@s.whatsapp.net)
- `henriquecosta` — secondary, number `5527992225158` (owner 5527992225158@s.whatsapp.net)
- Instance name goes IN THE URL PATH and must be URL-encoded (has spaces): `Matizze%20Suporte`.
- The adapter does `encodeURIComponent(config.evolutionInstanceId)`.

## Evolution v2.3.7 webhook event names (lowercase, dot-separated)
- **`messages.upsert`** → inbound (and outbound from other devices)
- **`send.message`** → outbound echo when THIS instance sends
- **`messages.update`** → status change
- (Empty/unknown events are ignored gracefully → HTTP 200 `{status:"ignored"}`)

### messages.upsert / send.message payload (data)
`{ key: { remoteJid, fromMe, id }, pushName, message: {...}, messageType, messageTimestamp (SECONDS) }`
message body keys: `conversation`, `extendedTextMessage.text`, `imageMessage(.caption)`, `audioMessage`, `videoMessage(.caption)`, `documentMessage(.caption)`, `stickerMessage`, `locationMessage`, `contactMessage`. Media URL at `<type>Message.url`, mime at `.mimetype`.

### messages.update payload
`{ data: { keyId (provider message id), status, remoteJid, fromMe, messageId } }`
Status map: PENDING→pending, SERVER_ACK→sent, DELIVERY_ACK→delivered, READ→read, PLAYED→read, ERROR→failed.
Applied via `QueryService.applyStatusUpdate` (sets status + sent_at/delivered_at/read_at).
NOTE Postgres: param used both in assignment and IN() needs `$2::text` cast, else "inconsistent types deduced for parameter".

## Send endpoints used (auth: `apikey` header)
- text: `POST /message/sendText/{instance}` `{ number, text }`
- media: `POST /message/sendMedia/{instance}` `{ number, mediatype, mimetype, caption, media(base64), fileName }`
- voice note: `POST /message/sendWhatsAppAudio/{instance}` `{ number, audio(base64) }`
- sticker: `/message/sendSticker/{instance}`, reaction: `/message/sendReaction/{instance}`,
  edit: `/message/updateMessage/{instance}`, delete: `/chat/deleteMessageForEveryone/{instance}`
Send responses: `{ key:{id}, status:"PENDING", ... }` → mapped to `{status, providerMessageId}`.

## Other useful endpoints
- `GET /instance/connectionState/{instance}` → `{ instance:{ instanceName, state:"open" } }`
- `GET /instance/fetchInstances`
- `GET /webhook/find/{instance}` / `POST /webhook/set/{instance}` with `{ webhook:{ enabled, url, byEvents, base64, events:[...] } }`

## Webhook config set during testing (original was `null`)
url = `<cloudflare-tunnel>/webhook?token=change-me` (token passed via query param because Evolution
doesn't send custom headers); events = MESSAGES_UPSERT, MESSAGES_UPDATE, SEND_MESSAGE.

## Verified end-to-end (live)
- henriquecosta → Matizze: text, image, audio, document ALL normalized + persisted as inbound
- Matizze outbound echo persisted as outbound
- status transitions sent→delivered→read persisted with timestamps
- contact auto-created with name from pushName ("Henrique")
- MCP: message.search by type (image/audio), contact.search by name — all return data
- adapter `message.send` returns `{status:"PENDING", providerMessageId}`

## Concurrency note
Inbound handler + QueryService each open their own pool connection; the old manual BEGIN/COMMIT
in the webhook was removed. The `webhook/handler.ts` no longer manages transactions.

## Still TODO
- media download (store file; WhatsApp URLs are ephemeral) + `media` table persistence
- audio transcription / image description (README lists them; not built)
- groups ingestion (group JIDs `...@g.us` are detected but group entity not populated)
- Z-API / WhatsMeow adapters
- the tunnel URL is ephemeral — webhook must be repointed each session
