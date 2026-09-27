import { WhatsAppService } from "../../domain/services/whatsapp-service";
import { OutgoingMessage, SendResult, ProviderSendResult, ProviderMedia } from "./types";
import config from "../../config/index";

export class EvolutionAdapter implements WhatsAppService {
  private baseUrl: string;
  private apiKey: string;
  private instance: string;

  constructor() {
    this.baseUrl = config.evolutionUrl.replace(/\/$/, "");
    this.apiKey = config.evolutionApiKey;
    this.instance = encodeURIComponent(config.evolutionInstanceId);
  }

  private async request(method: string, path: string, body?: unknown): Promise<ProviderSendResult> {
    const response = await fetch(`${this.baseUrl}${path}`, {
      method,
      headers: {
        "Content-Type": "application/json",
        apikey: this.apiKey,
      },
      body: body ? JSON.stringify(body) : undefined,
    });

    const text = await response.text();
    let parsed: any = {};
    try {
      parsed = text ? JSON.parse(text) : {};
    } catch {
      parsed = { raw: text };
    }

    if (!response.ok) {
      throw new Error(`Evolution request failed (${response.status}): ${text}`);
    }

    return parsed as ProviderSendResult;
  }

  private toResult(result: ProviderSendResult): SendResult {
    return {
      status: result.status || "sent",
      providerMessageId: result.key?.id,
    };
  }

  async sendMessage(message: OutgoingMessage): Promise<SendResult> {
    if (message.type === "image" || message.type === "audio" || message.type === "video" || message.type === "document") {
      const result = await this.request("POST", `/message/sendMedia/${this.instance}`, {
        number: message.conversationId,
        mediatype: message.type,
        media: message.mediaBase64 || message.mediaUrl,
        mimetype: message.mimetype,
        fileName: message.fileName,
        caption: message.content,
      });
      return this.toResult(result);
    }

    const result = await this.request("POST", `/message/sendText/${this.instance}`, {
      number: message.conversationId,
      text: message.content,
    });
    return this.toResult(result);
  }

  async editMessage(messageId: string, content: string): Promise<SendResult> {
    const result = await this.request("POST", `/message/updateMessage/${this.instance}`, {
      id: messageId,
      text: content,
    });
    return this.toResult(result);
  }

  async sendSticker(stickerId: string, conversationId: string): Promise<SendResult> {
    const result = await this.request("POST", `/message/sendSticker/${this.instance}`, {
      number: conversationId,
      sticker: stickerId,
    });
    return this.toResult(result);
  }

  async react(messageId: string, emoji: string): Promise<SendResult> {
    const result = await this.request("POST", `/message/sendReaction/${this.instance}`, {
      key: { id: messageId },
      reaction: emoji,
    });
    return this.toResult(result);
  }

  async deleteMessage(messageId: string): Promise<SendResult> {
    const result = await this.request("POST", `/chat/deleteMessageForEveryone/${this.instance}`, {
      id: messageId,
    });
    return this.toResult(result);
  }

  async reply(messageId: string, content: string): Promise<SendResult> {
    const result = await this.request("POST", `/message/sendText/${this.instance}`, {
      number: messageId,
      text: content,
    });
    return this.toResult(result);
  }

  async getMedia(providerMessageId: string, remoteJid: string, fromMe: boolean): Promise<ProviderMedia> {
    const response = await this.request("POST", `/chat/getBase64FromMediaMessage/${this.instance}`, {
      message: {
        key: {
          id: providerMessageId,
          remoteJid,
          fromMe,
        },
      },
    });

    const media = response as any;
    if (!media || !media.base64) {
      throw new Error(`Evolution returned no media for message ${providerMessageId}`);
    }

    return {
      base64: media.base64,
      mimetype: media.mimetype,
      fileName: media.fileName,
      caption: media.caption,
    };
  }
}
