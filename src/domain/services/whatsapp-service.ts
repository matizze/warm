import { OutgoingMessage, SendResult, ProviderMedia } from "../../adapters/providers/types";

export interface WhatsAppService {
  sendMessage(message: OutgoingMessage): Promise<SendResult>;
  editMessage(messageId: string, content: string): Promise<SendResult>;
  sendSticker(stickerId: string, conversationId: string): Promise<SendResult>;
  react(messageId: string, emoji: string): Promise<SendResult>;
  deleteMessage(messageId: string): Promise<SendResult>;
  reply(messageId: string, content: string): Promise<SendResult>;
  getMedia(providerMessageId: string, remoteJid: string, fromMe: boolean): Promise<ProviderMedia>;
}
