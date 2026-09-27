import { Router, Request, Response, NextFunction } from "express";
import { normalize, normalizeStatus } from "../application/normalize/index";
import { QueryService } from "../domain/services/query-service";
import { MediaService } from "../application/media/media-service";
import { SearchService } from "../application/search/search-service";
import config from "../config/index";

const MEDIA_TYPES = ["image", "audio", "video", "document", "sticker"];

function authenticate(req: Request, res: Response, next: NextFunction): void {
  const expected = config.webhookToken;
  if (!expected) {
    next();
    return;
  }
  const provided = req.header("x-webhook-token") || req.query.token;
  if (provided !== expected) {
    res.status(401).json({ status: "unauthorized" });
    return;
  }
  next();
}

export function createWebhookRouter(
  queryService: QueryService,
  mediaService?: MediaService,
  searchService?: SearchService
): Router {
  const router = Router();

  router.post("/webhook", authenticate, async (req, res) => {
    const statusUpdate = normalizeStatus(req.body);
    if (statusUpdate) {
      try {
        await queryService.applyStatusUpdate(statusUpdate.providerMessageId, statusUpdate.status);
        res.status(200).json({ status: "ok", update: statusUpdate.status });
      } catch (error) {
        console.error("Status update error:", error);
        res.status(500).json({ status: "error" });
      }
      return;
    }

    let incoming;
    try {
      incoming = normalize(req.body);
    } catch (error) {
      console.warn("Ignoring unsupported webhook payload:", (error as Error).message, "EVENT:", req.body?.event);
      res.status(200).json({ status: "ignored" });
      return;
    }

    try {
      const pushName = typeof incoming.metadata?.pushName === "string" ? incoming.metadata.pushName : undefined;
      const contact = await queryService.findOrCreateContact(incoming.senderContactId, pushName);
      const conversation = await queryService.findOrCreateConversationByContact(contact.id);

      const message = await queryService.createMessage({
        conversationId: conversation.id,
        providerMessageId: incoming.providerId,
        direction: incoming.direction,
        type: incoming.type as never,
        senderContactId: contact.id,
        content: incoming.content,
        metadata: {
          ...incoming.metadata,
          ...(incoming.mediaUrl ? { mediaUrl: incoming.mediaUrl } : {}),
          ...(incoming.mediaType ? { mediaType: incoming.mediaType } : {}),
        },
        status: "sent",
      });

      if (message) {
        await queryService.touchConversation(conversation.id, incoming.timestamp);

        if (searchService && !MEDIA_TYPES.includes(incoming.type)) {
          searchService.indexMessage(message.id).catch((error) => {
            console.warn(`Indexing failed for message ${message.id}:`, (error as Error).message);
          });
        }

        if (mediaService && MEDIA_TYPES.includes(incoming.type)) {
          mediaService
            .download(message.id)
            .then(() => searchService?.indexMessage(message.id))
            .catch((error) => {
              console.warn(`Media auto-download failed for message ${message.id}:`, (error as Error).message);
            });
        }
      }

      res.status(200).json({ status: "ok", duplicate: message === null });
    } catch (error) {
      console.error("Webhook error:", error);
      res.status(500).json({ status: "error" });
    }
  });

  return router;
}
