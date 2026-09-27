import { McpServer } from "@modelcontextprotocol/sdk/server/mcp";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio";
import { z } from "zod";
import { QueryService } from "../../domain/services/query-service";
import { WhatsAppService } from "../../domain/services/whatsapp-service";
import { MediaService } from "../../application/media/media-service";
import { SearchService } from "../../application/search/search-service";

export async function startMcpServer(
  queryService: QueryService,
  whatsappService: WhatsAppService,
  mediaService: MediaService,
  searchService: SearchService
): Promise<void> {
  const server = new McpServer({ name: "warm", version: "1.0.0" }, { capabilities: { tools: {} } });

  server.tool("message.send", "Envia uma mensagem", {
    conversationId: z.string(),
    type: z.enum(["text", "image", "audio", "video", "document"]).optional().default("text"),
    content: z.string().optional(),
    mediaId: z.string().optional(),
  }, async (args) => {
    let mediaPayload = {};
    if (args.mediaId) {
      const resolved = await mediaService.resolveForSend(args.mediaId);
      if (!resolved) {
        return { content: [{ type: "text", text: JSON.stringify({ error: `media not found: ${args.mediaId}` }) }], isError: true };
      }
      mediaPayload = { mediaBase64: resolved.base64, mimetype: resolved.mimetype, fileName: resolved.fileName };
    }
    const result = await whatsappService.sendMessage({
      conversationId: args.conversationId,
      type: args.type,
      content: args.content,
      ...mediaPayload,
    });
    return { content: [{ type: "text", text: JSON.stringify(result) }] };
  });

  server.tool("contact.search", "Pesquisa contatos", {
    name: z.string().optional(),
    phoneNumber: z.string().optional(),
    search: z.string().optional(),
    limit: z.number().optional(),
  }, async (args) => {
    const results = await queryService.searchContacts({ name: args.name, phoneNumber: args.phoneNumber, search: args.search, limit: args.limit });
    return { content: [{ type: "text", text: JSON.stringify(results) }] };
  });

  server.tool("contact.get", "Consulta o contexto de um contato", { contactId: z.string() }, async (args) => {
    const contact = await queryService.getContact(args.contactId);
    const conversations = await queryService.searchConversations({ contactId: args.contactId });
    return { content: [{ type: "text", text: JSON.stringify({ contact, conversations }) }] };
  });

  server.tool("group.search", "Pesquisa grupos", { name: z.string().optional(), search: z.string().optional(), limit: z.number().optional() }, async (args) => {
    const results = await queryService.searchGroups({ name: args.name, search: args.search, limit: args.limit });
    return { content: [{ type: "text", text: JSON.stringify(results) }] };
  });

  server.tool("group.get", "Consulta o contexto de um grupo", { groupId: z.string() }, async (args) => {
    const group = await queryService.getGroup(args.groupId);
    return { content: [{ type: "text", text: JSON.stringify(group) }] };
  });

  server.tool("message.search", "Pesquisa mensagens (lexical, semântica ou híbrida)", {
    conversationId: z.string().optional(),
    contactId: z.string().optional(),
    type: z.string().optional(),
    direction: z.string().optional(),
    search: z.string().optional(),
    mode: z.enum(["lexical", "semantic", "hybrid"]).optional().default("hybrid"),
    minScore: z.number().optional(),
    limit: z.number().optional(),
  }, async (args) => {
    const results = await searchService.searchMessages(
      {
        conversationId: args.conversationId,
        contactId: args.contactId,
        type: args.type,
        direction: args.direction,
        search: args.search,
        minScore: args.minScore,
        limit: args.limit,
      },
      args.mode
    );
    return { content: [{ type: "text", text: JSON.stringify(results) }] };
  });

  server.tool("conversation.search", "Pesquisa conversas", { name: z.string().optional(), search: z.string().optional(), limit: z.number().optional() }, async (args) => {
    const results = await queryService.searchConversations({ name: args.name, search: args.search, limit: args.limit });
    return { content: [{ type: "text", text: JSON.stringify(results) }] };
  });

  server.tool("media.download", "Recupera o arquivo original associado a uma mensagem e o armazena no storage", { messageId: z.string() }, async (args) => {
    const result = await mediaService.download(args.messageId);
    return { content: [{ type: "text", text: JSON.stringify(result) }] };
  });

  server.tool("media.upload", "Adiciona uma mídia ao sistema para posterior utilização em uma mensagem", {
    type: z.string(),
    base64: z.string(),
    filename: z.string(),
    mimeType: z.string().optional(),
    messageId: z.string().optional(),
  }, async (args) => {
    const result = await mediaService.upload({
      type: args.type,
      base64: args.base64,
      filename: args.filename,
      mimeType: args.mimeType,
      messageId: args.messageId,
    });
    return { content: [{ type: "text", text: JSON.stringify(result) }] };
  });

  const transport = new StdioServerTransport();
  await server.connect(transport);
}
