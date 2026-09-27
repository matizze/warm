import config from "../../config/index";
import { normalizeEvolutionWebhook, normalizeEvolutionStatus, StatusUpdate } from "../../adapters/providers/evolution-normalize";

export interface IncomingMessage {
  id: string;
  providerId: string;
  conversationId: string;
  senderContactId: string;
  direction: "inbound" | "outbound";
  type: string;
  content?: string;
  timestamp: Date;
  mediaUrl?: string;
  mediaType?: string;
  quotedMsgId?: string;
  metadata: Record<string, unknown>;
}

export function normalize(payload: Record<string, any>): IncomingMessage {
  switch (config.provider) {
    case "evolution": {
      const normalized = normalizeEvolutionWebhook(payload);
      if (!normalized) {
        throw new Error("Unsupported evolution webhook event");
      }
      return normalized;
    }
    default:
      throw new Error(`No normalizer for provider: ${config.provider}`);
  }
}

export function normalizeStatus(payload: Record<string, any>): StatusUpdate | null {
  switch (config.provider) {
    case "evolution":
      return normalizeEvolutionStatus(payload);
    default:
      return null;
  }
}

