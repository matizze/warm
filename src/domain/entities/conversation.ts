import { v4 as uuidv4 } from "uuid";

export type ConversationType = "contact" | "group";

export interface Conversation {
  id: string;
  type: ConversationType;
  contactId?: string;
  groupId?: string;
  createdAt: Date;
  updatedAt: Date;
  lastMessageAt?: Date;
}

export function createConversation(data: Omit<Conversation, "id" | "createdAt" | "updatedAt">): Conversation {
  return {
    ...data,
    id: uuidv4(),
    createdAt: new Date(),
    updatedAt: new Date(),
  };
}
