import config from "../config/index";
import { QueryService } from "../domain/services/query-service";
import { OpenAIEmbedding } from "../infrastructure/embeddings/openai-embedding";
import { SearchService } from "../application/search/search-service";

export interface ReindexOptions {
  batch?: number;
  concurrency?: number;
  force?: boolean;
  dryRun?: boolean;
}

export interface ReindexSummary {
  scanned: number;
  indexed: number;
  skipped: number;
  failed: number;
  resized: boolean;
  seconds: number;
}

async function runPool<T>(items: T[], concurrency: number, worker: (item: T) => Promise<void>): Promise<void> {
  const queue = [...items];
  const runners = Array.from({ length: Math.max(1, Math.min(concurrency, queue.length)) }, async () => {
    while (queue.length > 0) {
      const item = queue.shift() as T;
      await worker(item);
    }
  });
  await Promise.all(runners);
}

export function createReindexContext(query = new QueryService()): {
  query: QueryService;
  search: SearchService;
  embeddings: OpenAIEmbedding;
} {
  const embeddings = new OpenAIEmbedding();
  const search = new SearchService(query, embeddings);
  return { query, search, embeddings };
}

export async function runReindex(
  options: ReindexOptions = {},
  context = createReindexContext()
): Promise<ReindexSummary> {
  const batchSize = options.batch ?? 100;
  const concurrency = options.concurrency ?? 4;
  const dryRun = options.dryRun ?? false;

  const { query, search, embeddings } = context;
  const startedAt = Date.now();

  if (!embeddings.enabled) {
    throw new Error("Embeddings disabled. Set OPENAI_API_KEY and EMBEDDING_ENABLED=true.");
  }

  let force = options.force ?? false;
  let resized = false;

  const currentDimension = await query.getEmbeddingDimension();
  if (currentDimension !== config.embeddingDimensions) {
    if (dryRun) {
      return { scanned: 0, indexed: 0, skipped: 0, failed: 0, resized: true, seconds: 0 };
    }
    await query.resizeEmbeddingColumn(config.embeddingDimensions);
    resized = true;
    force = true;
  }

  let after: { createdAt: Date; id: string } | null = null;
  let scanned = 0;
  let indexed = 0;
  let skipped = 0;
  let failed = 0;

  for (;;) {
    const batch = await query.listMessagesBatch(after, batchSize, !force);
    if (batch.length === 0) {
      break;
    }

    await runPool(batch, concurrency, async (row) => {
      scanned += 1;
      if (!force && row.hasEmbedding && row.hasSearchText) {
        skipped += 1;
        return;
      }
      if (dryRun) {
        indexed += 1;
        return;
      }
      try {
        const ok = await search.indexMessage(row.id);
        if (ok) {
          indexed += 1;
        } else {
          skipped += 1;
        }
      } catch (error) {
        failed += 1;
        console.warn(`  reindex failed for ${row.id}: ${(error as Error).message}`);
      }
    });

    after = { createdAt: batch[batch.length - 1].createdAt, id: batch[batch.length - 1].id };
  }

  return {
    scanned,
    indexed,
    skipped,
    failed,
    resized,
    seconds: Number(((Date.now() - startedAt) / 1000).toFixed(1)),
  };
}
