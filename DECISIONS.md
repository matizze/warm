# Decisions

## Credenciais de instância só no banco

**Status:** decided / implemented (2026-11-03)

**Context.** Depois do multi-instância, a instância default tinha **duas** fontes
de senha: as rotas legadas (`/webhook`, `/mcp`) conferiam `WEBHOOK_TOKEN`/
`MCP_TOKEN` do env, e as rotas `/instance/{id}/...` conferiam a tabela
`instances`. Elas só começavam iguais porque a migração copiava o env; trocar uma
não trocava a outra. As credenciais também ficavam em texto puro.

**Decision.**

- A tabela `instances` é a **única** fonte de `evolution_url`, `api_key`,
  `webhook_token` e `mcp_token`. As rotas legadas resolvem a instância default
  (`instance.resolve`) e conferem os tokens dela; sem default → 404.
- `config/warm.php` não tem mais `webhook_token`, `mcp_token` nem `evolution.*`,
  e o provider do WhatsApp não cai mais para o env (sem default → exceção).
- `api_key`, `webhook_token` e `mcp_token` usam o cast `encrypted` (APP_KEY);
  as colunas viraram `text` (migração `2026_11_03_162905`).
- Cadastro e rotação por comando: `warm:instance:create`, `warm:instance:list`,
  `warm:instance:tokens`, `warm:instance:rotate-token`.
- A migração `2026_10_16_120000` segue importando `EVOLUTION_*`/`WEBHOOK_TOKEN`/
  `MCP_TOKEN` do env (via `env()`), só como caminho de upgrade de instalações
  pré-multi-instância; sem `EVOLUTION_INSTANCE_ID` ela não cria default.

**Consequences.**

- Trocar o `APP_KEY` torna as credenciais ilegíveis; é preciso recadastrar.
- Rollback para uma imagem anterior a esta decisão exige reverter a migração de
  criptografia antes (`php artisan migrate:rollback --step=1`), senão a imagem
  antiga compara tokens com o texto cifrado e responde 401; e as variáveis de env
  removidas precisam voltar à stack.

## Multi-instance Evolution (webhook e MCP por número)

**Status:** decided / implemented (2026-10-16)

**Context.** Até então a instalação falava com **uma** instância do Evolution
(`EVOLUTION_INSTANCE_ID`); `/webhook` e `/mcp` eram globais e conversas não
guardavam de qual número vieram. Para atender mais de um número (suporte +
pessoal) na mesma stack, o WARM passou a suportar múltiplas instâncias.

**Decision.**

- As instâncias são **persistidas na tabela `instances`** (não só em env):
  `instance_id` (PK, e também o nome da instância no Evolution), `evolution_url`,
  `api_key`, `webhook_token`, `mcp_token`, `is_default`, `active`.
- Ingestão e envio são **por instância**: `POST /instance/{id}/webhook` e
  `POST /instance/{id}/mcp`, cada uma com o token próprio (ausente/errado → 401;
  instância inexistente/inativa → 404). `conversations` e `messages` guardam
  `instance_id` e a resposta sai pela instância da conversa.
- As rotas legadas `POST /webhook` e `POST /mcp` roteiam para a instância
  **default** e autenticam com os tokens **dela** no banco (ver decisão
  "Credenciais só no banco").
- A primeira migração faz **backfill** da instância do env como default, ligando
  os dados existentes a ela sem ação do operador.
- O `instance_id` é um **slug URL-safe** (norma: sem espaços/acentos/maiúsculas;
  forma recomendada `^[a-z0-9][a-z0-9_-]*$`, não validada em código), porque
  aparece no caminho da URL e acumula a função de nome da instância no Evolution.
  Instâncias do Evolution novas devem ser nomeadas como slug.

**Consequences / follow-ups.**

- Operadores precisam criar instâncias no Evolution já com nome de slug; o
  legado `Matizze Suporte` (espaço → `%20`) é suportado, mas **frágil** e fora do
  padrão (observação da revisão da MTZ-136/MTZ-137).
- `media.download` por `messageId`, `contact.*` e `group.*` **não** são escopados
  à instância. Contatos/grupos globais são intencionais; `media.download` ainda
  alcança mídia de outra instância se o UUID for conhecido (só descobrível via
  `message.search`, já escopada). Endurecer depois.
- `instances.is_default` tem índice simples, não único: dois defaults fariam
  `Instance::default()` retornar linha arbitrária. Um índice parcial único
  resolveria.
- Token do webhook viaja em **query string** (`?token=`) e aparece em logs de
  borda (Traefik). Padrão pré-existente; um header (`x-webhook-token`) evitaria.
- Instância não-default sempre constrói `EvolutionProvider`, ignorando
  `config('warm.provider')` (FYI).

**Rollback.** Repin da imagem **não basta**: `conversations.instance_id` e
`messages.instance_id` são `NOT NULL` sem default, então a imagem antiga quebra a
ingestão ao inserir sem a coluna. O rollback do schema é obrigatório e roda
**antes** de a imagem antiga atender, pela imagem **nova** (que conhece as
migrações): `php artisan migrate:rollback --step=3 --force`, ou a alternativa
revisável `deployment/rollback-multi-instance.sql` (deduplica conversas e limpa o
`migrations`). Ver README → Manutenção do dia a dia → Rollback de versão.

## Object storage provider for media

**Status:** pending / revisited later (2026-09-27)

**Context.** Media (documents, images, audio) received via WhatsApp is downloaded and
persisted automatically on arrival (`ReceiveWebhook` dispatches `DownloadMedia`, which
stores every blob in the `warm` disk). The storage must be durable: the WhatsApp/Evolution
media links expire, so the stored copy is the only recoverable original.

**Current setup.** Local dev uses RustFS/MinIO (`STORAGE_DRIVER=s3`, endpoint
`http://localhost:9000`, bucket `warm-media`). The `warm` disk
(`config/filesystems.php`) is already S3-compatible and fully env-driven, so switching
providers is an env-only change — no application code changes.

**Candidates considered** (per Reddit/community pricing, 2026):

| Provider | Storage | Egress | Notes |
| --- | --- | --- | --- |
| MEGA S4 | ~$2.63/TB | free up to 5x stored | Cheapest; requires Pro plan (~€15/mo floor); regions NL/LU/CA; no min retention; consumer-oriented, privacy concerns raised; 5x egress cap |
| Backblaze B2 | ~$6.95/TB | free via Cloudflare | Best cost/reliability; pay-as-you-go, no floor; API calls now free; US-only datacenters |
| Cloudflare R2 | ~$15/TB | zero, unconditional | Most reliable; best if download volume grows |
| iDrive e2 | ~$4/TB | free up to 3x stored | Cheapest raw, but reliability/complaint reports; slower with many small files |
| Wasabi | ~$6.99/TB | free (1:1 ratio) | 90-day minimum retention — conflicts with early pruning plans |

**Decision.** Defer. Keep the current setup for now; there is not enough data stored to
justify a paid provider.

**Next step / when to revisit.** When stored volume grows, evaluate **MEGA S4** —
**currently preferred on price** (~2.63/TB, no min retention, free 5x egress), accepting
the monthly floor and the consumer-plan caveat. Fall back to **Backblaze B2** when volume
is low or US-only + pay-as-you-go matters more (no monthly floor).

**Migration sketch (when the time comes).**
1. Create bucket + S3 credentials at the provider.
2. Update `.env` only: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`,
   `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT=true` (keep `STORAGE_DRIVER=s3`).
   - R2: region `auto`; iDrive e2: region `e2`; MEGA S4: endpoint/region from the S4 console.
3. Copy existing objects with `rclone`/`aws s3 sync` (no downtime).
4. Validate `media.upload` → `media.download` (open the signed URL) → `message.send`,
   then `php artisan config:clear` and flip the env.

**Deletion/retention note.** Avoid deleting only the blob while keeping the `media` row:
`DownloadMedia` is idempotent by `storage_key`, so it would return a broken signed URL
instead of re-fetching. If pruning is ever needed, delete row + blob together (and prefer
a bucket lifecycle rule). Keep documents; prune old audio/images only. Provider media
links expire, so deleted originals are unrecoverable.
