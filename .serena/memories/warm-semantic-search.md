# WARM — Semantic / Hybrid Search (pgvector)

Decision: use **pgvector** (Postgres extension) instead of a separate vector DB
(Qdrant/Weaviate/Pinecone), to preserve the WARM principle "1 install = 1 database".

## Infra
- docker-compose `db` image = `pgvector/pgvector:pg16` (was postgres:16-alpine).
  Existing data volume is compatible (same PG 16 major).
- `initDatabase` runs: `CREATE EXTENSION IF NOT EXISTS vector;` then
  `ALTER TABLE messages ADD COLUMN IF NOT EXISTS search_text TEXT;`
  `ALTER TABLE messages ADD COLUMN IF NOT EXISTS embedding vector(${config.embeddingDimensions});`
  `CREATE INDEX ... USING hnsw (embedding vector_cosine_ops);`  (HNSW, cosine)
  `CREATE INDEX ... USING gin (to_tsvector('portuguese', coalesce(search_text,'')));`  (FTS, not yet used in queries)

## Ports / adapters
- `domain/services/embedding-service.ts` → `EmbeddingService { enabled, embed(text) }`
- `infrastructure/embeddings/openai-embedding.ts` → `OpenAIEmbedding` via `/embeddings`
  (model `text-embedding-3-small`, `dimensions` param). Exports `toVectorLiteral()`.

## SearchService (application/search/search-service.ts)
- `indexMessage(messageId)` → builds searchable text = `message.content` + `media.extracted_text`,
  computes embedding, writes `messages.search_text` + `messages.embedding` via
  `QueryService.setMessageSearchData`.
- `searchMessages(filters, mode)` where mode = `lexical | semantic | hybrid` (default hybrid):
  - lexical → `QueryService.searchMessages` (ILIKE on content OR search_text)
  - semantic → embed query → `QueryService.searchMessagesSemantic` (`ORDER BY embedding <=> $vec`, returns `score`)
  - hybrid → both + RRF fusion (k=60). Falls back to lexical if embeddings disabled/error.

## QueryService additions
- `searchMessagesSemantic(embedding, filters)` → `SELECT m.*, 1-(m.embedding <=> $1::vector) AS score`.
- `setMessageSearchData(messageId, searchText, embedding?)` → writes vector as `[..]` with `$3::vector`.
- Lexical search now matches `content ILIKE OR search_text ILIKE` (search_text already includes media text,
  so the old LEFT JOIN media was removed).

## Indexing triggers (webhook/handler.ts)
- Text messages: after createMessage → `searchService.indexMessage(id)` (fire-and-forget).
- Media messages: after `mediaService.download(id)` resolves (extraction done) → `indexMessage(id)`.
- `createWebhookRouter(queryService, mediaService?, searchService?)`.

## MCP
`message.search` gained `mode: "lexical"|"semantic"|"hybrid"` (default hybrid); uses SearchService.
Results include a `score` for semantic/hybrid.

## Config / env
`EMBEDDING_ENABLED` (true), `EMBEDDING_MODEL` (text-embedding-3-small), `EMBEDDING_DIMENSIONS` (1536).
Requires `OPENAI_API_KEY`. If disabled/no key → semantic degrades gracefully to lexical.

## Verified live
Sent 3 texts (payment/boleto; contract/juridico; churrasco).
Query "questoes de dinheiro e advogados" (no shared words):
- lexical → 0 hits
- semantic → contract 0.408 > payment 0.383 > churrasco 0.241 (correct ordering)
- hybrid → 3 hits (RRF)
pgvector 0.8.6 active; HNSW + GIN indexes present; `pnpm typecheck` clean; `docker compose build warm` OK.

## Reindex / backfill command — `src/scripts/reindex.ts` (`pnpm reindex`)
Handles both backfill and embedding-model/dimension changes.
- Args: `--force` (re-embed everything), `--batch=N` (default 100), `--concurrency=N` (default 4),
  `--dry-run` (counts only, no writes/API calls).
- Logic:
  1. `initDatabase()`; fail if embeddings disabled (needs OPENAI_API_KEY + EMBEDDING_ENABLED).
  2. `query.getEmbeddingDimension()` (parses `format_type` → `vector(N)`).
     If != `config.embeddingDimensions`: `query.resizeEmbeddingColumn(N)`
     (DROP INDEX -> NULL out vectors -> ALTER COLUMN embedding TYPE vector(N) -> recreate HNSW),
     then forces full re-embed (dimension change invalidates old vectors).
  3. Keyset pagination over ALL messages via `query.listMessagesBatch(after, limit)`
     (`WHERE (created_at, id) > ($1,$2) ORDER BY created_at, id`, returns id/createdAt/hasEmbedding/hasSearchText).
  4. Per row: skip if `!force && hasEmbedding && hasSearchText`; else `search.indexMessage(id)`.
     Text-less rows are skipped harmlessly (re-checked next run, no API call).
  5. Concurrency pool + per-batch progress log + final summary.
- QueryService additions: `getEmbeddingDimension()`, `resizeEmbeddingColumn(dims)`, `listMessagesBatch()`.
- Tested live: incremental (20 scanned -> 15 indexed / 5 skipped); dimension 1536->512->1536
  (column resized, all rebuilt); semantic search still correct afterwards.

## Semantic result quality
`message.search` gained `minScore` (cosine floor). SearchService applies `DEFAULT_MIN_SCORE = 0.25`
for semantic/hybrid when not provided (18 -> ~4 relevant hits in testing).
`QueryService.searchMessagesSemantic` adds `AND 1 - (embedding <=> $1) >= $minScore`.

## Scheduling / triggering reindex (decision: cron, NOT an MCP tool)
Rationale: reindex is an ops/admin task (costly, long, model/config-driven), not a conversational
capability. README rule: don't add a tool if the operation is better served elsewhere.

Files:
- `src/scripts/reindex-core.ts` — `runReindex(options, context)` + `createReindexContext(query?)`.
  Reusable core (no arg parsing / no process.exit). Options: batch, concurrency, force, dryRun.
  Summary: { scanned, indexed, skipped, failed, resized, seconds }.
- `src/scripts/reindex.ts` — CLI (`pnpm reindex [--force] [--batch=] [--concurrency=] [--dry-run]`).
- `src/scripts/scheduler.ts` — `pnpm scheduler`; node-cron using `REINDEX_CRON` + `REINDEX_TZ`;
  single-flight guard (skips if previous run in progress); logs start/done.
- `src/admin/router.ts` — `GET/POST /admin/reindex` guarded by `ADMIN_TOKEN` (empty = 404 disabled).
  POST runs incremental in background (single-flight), returns 202; GET returns {running,last}.
- docker-compose: `scheduler` sidecar service (same image, `command: ["pnpm","scheduler"]`),
  env shared via YAML anchor `x-warm-env`. Services: db, minio, warm, scheduler.

QueryService: `listMessagesBatch(after, limit, onlyMissing)` — incremental pipeline filters
`(embedding IS NULL OR search_text IS NULL)` so a scheduled run only scans unfinished rows (cheap).

Env: `REINDEX_ENABLED` (true), `REINDEX_CRON` ("0 3 * * *"), `REINDEX_TZ` ("America/Sao_Paulo"),
`ADMIN_TOKEN` (empty disables the admin endpoint).

Host crontab equivalent (no Docker): `0 3 * * *  cd /path/to/warm && pnpm reindex >> /var/log/warm-reindex.log 2>&1`
(requires the process env / .env to be readable; prefer the scheduler service in Docker).

Verified: CLI incremental scans only missing (0s); scheduler fired on `*/20 * * * * *` twice;
admin POST 401 without token / 202 with token, GET shows last summary; `docker compose config` lists
db+minio+warm+scheduler; `pnpm typecheck` clean; `docker compose build warm` OK.

## Notes / TODO
- embeddings are generated per message; changing EMBEDDING_MODEL/DIMENSIONS requires re-embedding
  (different vector size = incompatible; would need a migration + backfill).
- FTS index exists but queries still use ILIKE; could switch lexical to websearch_to_tsquery for ranking.
- No re-index/backfill command yet (would need to iterate existing messages).
- Contacts/groups/media-text are not separately embedded (only the message-level search_text).
