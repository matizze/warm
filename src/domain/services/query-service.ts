import { v4 as uuidv4 } from "uuid";
import { getDatabase } from "../../infrastructure/persistence/database";
import { Contact, createContact } from "../entities/contact";
import { Group, createGroup } from "../entities/group";
import { Conversation, createConversation } from "../entities/conversation";
import { Message, createMessage } from "../entities/message";
import { Media, createMedia } from "../entities/media";
import { mapContact, mapGroup, mapConversation, mapMessage, mapMedia } from "../../infrastructure/persistence/mappers";

export class QueryService {
  private db = getDatabase();

  async searchMessages(filters: SearchMessagesFilters): Promise<Message[]> {
    const client = await this.db.connect();
    try {
      const { conversationId, contactId, type, direction, from, to, search, limit = 50, offset = 0 } = filters;
      let query = `SELECT m.* FROM messages m WHERE 1=1`;
      const params: unknown[] = [];
      let paramIndex = 1;

      if (conversationId) { query += ` AND m.conversation_id = $${paramIndex++}`; params.push(conversationId); }
      if (contactId) { query += ` AND m.sender_contact_id = $${paramIndex++}`; params.push(contactId); }
      if (type) { query += ` AND m.type = $${paramIndex++}`; params.push(type); }
      if (direction) { query += ` AND m.direction = $${paramIndex++}`; params.push(direction); }
      if (from) { query += ` AND m.created_at >= $${paramIndex++}`; params.push(from); }
      if (to) { query += ` AND m.created_at <= $${paramIndex++}`; params.push(to); }
      if (search) {
        query += ` AND (m.content ILIKE $${paramIndex} OR m.search_text ILIKE $${paramIndex})`;
        params.push(`%${search}%`);
        paramIndex++;
      }

      query += ` ORDER BY m.created_at DESC LIMIT $${paramIndex++} OFFSET $${paramIndex++}`;
      params.push(limit, offset);

      const result = await client.query(query, params);
      return result.rows.map(mapMessage);
    } finally {
      client.release();
    }
  }

  async searchMessagesSemantic(
    embedding: number[],
    filters: SearchMessagesFilters
  ): Promise<Array<Message & { score: number }>> {
    const client = await this.db.connect();
    try {
      const { conversationId, contactId, type, direction, from, to, limit = 50, minScore } = filters;
      const vec = `[${embedding.join(",")}]`;
      let query = `SELECT m.*, 1 - (m.embedding <=> $1::vector) AS score FROM messages m WHERE m.embedding IS NOT NULL`;
      const params: unknown[] = [vec];
      let paramIndex = 2;

      if (minScore !== undefined) {
        query += ` AND 1 - (m.embedding <=> $1::vector) >= $${paramIndex++}`;
        params.push(minScore);
      }
      if (conversationId) { query += ` AND m.conversation_id = $${paramIndex++}`; params.push(conversationId); }
      if (contactId) { query += ` AND m.sender_contact_id = $${paramIndex++}`; params.push(contactId); }
      if (type) { query += ` AND m.type = $${paramIndex++}`; params.push(type); }
      if (direction) { query += ` AND m.direction = $${paramIndex++}`; params.push(direction); }
      if (from) { query += ` AND m.created_at >= $${paramIndex++}`; params.push(from); }
      if (to) { query += ` AND m.created_at <= $${paramIndex++}`; params.push(to); }

      query += ` ORDER BY m.embedding <=> $1::vector LIMIT $${paramIndex++}`;
      params.push(limit);

      const result = await client.query(query, params);
      return result.rows.map((row) => ({ ...mapMessage(row), score: Number(row.score) }));
    } finally {
      client.release();
    }
  }

  async setMessageSearchData(messageId: string, searchText: string, embedding: number[] | null): Promise<void> {
    const client = await this.db.connect();
    try {
      if (embedding) {
        await client.query(`UPDATE messages SET search_text = $2, embedding = $3::vector WHERE id = $1`, [
          messageId,
          searchText,
          `[${embedding.join(",")}]`,
        ]);
      } else {
        await client.query(`UPDATE messages SET search_text = $2 WHERE id = $1`, [messageId, searchText]);
      }
    } finally {
      client.release();
    }
  }

  async searchConversations(filters: SearchConversationsFilters): Promise<Conversation[]> {
    const client = await this.db.connect();
    try {
      const { contactId, groupId, search, limit = 50, offset = 0 } = filters;
      let query = "SELECT * FROM conversations WHERE 1=1";
      const params: unknown[] = [];
      let paramIndex = 1;

      if (contactId) { query += ` AND contact_id = $${paramIndex++}`; params.push(contactId); }
      if (groupId) { query += ` AND group_id = $${paramIndex++}`; params.push(groupId); }
      if (search) { query += ` AND id = $${paramIndex++}`; params.push(search); }

      query += ` ORDER BY last_message_at DESC NULLS LAST LIMIT $${paramIndex++} OFFSET $${paramIndex++}`;
      params.push(limit, offset);

      const result = await client.query(query, params);
      return result.rows.map(mapConversation);
    } finally {
      client.release();
    }
  }

  async searchContacts(filters: SearchContactsFilters): Promise<Contact[]> {
    const client = await this.db.connect();
    try {
      const { name, phoneNumber, search, limit = 50, offset = 0 } = filters;
      let query = "SELECT * FROM contacts WHERE 1=1";
      const params: unknown[] = [];
      let paramIndex = 1;

      if (name) { query += ` AND name ILIKE $${paramIndex++}`; params.push(`%${name}%`); }
      if (phoneNumber) { query += ` AND phone_number = $${paramIndex++}`; params.push(phoneNumber); }
      if (search) { query += ` AND (name ILIKE $${paramIndex++} OR phone_number ILIKE $${paramIndex++})`; params.push(`%${search}%`, `%${search}%`); }

      query += ` ORDER BY name LIMIT $${paramIndex++} OFFSET $${paramIndex++}`;
      params.push(limit, offset);

      const result = await client.query(query, params);
      return result.rows.map(mapContact);
    } finally {
      client.release();
    }
  }

  async getContact(contactId: string): Promise<Contact | null> {
    const client = await this.db.connect();
    try {
      const result = await client.query("SELECT * FROM contacts WHERE id = $1", [contactId]);
      return result.rows[0] ? mapContact(result.rows[0]) : null;
    } finally {
      client.release();
    }
  }

  async getGroup(groupId: string): Promise<Group | null> {
    const client = await this.db.connect();
    try {
      const result = await client.query("SELECT * FROM groups WHERE id = $1", [groupId]);
      return result.rows[0] ? mapGroup(result.rows[0]) : null;
    } finally {
      client.release();
    }
  }

  async searchGroups(filters: SearchGroupsFilters): Promise<Group[]> {
    const client = await this.db.connect();
    try {
      const { name, search, limit = 50, offset = 0 } = filters;
      let query = "SELECT * FROM groups WHERE 1=1";
      const params: unknown[] = [];
      let paramIndex = 1;

      if (name) { query += ` AND name ILIKE $${paramIndex++}`; params.push(`%${name}%`); }
      if (search) { query += ` AND (name ILIKE $${paramIndex++} OR description ILIKE $${paramIndex++})`; params.push(`%${search}%`, `%${search}%`); }

      query += ` ORDER BY name LIMIT $${paramIndex++} OFFSET $${paramIndex++}`;
      params.push(limit, offset);

      const result = await client.query(query, params);
      return result.rows.map(mapGroup);
    } finally {
      client.release();
    }
  }

  async createContact(data: Omit<Contact, "id" | "createdAt" | "updatedAt">): Promise<Contact> {
    const contact = createContact(data);
    const client = await this.db.connect();
    try {
      await client.query(
        `INSERT INTO contacts (id, phone_number, name, push_name, observations, created_at, updated_at)
         VALUES ($1, $2, $3, $4, $5, $6, $7)
         ON CONFLICT (phone_number) DO UPDATE SET name = EXCLUDED.name, push_name = EXCLUDED.push_name, observations = EXCLUDED.observations, updated_at = EXCLUDED.updated_at`,
        [contact.id, contact.phoneNumber, contact.name, contact.pushName, contact.observations, contact.createdAt, contact.updatedAt]
      );
      return contact;
    } finally {
      client.release();
    }
  }

  async createGroup(data: Omit<Group, "id" | "createdAt" | "updatedAt">): Promise<Group> {
    const group = createGroup(data);
    const client = await this.db.connect();
    try {
      await client.query(
        `INSERT INTO groups (id, provider_group_id, name, description, observations, created_at, updated_at)
         VALUES ($1, $2, $3, $4, $5, $6, $7)
         ON CONFLICT (provider_group_id) DO UPDATE SET name = EXCLUDED.name, description = EXCLUDED.description, observations = EXCLUDED.observations, updated_at = EXCLUDED.updated_at`,
        [group.id, group.providerGroupId, group.name, group.description, group.observations, group.createdAt, group.updatedAt]
      );
      return group;
    } finally {
      client.release();
    }
  }

  async createConversation(data: Omit<Conversation, "id" | "createdAt" | "updatedAt">): Promise<Conversation> {
    const conversation = createConversation(data);
    const client = await this.db.connect();
    try {
      await client.query(
        `INSERT INTO conversations (id, type, contact_id, group_id, created_at, updated_at, last_message_at)
         VALUES ($1, $2, $3, $4, $5, $6, $7)`,
        [conversation.id, conversation.type, conversation.contactId, conversation.groupId, conversation.createdAt, conversation.updatedAt, conversation.lastMessageAt]
      );
      return conversation;
    } finally {
      client.release();
    }
  }

  async findOrCreateContact(phoneNumber: string, name?: string): Promise<Contact> {
    const existing = await this.searchContacts({ phoneNumber, limit: 1 });
    if (existing.length > 0) {
      const contact = existing[0];
      if (name && contact.name !== name) {
        const client = await this.db.connect();
        try {
          await client.query("UPDATE contacts SET name = $2, updated_at = NOW() WHERE id = $1", [contact.id, name]);
          contact.name = name;
        } finally {
          client.release();
        }
      }
      return contact;
    }
    return this.createContact({ phoneNumber, name, observations: "" });
  }

  async findOrCreateConversationByContact(contactId: string): Promise<Conversation> {
    const existing = await this.searchConversations({ contactId, limit: 1 });
    if (existing.length > 0) {
      return existing[0];
    }
    return this.createConversation({ type: "contact", contactId });
  }

  async createMessage(data: Omit<Message, "id" | "createdAt">): Promise<Message | null> {
    const message = createMessage(data);
    const client = await this.db.connect();
    try {
      const result = await client.query(
        `INSERT INTO messages (id, conversation_id, provider_message_id, direction, type, sender_contact_id, content, metadata, status, created_at)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
         ON CONFLICT (provider_message_id) DO NOTHING
         RETURNING *`,
        [message.id, message.conversationId, message.providerMessageId, message.direction, message.type, message.senderContactId, message.content, JSON.stringify(message.metadata), message.status, message.createdAt]
      );
      if (result.rows.length === 0) {
        return null;
      }
      return mapMessage(result.rows[0]);
    } finally {
      client.release();
    }
  }

  async touchConversation(conversationId: string, at: Date): Promise<void> {
    const client = await this.db.connect();
    try {
      await client.query(
        `UPDATE conversations SET last_message_at = $2, updated_at = $2 WHERE id = $1`,
        [conversationId, at]
      );
    } finally {
      client.release();
    }
  }

  async applyStatusUpdate(providerMessageId: string, status: string): Promise<void> {
    const client = await this.db.connect();
    try {
      await client.query(
        `UPDATE messages SET status = $2::text,
           sent_at = CASE WHEN $2::text IN ('sent','delivered','read') AND sent_at IS NULL THEN NOW() ELSE sent_at END,
           delivered_at = CASE WHEN $2::text IN ('delivered','read') AND delivered_at IS NULL THEN NOW() ELSE delivered_at END,
           read_at = CASE WHEN $2::text = 'read' AND read_at IS NULL THEN NOW() ELSE read_at END
         WHERE provider_message_id = $1`,
        [providerMessageId, status]
      );
    } finally {
      client.release();
    }
  }

  async getMessageById(messageId: string): Promise<Message | null> {
    const client = await this.db.connect();
    try {
      const result = await client.query("SELECT * FROM messages WHERE id = $1", [messageId]);
      return result.rows[0] ? mapMessage(result.rows[0]) : null;
    } finally {
      client.release();
    }
  }

  async getConversationById(conversationId: string): Promise<Conversation | null> {
    const client = await this.db.connect();
    try {
      const result = await client.query("SELECT * FROM conversations WHERE id = $1", [conversationId]);
      return result.rows[0] ? mapConversation(result.rows[0]) : null;
    } finally {
      client.release();
    }
  }

  async createMedia(data: Omit<Media, "id" | "createdAt">): Promise<Media> {
    const media = createMedia(data);
    const client = await this.db.connect();
    try {
      await client.query(
        `INSERT INTO media (id, message_id, type, filename, mime_type, size_bytes, storage_key, metadata, created_at)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)`,
        [media.id, media.messageId, media.type, media.filename, media.mimeType, media.sizeBytes, media.storageKey, JSON.stringify(media.metadata), media.createdAt]
      );
      return media;
    } finally {
      client.release();
    }
  }

  async getMediaById(mediaId: string): Promise<Media | null> {
    const client = await this.db.connect();
    try {
      const result = await client.query("SELECT * FROM media WHERE id = $1", [mediaId]);
      return result.rows[0] ? mapMedia(result.rows[0]) : null;
    } finally {
      client.release();
    }
  }

  async getMediaByMessageId(messageId: string): Promise<Media | null> {
    const client = await this.db.connect();
    try {
      const result = await client.query("SELECT * FROM media WHERE message_id = $1 ORDER BY created_at DESC LIMIT 1", [messageId]);
      return result.rows[0] ? mapMedia(result.rows[0]) : null;
    } finally {
      client.release();
    }
  }

  async updateMediaProcessed(mediaId: string, extractedText: string, processing: Record<string, unknown>): Promise<void> {
    const client = await this.db.connect();
    try {
      await client.query(
        `UPDATE media SET extracted_text = $2, metadata = jsonb_set(coalesce(metadata, '{}'::jsonb), '{processing}', $3::jsonb) WHERE id = $1`,
        [mediaId, extractedText, JSON.stringify(processing)]
      );
    } finally {
      client.release();
    }
  }

  async getEmbeddingDimension(): Promise<number | null> {
    const client = await this.db.connect();
    try {
      const result = await client.query(
        `SELECT format_type(atttypid, atttypmod) AS coltype
         FROM pg_attribute
         WHERE attrelid = 'messages'::regclass AND attname = 'embedding' AND attnum > 0 AND NOT attisdropped`
      );
      const coltype = result.rows[0]?.coltype as string | undefined;
      const match = coltype?.match(/vector\((\d+)\)/);
      return match ? parseInt(match[1], 10) : null;
    } finally {
      client.release();
    }
  }

  async resizeEmbeddingColumn(dimensions: number): Promise<void> {
    const client = await this.db.connect();
    try {
      await client.query("DROP INDEX IF EXISTS idx_messages_embedding;");
      await client.query("UPDATE messages SET embedding = NULL WHERE embedding IS NOT NULL;");
      await client.query(`ALTER TABLE messages ALTER COLUMN embedding TYPE vector(${dimensions});`);
      await client.query(
        "CREATE INDEX IF NOT EXISTS idx_messages_embedding ON messages USING hnsw (embedding vector_cosine_ops);"
      );
    } finally {
      client.release();
    }
  }

  async listMessagesBatch(
    after: { createdAt: Date; id: string } | null,
    limit: number,
    onlyMissing = false
  ): Promise<Array<{ id: string; createdAt: Date; hasEmbedding: boolean; hasSearchText: boolean }>> {
    const client = await this.db.connect();
    try {
      const params: unknown[] = [];
      const conditions: string[] = [];
      if (onlyMissing) {
        conditions.push("(embedding IS NULL OR search_text IS NULL)");
      }
      if (after) {
        params.push(after.createdAt, after.id);
        conditions.push(`(created_at, id) > ($${params.length - 1}, $${params.length})`);
      }
      const where = conditions.length > 0 ? `WHERE ${conditions.join(" AND ")}` : "";
      params.push(limit);
      const result = await client.query(
        `SELECT id, created_at, (embedding IS NOT NULL) AS has_embedding, (search_text IS NOT NULL) AS has_search_text
         FROM messages ${where}
         ORDER BY created_at, id
         LIMIT $${params.length}`,
        params
      );
      return result.rows.map((row) => ({
        id: row.id,
        createdAt: row.created_at,
        hasEmbedding: row.has_embedding,
        hasSearchText: row.has_search_text,
      }));
    } finally {
      client.release();
    }
  }
}

interface SearchMessagesFilters {
  conversationId?: string;
  contactId?: string;
  type?: string;
  direction?: string;
  from?: Date;
  to?: Date;
  search?: string;
  limit?: number;
  offset?: number;
  minScore?: number;
}

interface SearchConversationsFilters {
  name?: string;
  contactId?: string;
  groupId?: string;
  search?: string;
  limit?: number;
  offset?: number;
}

interface SearchContactsFilters {
  name?: string;
  phoneNumber?: string;
  search?: string;
  limit?: number;
  offset?: number;
}

interface SearchGroupsFilters {
  name?: string;
  search?: string;
  limit?: number;
  offset?: number;
}
