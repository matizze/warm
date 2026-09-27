const config = {
  get databaseUrl(): string {
    return process.env.DATABASE_URL || "postgresql://warm:warm_pass@localhost:5433/warm";
  },
  get provider(): string {
    return process.env.WHATSAPP_PROVIDER || "evolution";
  },
  get evolutionUrl(): string {
    return process.env.EVOLUTION_URL || "http://localhost:8080";
  },
  get evolutionApiKey(): string {
    return process.env.EVOLUTION_API_KEY || "";
  },
  get evolutionInstanceId(): string {
    return process.env.EVOLUTION_INSTANCE_ID || "instance1";
  },
  get webhookToken(): string {
    return process.env.WEBHOOK_TOKEN || "";
  },
  get port(): number {
    return parseInt(process.env.PORT || "3000", 10);
  },
  get s3Endpoint(): string | undefined {
    return process.env.S3_ENDPOINT || undefined;
  },
  get storageDriver(): string {
    return process.env.STORAGE_DRIVER || "s3";
  },
  get storageLocalPath(): string {
    return process.env.STORAGE_LOCAL_PATH || "./.warm-media";
  },
  get s3Region(): string {
    return process.env.S3_REGION || "us-east-1";
  },
  get s3Bucket(): string {
    return process.env.S3_BUCKET || "warm-media";
  },
  get s3AccessKey(): string {
    return process.env.S3_ACCESS_KEY || "";
  },
  get s3SecretKey(): string {
    return process.env.S3_SECRET_KEY || "";
  },
  get s3ForcePathStyle(): boolean {
    return (process.env.S3_FORCE_PATH_STYLE || "true") === "true";
  },
  get mediaSignedUrlTtl(): number {
    return parseInt(process.env.MEDIA_SIGNED_URL_TTL || "3600", 10);
  },
  get processingEnabled(): boolean {
    return (process.env.PROCESSING_ENABLED || "true") === "true";
  },
  get openaiApiKey(): string {
    return process.env.OPENAI_API_KEY || "";
  },
  get openaiBaseUrl(): string {
    return (process.env.OPENAI_BASE_URL || "https://api.openai.com/v1").replace(/\/$/, "");
  },
  get transcriptionModel(): string {
    return process.env.TRANSCRIPTION_MODEL || "whisper-1";
  },
  get visionModel(): string {
    return process.env.VISION_MODEL || "gpt-4o-mini";
  },
  get embeddingEnabled(): boolean {
    return (process.env.EMBEDDING_ENABLED || "true") === "true";
  },
  get embeddingModel(): string {
    return process.env.EMBEDDING_MODEL || "text-embedding-3-small";
  },
  get embeddingDimensions(): number {
    return parseInt(process.env.EMBEDDING_DIMENSIONS || "1536", 10);
  },
  get reindexEnabled(): boolean {
    return (process.env.REINDEX_ENABLED || "true") === "true";
  },
  get reindexCron(): string {
    return process.env.REINDEX_CRON || "0 3 * * *";
  },
  get reindexTz(): string {
    return process.env.REINDEX_TZ || "America/Sao_Paulo";
  },
  get adminToken(): string {
    return process.env.ADMIN_TOKEN || "";
  },
};

export default config;
