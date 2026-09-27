import { Pool } from "pg";
import config from "../../config/index";

let pool: Pool | null = null;

export function getDatabase(): Pool {
  if (!pool) {
    pool = new Pool({ connectionString: config.databaseUrl });
  }
  return pool;
}

export async function initDatabase(): Promise<void> {
  const db = getDatabase();
  const client = await db.connect();
  try {
    await client.query(`CREATE EXTENSION IF NOT EXISTS vector;`);
    await client.query(`
      CREATE TABLE IF NOT EXISTS contacts (
        id UUID PRIMARY KEY, phone_number VARCHAR NOT NULL, name VARCHAR, push_name VARCHAR,
        observations TEXT, created_at TIMESTAMP NOT NULL DEFAULT NOW(), updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
        UNIQUE(phone_number)
      );
      CREATE TABLE IF NOT EXISTS groups (
        id UUID PRIMARY KEY, provider_group_id VARCHAR NOT NULL, name VARCHAR, description TEXT,
        observations TEXT, created_at TIMESTAMP NOT NULL DEFAULT NOW(), updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
        UNIQUE(provider_group_id)
      );
      CREATE TABLE IF NOT EXISTS conversations (
        id UUID PRIMARY KEY, type VARCHAR NOT NULL, contact_id UUID REFERENCES contacts(id),
        group_id UUID REFERENCES groups(id), created_at TIMESTAMP NOT NULL DEFAULT NOW(),
        updated_at TIMESTAMP NOT NULL DEFAULT NOW(), last_message_at TIMESTAMP
      );
      CREATE TABLE IF NOT EXISTS messages (
        id UUID PRIMARY KEY, conversation_id UUID NOT NULL REFERENCES conversations(id),
        provider_message_id VARCHAR UNIQUE, direction VARCHAR NOT NULL, type VARCHAR NOT NULL,
        sender_contact_id UUID REFERENCES contacts(id), content TEXT, metadata JSONB,
        status VARCHAR NOT NULL DEFAULT 'pending', sent_at TIMESTAMP, delivered_at TIMESTAMP,
        read_at TIMESTAMP, created_at TIMESTAMP NOT NULL DEFAULT NOW()
      );
      CREATE TABLE IF NOT EXISTS media (
        id UUID PRIMARY KEY, message_id UUID REFERENCES messages(id), type VARCHAR NOT NULL,
        filename VARCHAR, mime_type VARCHAR, size_bytes BIGINT, storage_key VARCHAR NOT NULL,
        extracted_text TEXT, metadata JSONB, created_at TIMESTAMP NOT NULL DEFAULT NOW()
      );
      CREATE INDEX IF NOT EXISTS idx_messages_conversation ON messages(conversation_id, created_at);
      CREATE INDEX IF NOT EXISTS idx_messages_provider_id ON messages(provider_message_id);
      CREATE INDEX IF NOT EXISTS idx_messages_sender ON messages(sender_contact_id);
    `);

    await client.query(`ALTER TABLE messages ADD COLUMN IF NOT EXISTS search_text TEXT;`);
    await client.query(`ALTER TABLE messages ADD COLUMN IF NOT EXISTS embedding vector(${config.embeddingDimensions});`);
    await client.query(
      `CREATE INDEX IF NOT EXISTS idx_messages_embedding ON messages USING hnsw (embedding vector_cosine_ops);`
    );
    await client.query(
      `CREATE INDEX IF NOT EXISTS idx_messages_search_text ON messages USING gin (to_tsvector('portuguese', coalesce(search_text, '')));`
    );
  } finally {
    client.release();
  }
}
