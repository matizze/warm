import "dotenv/config";
import express from "express";
import config from "./config/index";
import { QueryService } from "./domain/services/query-service";
import { WhatsAppService } from "./domain/services/whatsapp-service";
import { createWhatsAppService } from "./adapters/providers/factory";
import { createStorage } from "./infrastructure/storage/factory";
import { createProcessingService } from "./infrastructure/processing/factory";
import { OpenAIEmbedding } from "./infrastructure/embeddings/openai-embedding";
import { MediaService } from "./application/media/media-service";
import { SearchService } from "./application/search/search-service";
import { initDatabase } from "./infrastructure/persistence/database";
import { createWebhookRouter } from "./webhook/handler";
import { createAdminRouter } from "./admin/router";

async function main(): Promise<void> {
  await initDatabase();

  const queryService = new QueryService();
  const whatsappService: WhatsAppService = createWhatsAppService();
  const storage = createStorage();
  await storage.ensureBucket();
  const processing = config.processingEnabled ? createProcessingService() : undefined;
  const mediaService = new MediaService(queryService, whatsappService, storage, processing);
  const embeddings = new OpenAIEmbedding();
  const searchService = new SearchService(queryService, embeddings);

  const app = express();
  app.use(express.json({ limit: "25mb" }));

  app.get("/health", (_req, res) => {
    res.json({ status: "ok" });
  });

  app.use("/", createWebhookRouter(queryService, mediaService, searchService));
  app.use("/", createAdminRouter(queryService));

  app.listen(config.port, () => {
    console.log(`WARM webhook server running on port ${config.port}`);
    console.log(`Provider: ${config.provider}`);
    console.log(`Storage driver: ${config.storageDriver} (bucket: ${config.s3Bucket})`);
    console.log(`Processing: ${processing ? "enabled" : "disabled"}`);
    console.log(`Embeddings: ${embeddings.enabled ? config.embeddingModel : "disabled"}`);
  });
}

main().catch((err) => {
  console.error("Failed to start WARM:", err);
  process.exit(1);
});
