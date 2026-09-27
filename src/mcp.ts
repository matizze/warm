import "dotenv/config";
import { QueryService } from "./domain/services/query-service";
import { WhatsAppService } from "./domain/services/whatsapp-service";
import { createWhatsAppService } from "./adapters/providers/factory";
import { createStorage } from "./infrastructure/storage/factory";
import { createProcessingService } from "./infrastructure/processing/factory";
import { OpenAIEmbedding } from "./infrastructure/embeddings/openai-embedding";
import { MediaService } from "./application/media/media-service";
import { SearchService } from "./application/search/search-service";
import { initDatabase } from "./infrastructure/persistence/database";
import { startMcpServer } from "./infrastructure/mcp/server";
import config from "./config/index";

async function main(): Promise<void> {
  await initDatabase();

  const queryService = new QueryService();
  const whatsappService: WhatsAppService = createWhatsAppService();
  const storage = createStorage();
  const processing = config.processingEnabled ? createProcessingService() : undefined;
  const mediaService = new MediaService(queryService, whatsappService, storage, processing);
  const embeddings = new OpenAIEmbedding();
  const searchService = new SearchService(queryService, embeddings);

  await startMcpServer(queryService, whatsappService, mediaService, searchService);
}

main().catch((err) => {
  console.error("Failed to start WARM MCP server:", err);
  process.exit(1);
});
