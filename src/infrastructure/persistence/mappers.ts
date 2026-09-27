import { Contact } from "../../domain/entities/contact";
import { Group } from "../../domain/entities/group";
import { Conversation } from "../../domain/entities/conversation";
import { Message } from "../../domain/entities/message";
import { Media } from "../../domain/entities/media";

type Row = Record<string, any>;

export function mapContact(row: Row): Contact {
  return {
    id: row.id,
    phoneNumber: row.phone_number,
    name: row.name ?? undefined,
    pushName: row.push_name ?? undefined,
    observations: row.observations ?? "",
    createdAt: row.created_at,
    updatedAt: row.updated_at,
  };
}

export function mapGroup(row: Row): Group {
  return {
    id: row.id,
    providerGroupId: row.provider_group_id,
    name: row.name,
    description: row.description ?? "",
    observations: row.observations ?? "",
    createdAt: row.created_at,
    updatedAt: row.updated_at,
  };
}

export function mapConversation(row: Row): Conversation {
  return {
    id: row.id,
    type: row.type,
    contactId: row.contact_id ?? undefined,
    groupId: row.group_id ?? undefined,
    createdAt: row.created_at,
    updatedAt: row.updated_at,
    lastMessageAt: row.last_message_at ?? undefined,
  };
}

export function mapMessage(row: Row): Message {
  return {
    id: row.id,
    conversationId: row.conversation_id,
    providerMessageId: row.provider_message_id ?? undefined,
    direction: row.direction,
    type: row.type,
    senderContactId: row.sender_contact_id ?? undefined,
    content: row.content ?? undefined,
    searchText: row.search_text ?? null,
    metadata: row.metadata ?? {},
    status: row.status,
    sentAt: row.sent_at ?? undefined,
    deliveredAt: row.delivered_at ?? undefined,
    readAt: row.read_at ?? undefined,
    createdAt: row.created_at,
  };
}

export function mapMedia(row: Row): Media {
  return {
    id: row.id,
    messageId: row.message_id ?? null,
    type: row.type,
    filename: row.filename,
    mimeType: row.mime_type,
    sizeBytes: Number(row.size_bytes),
    storageKey: row.storage_key,
    extractedText: row.extracted_text ?? null,
    metadata: row.metadata ?? {},
    createdAt: row.created_at,
  };
}
