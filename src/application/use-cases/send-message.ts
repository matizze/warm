import { WhatsAppService } from "../../domain/services/whatsapp-service";
import { OutgoingMessage, SendResult } from "../../adapters/providers/types";

export async function sendMessage(whatsappService: WhatsAppService, message: OutgoingMessage): Promise<SendResult> {
  return whatsappService.sendMessage(message);
}

export async function editMessage(whatsappService: WhatsAppService, messageId: string, content: string): Promise<SendResult> {
  return whatsappService.editMessage(messageId, content);
}

export async function sendSticker(whatsappService: WhatsAppService, stickerId: string, conversationId: string): Promise<SendResult> {
  return whatsappService.sendSticker(stickerId, conversationId);
}

export async function react(whatsappService: WhatsAppService, messageId: string, emoji: string): Promise<SendResult> {
  return whatsappService.react(messageId, emoji);
}

export async function deleteMessage(whatsappService: WhatsAppService, messageId: string): Promise<SendResult> {
  return whatsappService.deleteMessage(messageId);
}

export async function reply(whatsappService: WhatsAppService, messageId: string, content: string): Promise<SendResult> {
  return whatsappService.reply(messageId, content);
}
