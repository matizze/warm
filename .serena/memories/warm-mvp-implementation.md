# WARM MVP — Implementation Notes

Status: working MVP (TypeScript + pnpm + Docker + PostgreSQL, provider: Evolution).

## Stack & Tooling
- TypeScript, run with `tsx` (no compile step needed for runtime; `pnpm typecheck` = `tsc --noEmit`)
- Package manager: pnpm (lockfile committed). Node 20 in Docker (corepack enabled)
- PostgreSQL 16
- Files are ALL kebab-case (user requirement) — e.g. `query-service.ts`, `evolution-adapter.ts`
- Imports use relative paths (no tsconfig path aliases)
- `tsconfig.json`: module esnext / moduleResolution bundler (chosen so tsx resolves `.ts`)

## Two entrypoints (IMPORTANT)
- `src/index.ts` → HTTP webhook server (`pnpm start`), Express, port 3000
- `src/mcp.ts` → MCP server over stdio (`pnpm mcp`), spawned by the AI client
- They are intentionally separate processes.

## Directory Layout (src/)
- `domain/entities/` — contact, group, conversation, message, media (+ index.ts)
- `domain/services/whatsapp-service.ts` — outbound interface (Ports & Adapters port)
- `domain/services/query-service.ts` — read/write queries, owns all SQL (the only place touching pg)
- `adapters/providers/` — evolution-adapter.ts (send), evolution-normalize.ts (inbound), factory.ts, types.ts
- `application/normalize/` — `normalize(payload)` dispatches on `config.provider`
- `application/use-cases/` — thin wrappers (send-message, search-*, get-group)
- `infrastructure/persistence/database.ts` — pg Pool + schema bootstrap (`initDatabase`)
- `infrastructure/mcp/server.ts` — McpServer (SDK v1.30) with 9 tools
- `webhook/handler.ts` — POST /webhook: auth → normalize → findOrCreateContact → findOrCreateConversation → createMessage (+ touch)
- `config/index.ts` — env-based config (getters)

## Key Behaviors Implemented
- **Inbound (Evolution real payload)**: `messages.upsert` with `data.key.remoteJid/fromMe/id`, `data.message` (conversation, extendedTextMessage, imageMessage caption, audio/video/document/sticker/location/contact), `data.messageTimestamp` (seconds→ms).
- **Idempotency**: `messages.provider_message_id UNIQUE`; `createMessage` uses `ON CONFLICT (provider_message_id) DO NOTHING` and returns null on duplicate → webhook responds `{duplicate:true}`.
- **Webhook auth**: header `x-webhook-token` (or `?token=`) compared to `WEBHOOK_TOKEN`. Empty token = disabled.
- **Unsupported events**: normalize() throws → webhook returns 200 `{status:"ignored"}` (not an error).
- **Contact name**: populated from Evolution `pushName`; updated if changed (name search then works).
- **Conversation id** stored = remoteJid (e.g. `5511...@s.whatsapp.net` or `...@g.us`).

## MCP Tools (9)
message.send, contact.search, contact.get, group.search, group.get, message.search, conversation.search, media.download, media.upload.
(media.* are stubs for now.) Built with `McpServer` from `@modelcontextprotocol/sdk` (NOT deprecated `Server`); `server.tool(name, desc, zodShape, handler)`.

## Config / Env
- `DATABASE_URL` (default points to localhost:5433), `WHATSAPP_PROVIDER=evolution`,
  `EVOLUTION_URL`, `EVOLUTION_API_KEY`, `EVOLUTION_INSTANCE_ID`, `WEBHOOK_TOKEN`, `PORT`
- Evolution send endpoints used: `/message/sendText/{instance}`, `/message/sendMedia/{instance}`,
  `/message/sendSticker/{instance}`, `/message/sendReaction/{instance}`, `/message/updateMessage/{instance}`,
  `/chat/deleteMessageForEveryone/{instance}`; auth via `apikey` header.

## Docker
- `docker compose up --build -d` runs `warm` + `db`.
- **db host port is 5433** (5432 conflicts with a local Postgres on this machine).
- `.env` (gitignored) feeds compose substitution (EVOLUTION_*, WEBHOOK_TOKEN).
- warm service reaches host Evolution via `host.docker.internal`.

## Verified Working
- `pnpm typecheck` → clean (exit 0)
- Docker build + run; `/health` → ok
- Inbound Evolution text + image payloads persisted; duplicates rejected
- Webhook auth rejects missing token (401)
- MCP initialize + tools/list + tools/call (contact.search, message.search, conversation.search) return correct data

## Not done yet (future)
- Z-API / WhatsMeow adapters (factory only knows evolution)
- media download/upload real implementation
- groups ingestion, outbound status callbacks (delivered/read)
- tests (vitest configured but empty)
- MCP `message.send` end-to-end against a live Evolution instance (needs user's host)
