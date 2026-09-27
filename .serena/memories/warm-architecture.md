# WARM — WhatsApp Agent Architecture

Project at /Users/henriquecosta/Code/matizze/warm. A single WhatsApp instance connected to AI via MCP.

## Three Main Flows

1. **Inbound (Recebimento)**: WhatsApp → Provider → Webhook → normalize() → Application → Persistence
2. **Outbound (Envio)**: AI Agent → MCP → Application/Use Case → WhatsAppService → Provider → WhatsApp
3. **Query (Consulta)**: AI Agent → MCP → Application → QueryService → Persistence

Key architectural notes:
- Each WARM installation = one WhatsApp instance, one provider, one database, one MCP server
- `normalize()` converts provider-specific payloads to typed `IncomingMessage` contracts
- `WhatsAppService` (Ports and Adapters pattern) — interface completa do provider para outbound: sendMessage, editMessage, sendSticker, react, deleteMessage, reply, etc.
- No `normalize()` in outbound flow; no `instance_id` in tables (single instance per DB)
- Providers: Evolution, Z-API, WhatsMeow (configurable via `WHATSAPP_PROVIDER` env var)
- MCP exposes capabilities (conversation.search, message.search, message.send, etc.), not raw CRUD
- Credential storage: env vars / secret manager, never in DB