import { IncomingMessage } from "../../application/normalize/normalize";

interface EvolutionKey {
  remoteJid?: string;
  fromMe?: boolean;
  id?: string;
  participant?: string;
}

interface EvolutionData {
  key?: EvolutionKey;
  pushName?: string;
  message?: Record<string, any>;
  messageType?: string;
  messageTimestamp?: number;
  participant?: string;
}

interface EvolutionWebhook {
  event?: string;
  instance?: string;
  data?: EvolutionData;
}

const TEXT_TYPES: Record<string, string> = {
  conversation: "text",
  extendedTextMessage: "text",
  imageMessage: "image",
  audioMessage: "audio",
  videoMessage: "video",
  documentMessage: "document",
  documentWithCaptionMessage: "document",
  stickerMessage: "sticker",
  locationMessage: "location",
  contactMessage: "contact",
  contactsArrayMessage: "contact",
};

function detectType(message: Record<string, any>): string {
  for (const key of Object.keys(message)) {
    if (TEXT_TYPES[key]) {
      return TEXT_TYPES[key];
    }
  }
  return "unknown";
}

function extractText(message: Record<string, any>): string | undefined {
  if (typeof message.conversation === "string") return message.conversation;
  if (message.extendedTextMessage?.text) return message.extendedTextMessage.text;
  if (message.imageMessage?.caption) return message.imageMessage.caption;
  if (message.videoMessage?.caption) return message.videoMessage.caption;
  if (message.documentMessage?.caption) return message.documentMessage.caption;
  return undefined;
}

function extractMedia(message: Record<string, any>, type: string): { mediaUrl?: string; mediaType?: string } {
  const media =
    message.imageMessage ||
    message.audioMessage ||
    message.videoMessage ||
    message.documentMessage ||
    message.stickerMessage;
  if (!media) return {};
  return { mediaUrl: media.url, mediaType: media.mimetype };
}

const SUPPORTED_EVENTS = ["messages.upsert", "send.message"];

export function normalizeEvolutionWebhook(payload: EvolutionWebhook): IncomingMessage | null {
  const event = payload.event?.toLowerCase();
  if (event && !SUPPORTED_EVENTS.includes(event)) {
    return null;
  }

  const data = payload.data;
  if (!data || !data.key) {
    return null;
  }

  const key = data.key;
  const remoteJid = key.remoteJid;
  if (!remoteJid) {
    return null;
  }

  const message = data.message || {};
  const type = detectType(message);
  const isGroup = remoteJid.endsWith("@g.us");
  const timestampMs = data.messageTimestamp ? data.messageTimestamp * 1000 : Date.now();
  const { mediaUrl, mediaType } = extractMedia(message, type);

  return {
    id: key.id || `${remoteJid}-${timestampMs}`,
    providerId: key.id || `${remoteJid}-${timestampMs}`,
    conversationId: remoteJid,
    senderContactId: isGroup ? data.participant || key.participant || remoteJid : remoteJid,
    direction: key.fromMe ? "outbound" : "inbound",
    type,
    content: extractText(message),
    timestamp: new Date(timestampMs),
    mediaUrl,
    mediaType,
    metadata: {
      pushName: data.pushName,
      isGroup,
      remoteJid,
      messageType: data.messageType,
    },
  };
}

export interface StatusUpdate {
  providerMessageId: string;
  status: "pending" | "sent" | "delivered" | "read" | "failed";
}

const STATUS_MAP: Record<string, StatusUpdate["status"]> = {
  PENDING: "pending",
  SERVER_ACK: "sent",
  DELIVERY_ACK: "delivered",
  READ: "read",
  PLAYED: "read",
  ERROR: "failed",
};

export function normalizeEvolutionStatus(payload: EvolutionWebhook): StatusUpdate | null {
  if (payload.event?.toLowerCase() !== "messages.update") {
    return null;
  }
  const data = payload.data as any;
  if (!data || !data.keyId) {
    return null;
  }
  return {
    providerMessageId: data.keyId,
    status: STATUS_MAP[data.status] || "sent",
  };
}

