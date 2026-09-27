# WARM — Laravel 12 port (in `laravel/`)

Status: working port, validated live. The Laravel app is now at the REPO ROOT
(`/Users/henriquecosta/Code/matizze/warm`); the old TypeScript app was deleted.
Git history: commit 7842a44 = snapshot of TS + laravel/ port; commit df3b102 = promote
laravel/ to root and remove TypeScript (all TS files gone; only Laravel remains).
Goal (user): reimplement the TS project in Laravel 12 with `lorisleiva/laravel-actions`
as the ONLY application library. `league/flysystem-aws-s3-v3` allowed only as the S3 disk driver.
NOTE: root `package.json` + `vite.config.js` + `resources/*` are the Laravel skeleton frontend
stubs (not the TS app); they are unused and can be removed (pending user decision).
Dockerfile/docker-compose were deleted for now (infra to be recreated later).

## Environment decisions
- Laravel Framework 12.69.2, PHP 8.4 (Herd), Composer 2.
- DB = the repo's Docker `pgvector/pgvector:pg16` on **port 5433** (Herd Postgres 18 not installed;
  brew postgresql@14 lacks pgvector). Databases: `warm_laravel`, `warm_laravel_test` (user warm/warm_pass).
- Storage = **RustFS** from Laravel Herd (service id B0CC6018-3A04-4267-8972-553BB8C1BAE0, ports 9000/9001).
  Credentials: access `herd`, secret `secretkey`. Bucket `warm-media`. Served via `mc`.
- `pdftotext` (poppler) at /opt/homebrew/bin for PDF extraction.
- SQLite is NOT usable: migrations depend on pgvector.

## Layout
- Models: Contact, Group, Conversation, Message, Media (HasUuids). No mappers (Eloquent).
  Message has `UPDATED_AT = null`, hidden `embedding`, scope `search`.
- Enums: MessageDirection/Status/Type, ConversationType, ProcessingStatus, SearchMode.
- Contract: App\Contracts\WhatsAppProvider (send/edit/sticker/react/delete/reply/getMedia).
- Data DTOs: IncomingMessage, OutgoingMessage, SendResult, ProviderMedia, StatusUpdate.
- Integrations: Evolution\EvolutionNormalizer, Evolution\EvolutionProvider, Integrations\Normalizer (dispatcher).
- Processing: PdfProcessor (pdftotext via Symfony Process), AudioProcessor (Whisper), ImageProcessor (vision), ProcessingService.
- Embeddings: EmbeddingService (+ toVectorLiteral).
- Support: MediaStorage (wraps disk `warm`).
- Mcp: ToolRegistry (9 tools) + McpServer (JSON-RPC 2.0 over stdio) + `mcp:serve`.
- Actions: Webhook/ReceiveWebhook (AsController), Messages/{PersistIncomingMessage, ApplyStatusUpdate,
  SearchMessages, SendMessage, IndexMessage(AsJob)}, Media/{DownloadMedia(AsJob), UploadMedia, ProcessMedia},
   Search/{ReindexMessages(AsCommand `warm:reindex`), AdminReindex}, Contacts/*, Groups/*, Conversations/*,
   System/HealthCheck.

## Key implementation notes
- Commands from AsCommand are NOT auto-registered: `Actions::registerCommands()` in AppServiceProvider::boot()
  (console only).
- Idempotency uses `DB::table('messages')->insertOrIgnore(...)` (ON CONFLICT DO NOTHING) — transaction-safe;
  a try/catch on unique violation poisons a surrounding transaction (found via tests).
- Routes: `routes/api.php` registered in bootstrap/app.php with `apiPrefix: ''` → POST /webhook, GET /health,
  POST /admin/reindex. Middleware aliases `webhook.auth`/`admin.auth`.
- `AsJob` pushes a `Lorisleiva\Actions\Decorators\JobDecorator`; assert via `$job->decorates(IndexMessage::class)`.
- `Message::travel` search modes with RRF k=60, DEFAULT_MIN_SCORE=0.25, graceful lexical fallback when
  embeddings disabled/no key.
- Invalid UUIDs are guarded (`Str::isUuid`) before DB queries (Postgres otherwise throws 22P02).
- README.md in laravel/ has the full TS→Laravel mapping table.

## Verified live
- `php artisan migrate` (vector(1536), HNSW + GIN FTS indexes).
- Webhook: 401 no token, ignored unknown event, inbound persist + contact auto-create, duplicate, status update.
- Queue: IndexMessage processed; search_text written.
- MCP: initialize/tools/list/tools/call; contact.search, conversation.search, message.search (hybrid→lexical, score), unknown tool error.
- media.upload → RustFS object + presigned URL fetchable.
- `php artisan test` 7 passed; `./vendor/bin/pint` clean; route:cache + config:cache OK.

## Validated live (hosted Evolution)
- Inbound instance = "Matizze Suporte" (number 5527928346619); sender for tests = "henriquecosta".
- End-to-end OK: text inbound (persist + search_text + embedding), hybrid/semantic search
  across text/image/PDF by paraphrase, image (vision description + OCR), PDF (pdftotext),
  audio (Whisper transcription), outbound text via MCP, outbound media via MCP
  (media.upload -> message.send with mediaId), status sent->delivered, duplicate=true, auth 401.
- MCP is stdio (`php artisan mcp:serve`) — NO tunnel needed. Claude Code/opencode config added in
  `.mcp.json` and `opencode.json` ("warm": php artisan mcp:serve). The cloudflared tunnel is ONLY
  for the Evolution webhook to reach `/webhook`.
- `warm:reindex --force` verified (scanned/text/embeddings counted).

## Pendências
- `media.download` + `message.send` need a live Evolution instance (EVOLUTION_URL).
- Rich media processors (audio/image) need OPENAI_API_KEY; video not implemented.
- Z-API / WhatsMeow adapters not added.
- Next step requested by user: move `laravel/` to repo root and delete the old TS files.
