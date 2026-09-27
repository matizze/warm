# WARM — Seu WhatsApp com memória, consultado pela IA

O **WARM** conecta **uma** conta de WhatsApp a um assistente de IA. Tudo o que
chega e sai do WhatsApp (texto, áudio, imagem, vídeo, documento) é guardado em um
banco de dados próprio. A IA então pode **pesquisar** esse conteúdo e **enviar**
mensagens, usando o seu WhatsApp como fonte de contexto.

Pense assim: o WARM é um **arquivo vivo do seu WhatsApp** que a IA consegue ler e
escrever. Em vez de você procurar uma conversa antiga rolando a tela, você
pergunta à IA e ela responde com base no que foi conversado.

> **Uma instalação = um WhatsApp.** Cada cópia do WARM cuida de um único número.
> Se você quiser usar em outro número, faz uma nova instalação independente.

---

## Índice

1. [O que o WARM faz](#o-que-o-warm-faz)
2. [Como funciona (visão simples)](#como-funciona-visão-simples)
3. [O que você vai precisar](#o-que-você-vai-precisar)
4. [Instalação passo a passo (no seu computador)](#instalação-passo-a-passo-no-seu-computador)
5. [Configuração (arquivo `.env`)](#configuração-arquivo-env)
6. [Conectar o WhatsApp (Evolution)](#conectar-o-whatsapp-evolution)
7. [Ligar o WARM (os 4 processos)](#ligar-o-warm-os-4-processos)
8. [Conectar a IA (MCP)](#conectar-a-ia-mcp)
9. [O que você pode pedir para a IA](#o-que-você-pode-pedir-para-a-ia)
10. [Áudio, imagem e PDF (processamento)](#áudio-imagem-e-pdf-processamento)
11. [Hospedar em produção](#hospedar-em-produção)
12. [Segurança e privacidade](#segurança-e-privacidade)
13. [Manutenção do dia a dia](#manutenção-do-dia-a-dia)
14. [Problemas comuns](#problemas-comuns)
15. [Apêndice técnico](#apêndice-técnico)

---

## O que o WARM faz

- **Guarda** todas as mensagens do seu WhatsApp, com data, remetente e conversa.
- **Entende mídia**: transcreve áudio, descreve imagens (e lê o texto dentro
  delas) e extrai o texto de PDFs/documentos.
- **Pesquisa inteligente**: encontra mensagens por palavra **e** por significado
  — por exemplo, procurar "assuntos de dinheiro" acha uma conversa sobre "boleto".
- **Envia mensagens** a seu pedido, por texto ou mídia.
- **Fala com a IA** por um padrão aberto chamado **MCP** (o mesmo usado por
  Claude, Cursor, opencode etc.), então qualquer assistente compatível consegue
  usar o WARM.

## Como funciona (visão simples)

```
                          ┌──────────────┐
                          │   WhatsApp   │
                          └──────┬───────┘
              ┌──────────────────┴───────────────────┐
              │                                      │
         RECEBER                                  ENVIAR
              │                                      │
   ┌──────────▼──────────┐               ┌───────────▼──────────┐
   │  "Porteiro" (webhook)│               │   Sua IA (assistente) │
   │  recebe e organiza   │               │   pede: "mande X"     │
   └──────────┬──────────┘               └───────────┬──────────┘
              │                                      │
   ┌──────────▼──────────┐               ┌───────────▼──────────┐
   │  Banco de dados      │               │  WARM envia pelo      │
   │  (mensagens, mídia)  │◄──────────────┤  WhatsApp e confirma  │
   └─────────────────────┘   CONSULTAR   └──────────────────────┘
```

Três caminhos:

1. **Recebimento** — WhatsApp → WARM → banco de dados.
2. **Consulta** — IA → WARM → banco de dados (respostas).
3. **Envio** — IA → WARM → WhatsApp (com confirmação).

## O que você vai precisar

| Peça | Para que serve | Observação |
|---|---|---|
| **PHP 8.2+** | rodar o WARM | testado no PHP 8.4. Extensões `pdo_pgsql` e `mbstring`. |
| **Composer** | instalar as dependências do projeto | ferramenta padrão do PHP. |
| **PostgreSQL com a extensão `pgvector`** | guardar mensagens e a "memória semântica" | ex.: Docker `pgvector/pgvector:pg16`, Neon ou Supabase. |
| **Um armazenamento de arquivos S3** | guardar as mídias (áudios, imagens, PDFs) | RustFS, MinIO ou Amazon S3. Para testes, dá para usar disco local. |
| **Uma conta no Evolution (ou provedor compatível)** | conectar o número de WhatsApp | Z-API e WhatsMeow já estão previstos para o futuro. |
| **Uma chave da OpenAI** | transcrever áudio, descrever imagem e busca por significado | opcional, mas sem ela a "memória inteligente" fica limitada. |
| **`pdftotext`** | extrair texto de PDFs | vem com o pacote *poppler*. |

> Você **não precisa** saber programar para colocar no ar seguindo este guia. Todos
> os comandos são para copiar e colar.

---

## Instalação passo a passo (no seu computador)

### 1. Baixar e instalar as dependências

```bash
git clone <ENDERECO_DO_REPOSITORIO> warm
cd warm
composer install
```

### 2. Criar o arquivo de configuração

```bash
cp .env.example .env
php artisan key:generate
```

O arquivo `.env` é onde ficam as senhas e chaves. Ele **nunca** deve ser enviado
para o Git nem compartilhado.

### 3. Subir um banco de dados PostgreSQL com pgvector

Se você tem Docker, o caminho mais fácil é:

```bash
docker run -d --name warm-db \
  -e POSTGRES_DB=warm -e POSTGRES_USER=warm -e POSTGRES_PASSWORD=warm_pass \
  -p 5433:5432 pgvector/pgvector:pg16
```

Depois crie o banco que o WARM vai usar:

```bash
createdb -h 127.0.0.1 -p 5433 -U warm warm_laravel
```

### 4. Preparar o armazenamento das mídias

Com **RustFS/MinIO** (recomendado: Herd `RustFS` ou o MinIO do Docker), crie o
"balde" (bucket) onde os arquivos ficam guardados. Exemplo com o cliente `mc`:

```bash
mc alias set warmfs http://localhost:9000 herd secretkey
mc mb --ignore-existing warmfs/warm-media
```

Sem S3? Use disco local no `.env` (`STORAGE_DRIVER=local`) — bom só para testes.

### 5. Criar as tabelas no banco

```bash
php artisan migrate
```

### 6. Preencher as chaves no `.env`

Veja a seção [Configuração](#configuração-arquivo-env) abaixo. O mínimo é:
banco, storage, Evolution (URL, chave, instância) e `WEBHOOK_TOKEN`.

Pronto. Agora siga para **Conectar o WhatsApp** e **Ligar o WARM**.

---

## Configuração (arquivo `.env`)

O `.env` tem blocos. Abaixo, o que cada variável faz em linguagem simples.

### Aplicação
| Variável | O que é |
|---|---|
| `APP_NAME` | nome do sistema (aparece em alguns lugares). |
| `APP_ENV` | ambiente: `local` para testes, `production` no servidor. |
| `APP_DEBUG` | em produção, deixe `false` (evita mostrar detalhes de erro para estranhos). |
| `APP_URL` | endereço onde o WARM vai rodar (ex.: `https://warm.suaempresa.com`). |

### Banco de dados
| Variável | O que é |
|---|---|
| `DB_CONNECTION` | tipo de banco. Use `pgsql`. |
| `DB_HOST` / `DB_PORT` | onde o banco está (ex.: `127.0.0.1` / `5433`). |
| `DB_DATABASE` | nome do banco (ex.: `warm_laravel`). |
| `DB_USERNAME` / `DB_PASSWORD` | usuário e senha do banco. |

### Fila e agendador
| Variável | O que é |
|---|---|
| `QUEUE_CONNECTION` | onde ficam as tarefas em fila. `database` já basta. |
| `DB_QUEUE_RETRY_AFTER` | tempo que uma tarefa pode rodar antes de ser reenviada. Deve ser **maior** que o tempo dos downloads/processamentos (300s). |

### Storage (arquivos)
| Variável | O que é |
|---|---|
| `STORAGE_DRIVER` | `s3` (S3/RustFS/MinIO) ou `local` (apenas testes). |
| `STORAGE_LOCAL_PATH` | pasta usada quando o driver é `local`. |
| `AWS_*` | endereço, região, bucket e chaves do seu S3. |
| `MEDIA_SIGNED_URL_TTL` | por quanto tempo (segundos) um link de download continua válido. |

### Provedor de WhatsApp (Evolution)
| Variável | O que é |
|---|---|
| `WHATSAPP_PROVIDER` | qual provedor usar. Hoje: `evolution`. |
| `EVOLUTION_URL` | endereço do seu servidor Evolution. |
| `EVOLUTION_API_KEY` | chave da sua conta Evolution. |
| `EVOLUTION_INSTANCE_ID` | **nome exato** da instância (o número/conta). Se tiver espaço, use aspas: `"Minha Instância"`. |

### Segurança do webhook e admin
| Variável | O que é |
|---|---|
| `WEBHOOK_TOKEN` | uma senha que o Evolution usa para provar que o aviso é legítimo. Sem ela configurada, só passa em ambiente de teste. **Use um valor aleatório longo em produção.** |
| `ADMIN_TOKEN` | senha para disparar a reindexação por HTTP. Se vazio, esse endereço fica desativado. |

### Áudio, imagem e PDF (processamento por IA)
| Variável | O que é |
|---|---|
| `PROCESSING_ENABLED` | liga/desliga transcrição, descrição de imagem e extração de PDF. |
| `OPENAI_API_KEY` | sua chave da OpenAI (dá custo por uso). |
| `OPENAI_BASE_URL` | endereço da OpenAI (troque se usar um serviço compatível). |
| `TRANSCRIPTION_MODEL` | modelo de transcrição de áudio (padrão `whisper-1`). |
| `VISION_MODEL` | modelo que descreve imagens (padrão `gpt-4o-mini`). |

### Busca por significado (embeddings)
| Variável | O que é |
|---|---|
| `EMBEDDING_ENABLED` | liga a busca "por significado" (usa a OpenAI). |
| `EMBEDDING_MODEL` | modelo que gera a "impressão digital" do texto. |
| `EMBEDDING_DIMENSIONS` | tamanho dessa impressão digital. **Mudar isso exige migração de banco.** |

### Reindexação agendada
| Variável | O que é |
|---|---|
| `REINDEX_ENABLED` | liga a reindexação automática. |
| `REINDEX_CRON` | quando rodar (formato cron; `"0 3 * * *"` = todo dia às 3h). |
| `REINDEX_TZ` | fuso horário (ex.: `America/Sao_Paulo`). |

---

## Conectar o WhatsApp (Evolution)

1. No painel do Evolution, crie/abra a **instância** do número que o WARM vai
   usar e **conecte o WhatsApp** (leitura do QR Code).
2. Copie o **endereço do servidor**, a **chave de API** e o **nome da instância**
   e coloque no `.env` (`EVOLUTION_URL`, `EVOLUTION_API_KEY`,
   `EVOLUTION_INSTANCE_ID`).
3. Gere um `WEBHOOK_TOKEN` aleatório:

   ```bash
   php -r "echo bin2hex(random_bytes(16)).PHP_EOL;"
   ```

   Copie o resultado para `WEBHOOK_TOKEN` no `.env`.

### Apontar o webhook para o WARM

O Evolution precisa **avisar** o WARM quando chega mensagem. Para isso ele chama
o endereço `/webhook` do WARM.

**Em produção** (o WARM tem um endereço público, ex. `https://warm.suaempresa.com`):

```bash
curl -X POST "https://SEU-EVOLUTION/instance/fetchInstances" \
  -H "apikey: SUA_CHAVE_EVOLUTION"        # confira o nome da instância

curl -X POST "https://SEU-EVOLUTION/webhook/set/NOME_DA_INSTANCIA" \
  -H "apikey: SUA_CHAVE_EVOLUTION" \
  -H "Content-Type: application/json" \
  -d '{
    "webhook": {
      "enabled": true,
      "url": "https://warm.suaempresa.com/webhook?token=SEU_WEBHOOK_TOKEN",
      "byEvents": false,
      "events": ["MESSAGES_UPSERT", "MESSAGES_UPDATE", "SEND_MESSAGE"]
    }
  }'
```

Se o nome da instância tiver espaços, troque por `%20` na URL
(ex.: `Matizze%20Suporte`).

**Enquanto testa no seu computador**, o Evolution (que fica na internet) não
consegue alcançar o seu `localhost`. Use um túnel para criar um endereço público
temporário:

```bash
# terminal 1
php artisan serve --host=127.0.0.1 --port=8000

# terminal 2 (cria um link https temporário)
cloudflared tunnel --url http://localhost:8000
```

O túnel mostra um endereço tipo `https://algo-aleatorio.trycloudflare.com`. Use
esse endereço no `url` do webhook acima (com `/webhook?token=...`). Esse link
muda cada vez que o túnel reinicia — reaponte o webhook.

> O túnel só serve para o **webhook**. A conexão da IA (MCP) **não** precisa dele.

---

## Ligar o WARM (os 4 processos)

O WARM funciona com processos separados. Em produção, cada um roda de forma
contínua (veja [Hospedagem](#hospedar-em-produção)).

| Processo | Comando | Para que serve |
|---|---|---|
| **Servidor web** | `php artisan serve` | recebe o webhook e responde `/health`. |
| **Fila (worker)** | `php artisan queue:work` | baixa mídias e gera as buscas em segundo plano. |
| **Agendador** | `php artisan schedule:work` | dispara a reindexação diária. |
| **MCP (IA)** | `php artisan mcp:serve` | conecta a IA ao WARM (é iniciado pela própria IA). |

Para desenvolvimento, há um atalho que sobe servidor + fila + agendador juntos
(precisa de Node/npx):

```bash
composer dev
```

Verifique se está no ar:

```bash
curl http://localhost:8000/health
# {"status":"ok"}
```

---

## Conectar a IA (MCP)

O WARM expõe a IA por **MCP** (Model Context Protocol). Ele roda por "stdio",
ou seja, a própria IA **inicia** o comando `php artisan mcp:serve` no seu
computador. Não precisa de senha nem de internet para o MCP.

- **Claude Code** — o arquivo `.mcp.json` já contém o servidor `warm`. Ao abrir o
  projeto, aprove o servidor quando pedido, ou rode:
  ```bash
  claude mcp add warm -- php artisan mcp:serve
  claude mcp list    # deve aparecer "warm"
  ```
- **opencode** — já configurado em `opencode.json`.
- **Outros clientes** — adicione um servidor MCP do tipo *stdio* com o comando
  `php artisan mcp:serve` e o projeto como diretório de trabalho.

Depois de conectado, é só conversar normalmente com a IA.

## O que você pode pedir para a IA

A IA tem 9 "ferramentas" do WARM. Em linguagem simples:

| Ferramenta | O que faz | Exemplo de pedido |
|---|---|---|
| `message.search` | pesquisa mensagens | "O que foi falado sobre o contrato do João?" |
| `conversation.search` | encontra conversas | "Ache a conversa com o cliente XPTO" |
| `contact.search` / `contact.get` | procura contatos e contexto | "Me lembre quem é a Ana" |
| `group.search` / `group.get` | procura grupos e contexto | "Qual o assunto do grupo de implantação?" |
| `message.send` | envia mensagem | "Manda pro João: reunião amanhã às 10h" |
| `media.download` | baixa a mídia de uma mensagem | "Baixe o áudio daquela mensagem" |
| `media.upload` | adiciona uma mídia para enviar depois | "Prepare essa foto para enviar" |

A IA **não** mexe direto nas tabelas nem vê credenciais: ela só usa essas
capacidades.

## Áudio, imagem e PDF (processamento)

Quando `PROCESSING_ENABLED=true` e há `OPENAI_API_KEY`:

- **Áudio** → transcrito (Whisper).
- **Imagem** → descrita e tem o texto visível extraído (OCR).
- **PDF/documento com texto** → conteúdo extraído.

Esse conteúdo entra na busca, então você acha um áudio ou uma foto pelo que ele
**diz**, não pelo nome do arquivo. Se o processamento falhar, o sistema registra
o erro e **nunca inventa** conteúdo.

## Busca: palavras × significado

A IA pode pesquisar de três formas (por padrão, uma mistura das duas):

- **Palavras (lexical)** — acha o termo exato.
- **Significado (semântica)** — acha pelo sentido, mesmo com palavras diferentes.
- **Híbrida** — combina as duas (recomendado).

Sem a chave da OpenAI, a busca continua funcionando **por palavras**.

---

## Hospedar em produção

Você tem dois caminhos. Escolha um.

### Opção A — Laravel Cloud (mais simples)

O **Laravel Cloud** é o serviço oficial para hospedar aplicações Laravel. Ele
cuida de servidor, HTTPS e dos processos contínuos.

1. Crie uma conta em <https://cloud.laravel.com> e conecte seu repositório.
2. Configure as variáveis de ambiente (as mesmas do `.env`), **em especial**:
   `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, banco, storage S3 e chaves.
3. Crie um **worker** de fila (`queue:work`) e habilite o **scheduler**
   (`schedule:work`).
4. **Atenção ao banco:** a busca por significado precisa da extensão `pgvector`.
   Se o banco do provedor não oferecer, use um Postgres que suporte (Neon,
   Supabase, Amazon RDS com pgvector) ou um Postgres próprio.
5. Aponte o webhook do Evolution para `https://SEU-ENDEREÇO/webhook?token=...`.

### Opção B — Servidor próprio (VPS)

Um servidor Linux comum (2 vCPU / 2 GB já atende bem no começo).

1. **Instale** PHP 8.2+, Composer, Nginx, PostgreSQL com pgvector e `pdftotext`.
2. **Publique o código** no servidor e rode `composer install --no-dev --optimize-autoloader`.
3. **Configure o `.env`** de produção (nunca versione esse arquivo).
4. **Prepare a aplicação:**
   ```bash
   php artisan key:generate
   php artisan migrate --force
   php artisan optimize          # deixa a aplicação mais rápida
   ```
5. **Nginx + PHP-FPM** apontando para a pasta `public/` do projeto.
6. **HTTPS** com Let's Encrypt (`certbot`) — obrigatório em produção.
7. **Fila** sempre rodando (via Supervisor, reinicia se cair):
   ```ini
   [program:warm-queue]
   command=php /var/www/warm/artisan queue:work --tries=3 --timeout=320
   autostart=true
   autorestart=true
   numprocs=1
   ```
8. **Agendador** no `cron` (uma linha):
   ```
   * * * * * cd /var/www/warm && php artisan schedule:run >> /dev/null 2>&1
   ```
9. **Storage** em S3/RustFS/MinIO (`STORAGE_DRIVER=s3`).
10. **Webhook** apontando para `https://seu-dominio/webhook?token=...`.

> **Resumo do que precisa ficar ligado em produção:** servidor web (Nginx/PHP),
> worker da fila, agendador (cron) e o banco. O MCP roda onde a IA roda.

---

## Segurança e privacidade

- **Credenciais** (banco, S3, Evolution, OpenAI) ficam só no `.env`/secret manager
  — nunca no código, nos logs, nas respostas da IA ou em documentos.
- **Use `WEBHOOK_TOKEN` forte**; ele prova que o aviso veio do seu Evolution.
- **HTTPS sempre** em produção.
- **Rotacione chaves** periodicamente e imediatamente se vazarem.
- **LGPD**: o WARM guarda dados pessoais de conversas. Restrinja o acesso,
  proteja o servidor e o banco, e permita exclusão de dados quando solicitado.
- Prefira **minimizar** o que é guardado/processado, e mantenha backups
  protegidos.

## Manutenção do dia a dia

- **Reindexar a busca** (recalcular a "memória de significado"):
  ```bash
  php artisan warm:reindex            # completa o que falta
  php artisan warm:reindex --force    # recalcula tudo
  php artisan warm:reindex --dry-run  # só simula, não grava
  ```
  Se você mudar `EMBEDDING_DIMENSIONS`, o comando **avisa e para**; ajustar o
  banco é uma decisão separada e manual (proposital). Sem a chave da OpenAI, ele
  faz a indexação apenas por palavras.
- **Backups**: faça cópia do banco de dados e do bucket de mídias regularmente.
- **Logs**: ficam em `storage/logs/`. Acompanhe com `php artisan pail`.
- **Atualizações**: rode `composer install`, `php artisan migrate --force` e
  `php artisan optimize` de novo.

## Problemas comuns

| Sintoma | Causa provável | Solução |
|---|---|---|
| Mensagens não chegam | webhook não aponta para o WARM | reconfigure o webhook no Evolution (endereço pode ter mudado, ex.: túnel reiniciado). |
| Webhook responde 401 | `WEBHOOK_TOKEN` diferente do que está na URL | alinhe o token no `.env` e na URL do webhook. |
| Mídia não é baixada | worker da fila desligado | rode `php artisan queue:work`. |
| Erro "Vite manifest" | front-end do Laravel não compilado | rode `npm install && npm run build` (ou ignore se não usa o front). |
| Busca só acha por palavra | `OPENAI_API_KEY` ausente ou `EMBEDDING_ENABLED=false` | configure a chave e reindexe. |
| "pgvector" não existe | banco sem a extensão `vector` | use um Postgres com pgvector (Docker/Neon/Supabase/RDS). |
| Espaços no nome da instância dão erro | valor sem aspas no `.env` | coloque entre aspas: `EVOLUTION_INSTANCE_ID="Minha Instância"`. |

---

## Apêndice técnico

Para quem for mexer no código.

### Tecnologia
- **Laravel 12** (PHP 8.4), **PostgreSQL + pgvector**, **S3** para mídia.
- Única biblioteca de aplicação: [`lorisleiva/laravel-actions`](https://laravelactions.com).
  O adapter S3 oficial (`league/flysystem-aws-s3-v3`) é usado só como driver de disco.
- Qualidade: `larastan` (PHPStan nível 3), `pint`, `laravel/pao`, `laravel/boost`,
  `laravel-ide-helper`.

### Endpoints
| Método | Rota | Descrição |
|---|---|---|
| POST | `/webhook` | recebe eventos do provedor (auth: `x-webhook-token` ou `?token=`). |
| GET | `/health` | `{"status":"ok"}`. |
| POST | `/admin/reindex` | dispara a reindexação (auth: `x-admin-token`; 404 se `ADMIN_TOKEN` vazio). |

### Estrutura de pastas
```
app/
├── Actions/        regras de negócio (webhook, envio, busca, mídia, reindex)
├── Contracts/      porta WhatsAppProvider
├── Data/           contratos internos (IncomingMessage, OutgoingMessage...)
├── Enums/          tipos (direção, status, tipo de mensagem...)
├── Integrations/   normalização e provider do Evolution
├── Mcp/            servidor MCP (JSON-RPC sobre stdio)
├── Models/         Contact, Group, Conversation, Message, Media
├── Processing/     PDF, áudio, imagem
├── Embeddings/     geração de vetores
└── Support/        acesso ao storage de mídia
config/warm.php     todas as configurações do WARM
database/migrations schema (inclui a extensão vector e índices)
tests/              testes automatizados
```

### Mapeamento TypeScript → Laravel (histórico)
O projeto foi originalmente escrito em TypeScript e reescrito em Laravel 12.

| TS (original) | Laravel 12 |
|---|---|
| `domain/entities/*` | `app/Models/*` (Eloquent) |
| `domain/services/query-service.ts` | Eloquent (sem camada de mappers) |
| `domain/services/whatsapp-service.ts` | `App\Contracts\WhatsAppProvider` |
| `adapters/providers/evolution-*` | `App\Integrations\Evolution\*` |
| `application/use-cases/*` | `app/Actions/*` (laravel-actions) |
| `application/media/*` | `App\Actions\Media\{Download,Upload,Process}Media` |
| `application/search/*` | `App\Actions\Messages\SearchMessages` (RRF k=60) |
| `infrastructure/mcp/server.ts` | `app/Mcp/*` + `mcp:serve` |
| `webhook/handler.ts` | `App\Actions\Webhook\ReceiveWebhook` |
| `scripts/reindex.ts` | `App\Actions\Search\ReindexMessages` (`warm:reindex`) |
| `node-cron` | `routes/console.php` + `schedule:work` |

### Comandos de desenvolvimento
```bash
composer dev          # servidor + fila + agendador
composer mcp          # MCP (stdio)
composer reindex      # reindexação
composer analyse      # análise estática (PHPStan/Larastan)
composer ide-helper   # regenera os arquivos de autocompletar da IDE
composer test         # testes
./vendor/bin/pint     # formatação de código
```

### Banco de dados
`contacts`, `groups`, `conversations`, `messages`, `media` — sem `instance_id`
(o banco **é** a instância). `messages` guarda `search_text` (busca por palavras)
e `embedding vector(1536)` com índice HNSW (busca por significado).
