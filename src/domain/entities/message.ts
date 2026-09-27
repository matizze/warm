import { v4 as uuidv4 } from "uuid";

export type MessageDirection = "inbound" | "outbound";
export type MessageStatus = "pending" | "sending" | "sent" | "delivered" | "read" | "failed";
export type MessageType = "text" | "image" | "audio" | "video" | "document" | "sticker" | "location" | "contact";

export interface MediaRef {
  id: string;
  type: string;
  mimeType: string;
  sizeBytes: number;
  storageKey: string;
}

export interface Message {
  id: string;
  conversationId: string;
  providerMessageId?: string;
  direction: MessageDirection;
  type: MessageType;
  senderContactId?: string;
  content?: string;
  searchText?: string | null;
  metadata: Record<string, unknown>;
  status: MessageStatus;
  sentAt?: Date;
  deliveredAt?: Date;
  readAt?: Date;
  createdAt: Date;
  media?: MediaRef;
}

export function createMessage(data: Omit<Message, "id" | "createdAt">): Message {
  return {
    ...data,
    id: uuidv4(),
    createdAt: new Date(),
  };
}
