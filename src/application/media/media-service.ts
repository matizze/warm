import { v4 as uuidv4 } from "uuid";
import { QueryService } from "../../domain/services/query-service";
import { WhatsAppService } from "../../domain/services/whatsapp-service";
import { StorageService } from "../../domain/services/storage-service";
import { ProcessingServicePort } from "../../domain/services/media-processor";
import { Media } from "../../domain/entities/media";

export interface MediaResult {
  mediaId: string;
  storageKey: string;
  mimeType: string;
  sizeBytes: number;
  signedUrl: string;
  filename: string;
  extractedText?: string | null;
  processing?: Record<string, unknown>;
}

export interface UploadInput {
  type: string;
  base64: string;
  filename: string;
  mimeType?: string;
  messageId?: string;
}

export interface SendMediaPayload {
  base64: string;
  mimetype?: string;
  fileName?: string;
}

export class MediaService {
  constructor(
    private query: QueryService,
    private whatsapp: WhatsAppService,
    private storage: StorageService,
    private processing?: ProcessingServicePort
  ) {}

  private async buildResult(media: Media): Promise<MediaResult> {
    return {
      mediaId: media.id,
      storageKey: media.storageKey,
      mimeType: media.mimeType,
      sizeBytes: media.sizeBytes,
      filename: media.filename,
      extractedText: media.extractedText,
      processing: (media.metadata as Record<string, unknown>)?.processing as Record<string, unknown> | undefined,
      signedUrl: await this.storage.getSignedUrl(media.storageKey),
    };
  }

  private async processMedia(media: Media, buffer: Buffer): Promise<Media> {
    if (!this.processing) {
      return media;
    }

    const context = { type: media.type, mimeType: media.mimeType, filename: media.filename };
    try {
      const results = await this.processing.process(buffer, context);

      if (results.length === 0) {
        await this.query.updateMediaProcessed(media.id, "", { status: "skipped", processor: null });
        return { ...media, extractedText: "", metadata: { ...media.metadata, processing: { status: "skipped" } } };
      }

      const extractedText = results.map((r) => r.text).filter(Boolean).join("\n\n");
      const processing = {
        status: "done",
        kinds: results.map((r) => r.kind),
        details: results.map((r) => ({ kind: r.kind, ...(r.metadata || {}) })),
      };

      await this.query.updateMediaProcessed(media.id, extractedText, processing);
      return {
        ...media,
        extractedText,
        metadata: { ...media.metadata, processing },
      };
    } catch (error) {
      const processing = { status: "failed", error: (error as Error).message };
      await this.query.updateMediaProcessed(media.id, media.extractedText || "", processing);
      return { ...media, metadata: { ...media.metadata, processing } };
    }
  }

  async download(messageId: string): Promise<MediaResult> {
    const message = await this.query.getMessageById(messageId);
    if (!message) {
      throw new Error(`Message not found: ${messageId}`);
    }
    if (!message.providerMessageId) {
      throw new Error(`Message ${messageId} has no provider message id`);
    }

    const remoteJid =
      (message.metadata as Record<string, unknown>)?.remoteJid as string | undefined ||
      (await this.resolveRemoteJid(message.conversationId));
    const fromMe = message.direction === "outbound";

    const provider = await this.whatsapp.getMedia(message.providerMessageId, remoteJid, fromMe);
    const buffer = Buffer.from(provider.base64, "base64");
    const filename = provider.fileName || `${message.providerMessageId}.bin`;
    const mimeType = provider.mimetype || "application/octet-stream";
    const storageKey = `media/${messageId}/${filename}`;

    await this.storage.put(storageKey, buffer, mimeType);

    let media = await this.query.createMedia({
      messageId,
      type: message.type,
      filename,
      mimeType,
      sizeBytes: buffer.length,
      storageKey,
      extractedText: null,
      metadata: provider.caption ? { caption: provider.caption } : {},
    });

    media = await this.processMedia(media, buffer);

    return this.buildResult(media);
  }

  async upload(input: UploadInput): Promise<MediaResult> {
    const buffer = Buffer.from(input.base64, "base64");
    const mimeType = input.mimeType || "application/octet-stream";
    const storageKey = `media/uploads/${uuidv4()}/${input.filename}`;

    await this.storage.put(storageKey, buffer, mimeType);

    let media = await this.query.createMedia({
      messageId: input.messageId || null,
      type: input.type,
      filename: input.filename,
      mimeType,
      sizeBytes: buffer.length,
      storageKey,
      extractedText: null,
      metadata: {},
    });

    media = await this.processMedia(media, buffer);

    return this.buildResult(media);
  }

  async resolveForSend(mediaId: string): Promise<SendMediaPayload | null> {
    const media = await this.query.getMediaById(mediaId);
    if (!media) {
      return null;
    }
    const buffer = await this.storage.get(media.storageKey);
    return {
      base64: buffer.toString("base64"),
      mimetype: media.mimeType,
      fileName: media.filename,
    };
  }

  private async resolveRemoteJid(conversationId: string): Promise<string> {
    const conversation = await this.query.getConversationById(conversationId);
    if (conversation?.contactId) {
      const contact = await this.query.getContact(conversation.contactId);
      if (contact?.phoneNumber) {
        return contact.phoneNumber;
      }
    }
    if (conversation?.groupId) {
      const group = await this.query.getGroup(conversation.groupId);
      if (group?.providerGroupId) {
        return group.providerGroupId;
      }
    }
    throw new Error(`Could not resolve remoteJid for conversation ${conversationId}`);
  }
}
