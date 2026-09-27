export type ProviderDirection = "inbound" | "outbound";

export interface ProviderMessagePayload {
  id: string;
  from: string;
  to: string;
  type: string;
  body?: string;
  timestamp: number;
  isGroupMsg?: boolean;
  senderName?: string;
  pushName?: string;
  mediaUrl?: string;
  mediaType?: string;
  quotedMsgId?: string;
  metadata?: Record<string, unknown>;
}

export interface ProviderSendPayload {
  conversationId: string;
  type: string;
  content?: string;
  mediaUrl?: string;
  quotedMsgId?: string;
}

export interface ProviderSendResult {
  key?: { id: string };
  status: string;
  timestamp?: number;
}

export interface OutgoingMessage {
  conversationId: string;
  type: string;
  content?: string;
  mediaUrl?: string;
  mediaBase64?: string;
  fileName?: string;
  mimetype?: string;
  quotedMsgId?: string;
}

export interface SendResult {
  status: string;
  providerMessageId?: string;
}

export interface ProviderMedia {
  base64: string;
  mimetype?: string;
  fileName?: string;
  caption?: string;
}
