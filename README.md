# WARM — Seu WhatsApp com memória, consultado pela IA

O **WARM** conecta **uma ou mais** contas de WhatsApp a um assistente de IA.
Tudo o que chega e sai do WhatsApp (texto, áudio, imagem, vídeo, documento) é
guardado em um banco de dados próprio. A IA então pode **pesquisar** esse
conteúdo e **enviar** mensagens, usando os seus WhatsApp como fonte de contexto.

Pense assim: o WARM é um **arquivo vivo dos seus WhatsApp** que a IA consegue ler
e escrever. Em vez de você procurar uma conversa antiga rolando a tela, você
pergunta à IA e ela responde com base no que foi conversado.

> **Uma instalação = vários números.** Cada número de WhatsApp é uma
> **instância**. A mesma stack do WARM atende todos, com webhook e MCP por
> instância — veja [Múltiplas instâncias](#múltiplas-instâncias-vários-números).

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
11. [Múltiplas instâncias (vários números)](#múltiplas-instâncias-vários-números)
12. [Hospedar em produção](#hospedar-em-produção)
13. [Segurança e privacidade](#segurança-e-privacidade)
14. [Manutenção do dia a dia](#manutenção-do-dia-a-dia)
15. [Problemas comuns](#problemas-comuns)
16. [Apêndice técnico](#apêndice-técnico)

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
| `EVOLUTION_INSTANCE_ID` | nome da instância (o número/conta). **Só é usado na primeira migração**, para cadastrar a instância default na tabela `instances`. Depois disso, cada número é cadastrado na tabela — veja [Múltiplas instâncias](#múltiplas-instâncias-vários-números). Use um **slug** (sem espaços): ex. `henriquecosta`. |

### Segurança do webhook e admin
| Variável | O que é |
|---|---|
| `WEBHOOK_TOKEN` | uma senha que o Evolution usa para provar que o aviso é legítimo. Sem ela configurada, só passa em ambiente de teste. **Use um valor aleatório longo em produção.** É o token das rotas legadas e o token inicial (default) na primeira migração. |
| `ADMIN_TOKEN` | senha para disparar a reindexação por HTTP. Se vazio, esse endereço fica desativado. |
| `MCP_TOKEN` | bearer da rota MCP **legada** (`POST /mcp`). Vazio desativa essa rota (404). Cada instância tem o seu próprio `mcp_token` na tabela `instances`. |

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
   `EVOLUTION_INSTANCE_ID`). Esse nome será o `instance_id` da instância — nomeie
   o número como um **slug** (sem espaços nem acentos), ex. `henriquecosta`.
3. Gere um `WEBHOOK_TOKEN` aleatório:

   ```bash
   php -r "echo bin2hex(random_bytes(16)).PHP_EOL;"
   ```

   Copie o resultado para `WEBHOOK_TOKEN` no `.env`.

### Apontar o webhook para o WARM

O Evolution precisa **avisar** o WARM quando chega mensagem. Cada número chama a
rota `/instance/{instance_id}/webhook` do WARM, com o **token daquela** instância
(não o `/webhook` global — esse fica só para a instância default).

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
      "url": "https://warm.suaempresa.com/instance/NOME_DA_INSTANCIA/webhook?token=SEU_WEBHOOK_TOKEN",
      "byEvents": false,
      "events": ["MESSAGES_UPSERT", "MESSAGES_UPDATE", "SEND_MESSAGE"]
    }
  }'
```

Numere as instâncias do Evolution como **slugs** (ex. `henriquecosta`), para o
`{instance_id}` do caminho ficar URL-safe. Instâncias antigas com espaço
continuam funcionando com `%20` (ex. `Matizze%20Suporte`), mas não use isso em
números novos — veja
[convenção de slug](#convenção-de-slug-do-instance_id).

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

### MCP por HTTP (por instância)

Em produção o WARM também expõe o MCP por **HTTP**, um endpoint por instância:

```
POST https://warm.suaempresa.com/instance/{instance_id}/mcp
Authorization: Bearer MCP_TOKEN_DA_INSTANCIA
```

O bearer é o `mcp_token` da instância na tabela `instances`. As ferramentas de
**mensagem** (`message.search`, `conversation.search`, `message.send`) operam no
contexto daquela instância: só enxergam e enviam pelo número correspondente.

Escopo do que **não** é por instância (igual ao registrado em `DECISIONS`):

- `contact.*` e `group.*` são **globais** — contatos e grupos são compartilhados
  entre os números. A escrita (`contact.upsert`) respeita o número que a chamou:
  cria a conversa só naquela instância e recusa alterar um contato que já pertence
  a outra instância. A base de contatos em si continua única e compartilhada; a
  leitura por `contact.search`/`contact.get` também continua global.
- `media.download` recebe só o `messageId` e **não** é escopado à instância; use
  um id vindo de uma busca já escopada (`message.search`).

> **Agenda do aparelho.** `contact.upsert` grava na base de contatos do WARM, não
> na agenda do celular: a API do WhatsApp/Evolution não expõe escrita de contato
> no aparelho. O contato fica disponível para busca, contexto e envio pelo WARM.

É assim que um workspace (ex.: Multica) cadastra uma entrada MCP por número. A
rota legada `POST /mcp` usa o `MCP_TOKEN` do `.env` e atende a instância default.

Depois de conectado, é só conversar normalmente com a IA.

## O que você pode pedir para a IA

A IA tem 10 "ferramentas" do WARM. Em linguagem simples:

| Ferramenta | O que faz | Exemplo de pedido |
|---|---|---|
| `message.search` | pesquisa mensagens | "O que foi falado sobre o contrato do João?" |
| `conversation.search` | encontra conversas | "Ache a conversa com o cliente XPTO" |
| `contact.search` / `contact.get` | procura contatos e contexto | "Me lembre quem é a Ana" |
| `contact.upsert` | cria ou atualiza um contato (telefone + nome/notas) | "Salve o João, +55 11 99999-0000, cliente do plano anual" |
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

## Múltiplas instâncias (vários números)

O WARM atende **vários números de WhatsApp** na mesma instalação. Cada número é
uma **instância**: um registro na tabela `instances` com as credenciais do
Evolution e os tokens próprios daquele número.

### Cadastro das instâncias (tabela, não só `.env`)

| Campo | O que é |
|---|---|
| `instance_id` | identificador da instância. É também o **nome da instância no Evolution** e aparece na URL. Chave primária. |
| `evolution_url` | endereço do servidor Evolution daquele número. |
| `api_key` | chave de API do Evolution. |
| `webhook_token` | token que valida o webhook **daquela** instância. |
| `mcp_token` | bearer que valida o MCP **daquela** instância. Vazio desativa o MCP dela (401). |
| `is_default` | a instância usada pelas rotas legadas `/webhook` e `/mcp`. |
| `active` | instância inativa responde **404** (nunca 403). |

Na primeira migração, a instância que estava no `.env`
(`EVOLUTION_INSTANCE_ID`) é cadastrada automaticamente como **default**, e
conversas/mensagens antigas são ligadas a ela — o upgrade não exige ação do
operador. Dali em diante, números novos são cadastrados direto na tabela
`instances`.

Regra de isolamento: `conversations` e `messages` guardam `instance_id`, e a
unicidade de conversa é `(contact_id, instance_id)` / `(group_id, instance_id)` —
o mesmo contato pode existir em números diferentes, sem se misturar.

### Convenção de slug do `instance_id`

O `instance_id` vai no **caminho da URL** e também é o **nome da instância no
Evolution**, então precisa ser **URL-safe**.

**Norma (obrigatória para instâncias novas).** Sem espaços, acentos, maiúsculas
nem caracteres que exijam escape na URL. Exemplos válidos: `henriquecosta`,
`matizze-suporte`, `comercial`.

**Recomendação.** Um slug no formato `^[a-z0-9][a-z0-9_-]*$` — letras minúsculas,
dígitos, hífen e sublinhado. Isso **não** é validado em código nem no schema (a
coluna `instance_id` é uma `string`), então a disciplina é do operador ao criar a
instância no Evolution.

> **Legado.** A instância `Matizze Suporte` (com espaço) segue funcionando — o
> espaço vira `%20` na URL (ex.: `/instance/Matizze%20Suporte/webhook`). É
> suportado, porém **frágil**, e não deve servir de modelo: instâncias novas
> devem ser criadas no Evolution já com nome de slug.

### Webhook por instância

Aponte o webhook de **cada número** para a rota da sua instância, com o token
daquela instância (veja [Conectar o WhatsApp](#apontar-o-webhook-para-o-warm)):

```
https://warm.suaempresa.com/instance/{instance_id}/webhook?token=WEBHOOK_TOKEN_DA_INSTANCIA
```

- Token ausente/errado → `401`.
- Instância inexistente ou inativa → `404`.

O envio também é por instância: a resposta sai pelo número da conversa, não por
uma instância fixa de ambiente.

### MCP por instância

Cada instância tem endpoint e bearer próprios (veja
[MCP por HTTP](#mcp-por-http-por-instância)):

```
POST https://warm.suaempresa.com/instance/{instance_id}/mcp
Authorization: Bearer MCP_TOKEN_DA_INSTANCIA
```

### Rotas legadas (número único)

`POST /webhook` e `POST /mcp` continuam existindo e atendem a instância
**default** (autenticadas pelos tokens de ambiente `WEBHOOK_TOKEN`/`MCP_TOKEN`).
Servem instalações de número único e ficam disponíveis durante a transição.

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
5. Aponte o webhook de cada número do Evolution para
   `https://SEU-ENDEREÇO/instance/{instance_id}/webhook?token=...` (uma instância
   por número — veja [Múltiplas instâncias](#múltiplas-instâncias-vários-números)).

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
10. **Webhook** de cada número apontando para
    `https://seu-dominio/instance/{instance_id}/webhook?token=...`.

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

### Rollback de versão

A stack roda uma imagem pinada (`APP_IMAGE` no Portainer). Depois do rollout
multi-instância, **repin da imagem sozinho NÃO é suficiente**: as colunas
`conversations.instance_id` e `messages.instance_id` são `NOT NULL` **sem
default** (migração `2026_10_16_120001`). A imagem pré-multi-instância não
conhece a coluna e insere sem ela, então todo webhook de entrada falha com
`not-null violation` — a ingestão quebra em silêncio. O rollback do schema é
**obrigatório**, e tem que rodar **antes** de a imagem antiga atender.

Passos (em janela de manutenção, com a ingestão parada):

1. **Backup** do banco antes de qualquer coisa (`pg_dump`).
2. **Pré-checagem de duplicados.** O `down` recria `unique(contact_id)` e
   `unique(group_id)`; se o mesmo contato/grupo já tem conversas em 2+ instâncias,
   o índice não cria e o `migrate:rollback` falha (o `down` é atômico).
   ```sql
   SELECT contact_id, count(*) FROM conversations
    WHERE contact_id IS NOT NULL GROUP BY contact_id HAVING count(*) > 1;
   SELECT group_id, count(*) FROM conversations
    WHERE group_id IS NOT NULL GROUP BY group_id HAVING count(*) > 1;
   ```
   Se retornarem linhas, trate-as antes (ex.: manter a conversa da instância
   default) — ou use o `rollback-multi-instance.sql` do passo 3, que mantém a da
   default, move as mensagens das demais para ela e apaga as conversas
   esvaziadas.
3. **Reverter o schema com a imagem ATUAL (nova).** É a única imagem que conhece
   as duas migrações; rodar o `migrate:rollback` pela imagem antiga reverteria o
   batch errado. Dentro de um container da imagem atual:
   ```bash
   php artisan migrate:rollback --step=2 --force
   ```
   `--step=2` reverte exatamente as duas migrações de multi-instância — confirme
   em `php artisan migrate:status` que elas são o último batch.
   Alternativa revisável (mesmo efeito, com dedup e limpeza do `migrations`):
   ```bash
   psql "$DATABASE_URL" -f deployment/rollback-multi-instance.sql
   ```
   **Dados do multi-instância são perdidos** neste passo: a atribuição por
   instância (`instance_id`) some e, no caminho do SQL, as conversas duplicadas
   são mescladas — daí o backup do passo 1.
4. **Repin da imagem:** aponte `APP_IMAGE` para o digest anterior conhecido
   (o digest pré-multi-instância usado no rollout foi
   `main@sha256:e5fa1d15…`) e redeploy da stack.
5. **Validar:** `/up` (ou `/health`) responde 200; a rota legada `/webhook`
   autentica com o `WEBHOOK_TOKEN` do `.env`; e uma mensagem de entrada é
   persistida sem erro (a imagem antiga insere sem `instance_id`).

> **Ordem importa.** Pare a ingestão, reverta o schema pela imagem nova e só então
> suba a imagem antiga. Reverter só a imagem (sem o schema) quebra a ingestão;
> reverter o schema sem parar a ingestão pode perder mensagens do intervalo.
> Reverter o schema é o passo destrutivo; reverter a imagem é reversível.

## Problemas comuns

| Sintoma | Causa provável | Solução |
|---|---|---|
| Mensagens não chegam | webhook não aponta para o WARM | reconfigure o webhook no Evolution (endereço pode ter mudado, ex.: túnel reiniciado). |
| Webhook responde 401 | token da URL diferente do da instância (ou do `WEBHOOK_TOKEN`, na rota legada) | use o `webhook_token` da instância certa; na rota legada, alinhe com o `.env`. |
| Webhook/MCP responde 404 | `instance_id` do caminho não existe ou está inativa | confira o `instance_id` na tabela `instances` e o campo `active`. |
| Mídia não é baixada | worker da fila desligado | rode `php artisan queue:work`. |
| Erro "Vite manifest" | front-end do Laravel não compilado | rode `npm install && npm run build` (ou ignore se não usa o front). |
| Busca só acha por palavra | `OPENAI_API_KEY` ausente ou `EMBEDDING_ENABLED=false` | configure a chave e reindexe. |
| "pgvector" não existe | banco sem a extensão `vector` | use um Postgres com pgvector (Docker/Neon/Supabase/RDS). |
| Nome de instância com espaço vira `%20` na URL | `instance_id` não é um slug | crie a instância no Evolution com nome de slug (`a-z0-9-_`); veja a convenção de slug. |

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
| POST | `/instance/{id}/webhook` | recebe eventos da instância `{id}` (auth: `x-webhook-token` ou `?token=` do `webhook_token` da instância; 401 sem/errado, 404 inexistente/inativa). |
| POST | `/instance/{id}/mcp` | MCP HTTP no contexto da instância `{id}` (auth: `Authorization: Bearer <mcp_token>`; 401/404 como acima). |
| POST | `/webhook` | rota legada → instância default (auth: `WEBHOOK_TOKEN`). |
| POST | `/mcp` | rota legada → instância default (auth: `MCP_TOKEN`; 404 se vazio). |
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
├── Models/         Instance, Contact, Group, Conversation, Message, Media
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
`instances` (chave `instance_id`, que é também o nome da instância no Evolution),
`contacts`, `groups`, `conversations`, `messages`, `media`. `conversations` e
`messages` guardam `instance_id` (com FK para `instances`); a unicidade de
conversa é `(contact_id, instance_id)` / `(group_id, instance_id)`, então o mesmo
contato pode existir em números diferentes. `contacts` e `groups` são globais
(compartilhados entre instâncias). `messages` guarda também `search_text` (busca
por palavras) e `embedding vector(1536)` com índice HNSW (busca por significado).
