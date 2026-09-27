# WARM — Media Content Processing

Pipeline that turns stored media into searchable text.

## Port
`domain/services/media-processor.ts`:
- `MediaProcessor { name, supports(ctx), process(buffer, ctx) }`
- `ProcessedContent { kind: "extracted_text"|"transcription"|"description"|"ocr", text, metadata? }`
- `ProcessingServicePort { process(buffer, ctx) }`
- `ProcessingContext { type, mimeType, filename }`

## Orchestration
`application/media/processing-service.ts` → `ProcessingService(processors)` picks processors whose `supports()` matches.
`infrastructure/processing/factory.ts` → `createProcessingService()` registers: PdfProcessor, AudioProcessor, ImageProcessor.

### Processors (infrastructure/processing/)
- **pdf-processor.ts** — `pdf-parse` v2 (`new PDFParse({data})` → `getText()` → `.text`, then `destroy()`).
  supports: mime `application/pdf` or filename `.pdf`. kind = `extracted_text`.
- **audio-processor.ts** — OpenAI `/audio/transcriptions` (Whisper). Needs `OPENAI_API_KEY`.
  supports: type==="audio" or mime `audio/*`. kind = `transcription`.
- **image-processor.ts** — OpenAI `/chat/completions` with vision (`response_format: json_object`),
  prompt asks for `{description, text}` (OCR). supports: type==="image" or mime `image/*`. kind = `description`.

## Storage of results
`media.extracted_text` (TEXT, added via migration) + `media.metadata.processing` =
`{ status: done|failed|skipped, kinds:[...], details:[{kind, model|pages, processor}] , error? }`.
- Schema: `ALTER TABLE media ADD COLUMN IF NOT EXISTS extracted_text TEXT;` (already applied to dev DB)
- Media entity has `extractedText?`; mapper maps `extracted_text`.

## Integration
`MediaService(query, whatsapp, storage, processing?)` — after storing the buffer it runs
`processMedia()` (best-effort, errors captured into metadata.processing, never throws).
Both `download()` and `upload()` process. `MediaResult` now includes `extractedText` and `processing`.

## Search
`QueryService.searchMessages` LEFT JOINs `media md ON md.message_id = m.id` and matches
`m.content ILIKE $n OR md.extracted_text ILIKE $n` → searching a PDF/audio/image by its extracted
content returns the message. (Note: search uses ILIKE, not full-text; fine for MVP.)

## Config / env
`PROCESSING_ENABLED` (default true), `OPENAI_API_KEY`, `OPENAI_BASE_URL` (default api.openai.com/v1),
`TRANSCRIPTION_MODEL` (whisper-1), `VISION_MODEL` (gpt-4o-mini).
Entrypoints wire `createProcessingService()` when `config.processingEnabled`.

## Verified live (Evolution + local storage)
- PDF sent henriquecosta→Matizze: `extracted_text` = full contract text; `processing.status=done`
- search "Prestacao" / "1.234,56" → found the document message
- voice note → Whisper transcription stored (pt-BR)
- image → vision description stored ("Um robô cartoon ... acenando com a mão")
- `pnpm typecheck` clean; `docker compose build warm` OK

## Notes / TODO
- Video (transcribe audio track + frame description) not implemented.
- No full-text index (tsvector) yet; no OCR of scanned PDFs (text-based PDFs only).
- Processing is inline/awaited; for large files consider a queue/worker.
- OpenAI key used for testing was short-lived and only stored in local `.env`.
