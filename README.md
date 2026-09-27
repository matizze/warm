# WARM — Laravel 12

Porte do WARM (originalmente TypeScript) para **Laravel 12**. Cada instalação
representa **uma instância de WhatsApp**, com um provider, um banco e um MCP
server.

> Regra de dependências: a única biblioteca de aplicação é
> [`lorisleiva/laravel-actions`](https://laravelactions.com). O adapter S3
> oficial `league/flysystem-aws-s3-v3` é usado apenas como driver do disco
> `warm` (necessário para S3/RustFS). Todo o resto vem do framework.

## Requisitos

- PHP 8.2+ (testado com 8.4), extensões `pdo_pgsql`, `mbstring`
- `pdftotext` (poppler) no PATH — extração de texto de PDF
- PostgreSQL com a extensão **pgvector** (aqui: container `pgvector/pgvector:pg16` na porta 5433)
- S3 compatível (RustFS do Laravel Herd, MinIO ou AWS)
- Composer 2

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate

createdb -h 127.0.0.1 -p 5433 -U warm warm_laravel      # banco de trabalho
php artisan migrate

# bucket de mídia (RustFS: access=herd / secret=secretkey)
mc alias set warmfs http://localhost:9000 herd secretkey
mc mb --ignore-existing warmfs/warm-media
```

## Processos

O WARM roda como processos separados:

```bash
php artisan serve            # HTTP: POST /webhook, GET /health, POST /admin/reindex
php artisan queue:work       # DownloadMedia + IndexMessage
php artisan schedule:work    # warm:reindex (cron configurável)
php artisan mcp:serve        # MCP server (stdio) — spawnado pelo cliente de IA
```

## Endpoints

| Método | Rota              | Descrição                                             |
| ------ | ----------------- | ----------------------------------------------------- |
| POST   | `/webhook`        | Recebe eventos do provider (auth: `x-webhook-token` ou `?token=`) |
| GET    | `/health`         | `{"status":"ok"}`                                     |
| POST   | `/admin/reindex`  | Dispara reindex (auth: `x-admin-token`; 404 se `ADMIN_TOKEN` vazio) |

Comportamento do `/webhook`:

- Token ausente/errado → `401`. Sem `WEBHOOK_TOKEN` configurado, só passa em
  `local`/`testing`; em qualquer outro ambiente todo request é rejeitado.
- Evento não suportado → `200 {"status":"ignored"}`
- Mensagem nova → `200 {"status":"ok","duplicate":false}`
- `messages.update` → aplica status + timestamps, `200 {"status":"ok","update":"read"}`
- Duplicata (mesmo `provider_message_id`) → `200 {"status":"ok","duplicate":true}`

## MCP

`php artisan mcp:serve` fala JSON-RPC 2.0 sobre stdio (sem SDK). Logs vão para
STDERR para não corromper o protocolo.

Tools: `message.send`, `contact.search`, `contact.get`, `group.search`,
`group.get`, `message.search` (lexical/semantic/hybrid), `conversation.search`,
`media.download`, `media.upload`.

## Mapeamento TypeScript → Laravel

| TS (original)                          | Laravel 12                                              |
| -------------------------------------- | ------------------------------------------------------- |
| `domain/entities/*`                     | `app/Models/*` (Eloquent + `HasUuids`)                  |
| `domain/services/query-service.ts`      | Eloquent (scopes, casts) — sem camada de mappers        |
| `domain/services/whatsapp-service.ts`   | `App\Contracts\WhatsAppProvider`                        |
| `adapters/providers/evolution-*`        | `App\Integrations\Evolution\*`                          |
| `application/normalize`                 | `App\Integrations\Normalizer`                           |
| `application/use-cases/*`               | `app/Actions/*` (laravel-actions)                       |
| `application/media/media-service.ts`    | `App\Actions\Media\{Download,Upload,Process}Media`      |
| `application/search/search-service.ts`  | `App\Actions\Messages\SearchMessages` (RRF k=60)        |
| `infrastructure/persistence/database`   | `database/migrations/*` + `CREATE EXTENSION vector`     |
| `infrastructure/storage/*`              | disco `warm` (`config/filesystems.php`) + `MediaStorage`|
| `infrastructure/processing/*`           | `app/Processing/*` (`pdftotext`, Whisper, vision)       |
| `infrastructure/embeddings/*`           | `App\Embeddings\EmbeddingService`                       |
| `infrastructure/mcp/server.ts`          | `app/Mcp/*` + `mcp:serve`                               |
| `webhook/handler.ts`                    | `App\Actions\Webhook\ReceiveWebhook`                    |
| `scripts/reindex.ts`                    | `App\Actions\Search\ReindexMessages` (`warm:reindex`)   |
| `node-cron` scheduler                   | `routes/console.php` + `php artisan schedule:work`      |
| idempotência `ON CONFLICT DO NOTHING`   | `DB::table('messages')->insertOrIgnore(...)`            |

### Laravel Actions

Todas as ações usam apenas `AsAction`, que já incorpora `AsController`,
`AsJob`, `AsCommand` e `AsListener`. O comportamento é escolhido pelo contexto
de uso: rotas (`ReceiveWebhook`, `HealthCheck`, `AdminReindex`), jobs
(`DownloadMedia`, `IndexMessage`) e comando (`ReindexMessages` → `warm:reindex`).

Os comandos das Actions são registrados em `AppServiceProvider::boot()` via
`Actions::registerCommands()` (exigência do pacote).

## Banco

`contacts`, `groups`, `conversations`, `messages`, `media` — sem `instance_id`
(o banco é a instância). `messages` tem `search_text` (texto indexável, usado
pela busca lexical via `ILIKE`) e `embedding vector(1536)` com índice HNSW
(cosseno).

## Testes

```bash
createdb -h 127.0.0.1 -p 5433 -U warm warm_laravel_test
php artisan test
./vendor/bin/pint
```

Os testes usam **Postgres** (não SQLite) porque as migrations dependem de
`vector`. Cobertura: autenticação do webhook, evento ignorado, persistência e
idempotência, status update, identidade de conversa (grupo vs contato) e
resolução do destinatário no envio, busca híbrida (RRF), download retomável e
validação das tools MCP.

## Notas

- **Identidade de conversa:** mensagens de grupo viram uma `Conversation` do
  tipo `group` (por `provider_group_id`); mensagens privadas viram `contact`. No
  envio, `message.send` recebe o UUID interno e resolve o destinatário
  (telefone ou JID do grupo) antes de falar com o provider.
- **Contrato do provider:** `WhatsAppProvider` expõe só `sendMessage` e
  `getMedia` — as operações que a aplicação realmente usa. Novas capacidades
  entram quando houver consumidor.
- **Mídia retomável:** o download usa chave determinística
  (`media/{messageId}/original`) e `storage_key` único; repetir não duplica
  linha, não rebaixa o arquivo nem repete chamadas ao provider/IA.
- **Reindexação:** `warm:reindex` nunca altera o schema. Se a dimensão de
  embedding configurada divergir da coluna, ele aborta com erro; redimensionar
  é uma operação explícita. Sem `OPENAI_API_KEY`, faz reindex apenas lexical.
- **Falhas explícitas:** erro de escrita no storage e erro do provider viram
  exceção; mensagens de erro não expõem corpo de resposta externo.
- `media.download` e `message.send` dependem de uma instância Evolution viva.
- Migrations padrão do Laravel (`users`, `cache`, `jobs`, `sessions`) são
  mantidas porque `queue`/`cache`/`session` usam o banco. As tabelas `users` e
  `password_reset_tokens` não são usadas.
- Z-API / WhatsMeow: adicionar normalizer + provider e registrar o binding em
  `AppServiceProvider`.
