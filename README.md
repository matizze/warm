# WARM — WhatsApp Agent

Seu WhatsApp, consultado e utilizado por IA via MCP.

## Visão Geral

O WARM conecta uma única instância de WhatsApp a modelos de linguagem por meio do **Model Context Protocol (MCP)**.

O sistema recebe mensagens do WhatsApp por meio de um provedor, processa e normaliza diferentes formatos de mensagem e armazena os dados em um banco de dados próprio.

A IA pode consultar esse conteúdo por meio do servidor MCP e, posteriormente, também enviar mensagens pelo mesmo fluxo de processamento.

O WARM suporta diferentes provedores de WhatsApp, como:

- Evolution API
- Z-API
- WhatsMeow
- Outros provedores compatíveis

O provedor é uma implementação de infraestrutura. O núcleo do WARM não depende de um provedor específico.

### Unidade de implantação

Cada instalação do WARM representa **uma única instância de WhatsApp**.

```text
WARM #1
├── 1 WhatsApp
├── 1 Provider
├── 1 Application
├── 1 Banco de Dados
└── 1 MCP Server
```

Caso o usuário queira utilizar outro número de WhatsApp, deverá executar outra instalação independente do WARM.

```text
WARM #1 → WhatsApp A → Banco A
WARM #2 → WhatsApp B → Banco B
WARM #3 → WhatsApp C → Banco C
```

Não existe suporte a múltiplas instâncias dentro da mesma instalação.

Essa decisão simplifica:

- modelo de dados;
- código;
- segurança;
- isolamento;
- configuração;
- testes;
- operação;
- manutenção.

---

# Objetivo

Registrar e organizar as mensagens recebidas e enviadas pelo WhatsApp, incluindo:

- texto;
- áudio;
- imagem;
- vídeo;
- documento;
- localização;
- contatos;
- outros tipos suportados pelo provedor.

O sistema deve permitir que uma IA consulte esse conteúdo por meio do MCP, utilizando o WhatsApp como fonte de contexto.

O sistema também deve possuir arquitetura preparada para envio de mensagens pela IA.

---

# Entidades Principais

## Contact

Representa uma pessoa identificada no WhatsApp.

Pode conter:

- número de telefone;
- nome;
- push name;
- observações criadas pelo usuário.

As observações representam conhecimento contextual sobre o contato.

Exemplo:

> "Prefere mensagens curtas e objetivas."

---

## Group

Representa um grupo do WhatsApp.

Pode conter:

- identificador do grupo no provedor;
- nome;
- descrição oficial do grupo;
- observações criadas pelo usuário.

A diferença entre `description` e `observations` é importante:

- `description`: informação proveniente do WhatsApp/provedor;
- `observations`: conhecimento contextual mantido pelo WARM.

Exemplo:

```text
Nome:
Cliente XPTO — Implantação

Descrição:
Grupo destinado ao acompanhamento da implantação.

Observações:
João prefere mensagens curtas.
Maria trata assuntos financeiros.
Assuntos técnicos devem ser tratados com João.
```

---

## Conversation

Representa uma conversa entre a instalação do WARM e um contato ou grupo.

Uma conversa pertence a:

- um contato; ou
- um grupo.

A Conversation representa o contexto no qual as mensagens estão agrupadas.

---

## Message

Representa uma mensagem recebida ou enviada.

Uma mensagem pertence a uma Conversation.

Pode possuir:

- conteúdo;
- remetente;
- direção;
- tipo;
- identificador do provedor;
- status;
- timestamps;
- mídia;
- informações processadas por IA.

A mesma entidade `Message` representa os dois sentidos:

```text
inbound  → WhatsApp → WARM
outbound → WARM → WhatsApp
```

---

## Media

Representa um arquivo associado a uma mensagem.

Pode representar:

- áudio;
- imagem;
- vídeo;
- documento;
- outros anexos.

A mídia pode possuir conteúdo processado, como:

- transcrição;
- descrição;
- texto extraído;
- metadados.

---

# Arquitetura

A arquitetura do WARM separa a comunicação externa, a normalização de entrada, a aplicação, a persistência e a interface com agentes de IA.

```text
                         ┌─────────────────┐
                         │    WhatsApp     │
                         └────────┬────────┘
                                  │
                                  ▼
                         ┌─────────────────┐
                         │     Provider    │
                         │                 │
                         │ Z-API           │
                         │ Evolution       │
                         │ WhatsMeow       │
                         └────────┬────────┘
                                  │
                               Webhook
                                  │
                                  ▼
                         ┌─────────────────┐
                         │     Normalize   │
                         │                 │
                         │ Provider Payload│
                         │        ↓        │
                         │ IncomingMessage │
                         └────────┬────────┘
                                  │
                                  ▼
                         ┌─────────────────┐
                         │   Application   │
                         │   / Use Cases   │
                         └────────┬────────┘
                                  │
                                  ▼
                         ┌─────────────────┐
                         │   Persistence   │
                         │                 │
                         │ Contacts        │
                         │ Groups          │
                         │ Conversations   │
                         │ Messages       │
                         │ Media           │
                         └─────────────────┘


                         ┌─────────────────┐
                         │    AI Agent     │
                         │ Claude / Codex /│
                         │ Copilot / etc.  │
                         └────────┬────────┘
                                  │
                                 MCP
                                  │
                                  ▼
                         ┌─────────────────┐
                         │   Application   │
                         │   / Use Cases   │
                         └────────┬────────┘
                                  │
                            WhatsApp Service
                                  │
                                  ▼
                         ┌─────────────────┐
                         │     Provider    │
                         │                 │
                         │ Z-API           │
                         │ Evolution       │
                         │ WhatsMeow       │
                         └────────┬────────┘
                                  │
                                  ▼
                         ┌─────────────────┐
                         │    WhatsApp     │
                         └─────────────────┘
```

O fluxo possui três caminhos principais:

### Recebimento

```text
WhatsApp
   ↓
Provider
   ↓
Webhook
   ↓
normalize()
   ↓
IncomingMessage
   ↓
Application
   ↓
Persistence
```

### Envio

```text
AI Agent
   ↓
MCP
   ↓
Application / Use Case
   ↓
WhatsApp Service
   ↓
Provider
   ↓
WhatsApp
```

### Consulta

```text
AI Agent
   ↓
MCP
   ↓
Application / Use Case
   ↓
QueryService
   ↓
Persistence
```

A aplicação trabalha somente com modelos e contratos próprios do WARM.

Ela não precisa conhecer os formatos específicos de Evolution, Z-API ou WhatsMeow.

---

# Fluxo de recebimento

O recebimento é iniciado pelo webhook enviado pelo provider.

O payload externo é convertido por `normalize()` para um contrato tipado do WARM. A aplicação passa a trabalhar exclusivamente com esse contrato.

```text
WhatsApp
   ↓
Provider
   ↓
Webhook
   ↓
normalize(payload)
   ↓
IncomingMessage
   ↓
Application
   ↓
Conversation
   ↓
Message
   ↓
Media Processing
   ↓
Persistence
```

O `normalize()`:

1. recebe o payload específico do provider;
2. interpreta os campos necessários;
3. transforma o payload em um modelo tipado do WARM;
4. retorna o contrato esperado pela aplicação.

Exemplo conceitual:

```ts
const message: IncomingMessage = normalize(payload);
```

A aplicação não recebe `EvolutionPayload`, `ZApiPayload` ou `WhatsMeowPayload`.

Ela recebe somente:

```ts
IncomingMessage;
```

O `normalize()` é utilizado no fluxo de entrada. Ele não é responsável por adaptar mensagens para envio.

---

# Fluxo de envio

O envio utiliza o padrão **Ports and Adapters**.

A aplicação trabalha com um contrato próprio do WARM e depende de um serviço (`WhatsAppService`). A implementação concreta desse serviço realiza a comunicação com o provider escolhido.

```text
AI Agent
   ↓
MCP
   ↓
Application / Use Case
   ↓
WhatsApp Service
   ↓
Provider
   ↓
WhatsApp
```

Exemplo conceitual:

```ts
interface WhatsAppService {
    sendMessage(message: OutgoingMessage): Promise<SendResult>;
    editMessage(messageId: string, content: string): Promise<SendResult>;
    sendSticker(stickerId: string, conversationId: string): Promise<SendResult>;
    react(messageId: string, emoji: string): Promise<SendResult>;
    deleteMessage(messageId: string): Promise<SendResult>;
    reply(messageId: string, content: string): Promise<SendResult>;
}
```

A aplicação conhece apenas `WhatsAppService` e `OutgoingMessage`.

Ela não conhece os formatos específicos da Evolution, Z-API ou WhatsMeow.

A implementação do serviço pode utilizar qualquer provider compatível:

```text
WhatsAppService
├── Evolution
├── Z-API
└── WhatsMeow
```

O fluxo de envio é:

1. o AI Agent solicita `message.send` pelo MCP;
2. o MCP encaminha a operação para a aplicação;
3. o Use Case valida a operação;
4. a aplicação utiliza `WhatsAppService`;
5. a implementação do serviço comunica-se com o provider;
6. o provider realiza o envio;
7. o resultado do envio retorna para a aplicação;
8. a mensagem e seu status são persistidos.

Não existe `normalize()` no fluxo de envio.

---

# Banco de Dados

Como cada instalação representa uma única instância de WhatsApp, **não existe `instance_id` nas tabelas**.

O próprio banco representa a instância.

```dbml
Table contacts {
  id uuid [pk]

  phone_number varchar [not null]
  name varchar
  push_name varchar

  observations text

  created_at timestamp [not null]
  updated_at timestamp [not null]

  indexes {
    phone_number [unique]
  }
}


Table groups {
  id uuid [pk]

  provider_group_id varchar [not null]
  name varchar
  description text

  observations text

  created_at timestamp [not null]
  updated_at timestamp [not null]

  indexes {
    provider_group_id [unique]
  }
}


Table conversations {
  id uuid [pk]

  type varchar [not null, note: 'contact | group']

  contact_id uuid
  group_id uuid

  created_at timestamp [not null]
  updated_at timestamp [not null]
  last_message_at timestamp

  indexes {
    contact_id
    group_id
  }
}


Table messages {
  id uuid [pk]

  conversation_id uuid [not null]

  provider_message_id varchar

  direction varchar [not null, note: 'inbound | outbound']
  type varchar [not null]

  sender_contact_id uuid

  content text
  metadata json

  status varchar [not null, note: 'pending | sending | sent | delivered | read | failed']

  sent_at timestamp
  delivered_at timestamp
  read_at timestamp

  created_at timestamp [not null]

  indexes {
    (conversation_id, created_at)
    provider_message_id
    sender_contact_id
  }
}


Table media {
  id uuid [pk]

  message_id uuid [not null]

  type varchar [not null]
  filename varchar
  mime_type varchar
  size_bytes bigint

  storage_key varchar [not null]

  metadata json

  created_at timestamp [not null]
}


Ref: contacts.id < conversations.contact_id
Ref: groups.id < conversations.group_id

Ref: conversations.id < messages.conversation_id
Ref: contacts.id < messages.sender_contact_id

Ref: messages.id < media.message_id
```

---

# Histórias de Usuário

## US-01 — Consultar Mensagens

**Como** usuário do WhatsApp, **quero** consultar mensagens das minhas conversas por meio da IA **para** recuperar informações e contexto sem precisar procurar manualmente no WhatsApp.

### Critérios de Aceitação

### Texto

- O texto é salvo com identificação da conversa, remetente, data, horário e identificador da mensagem.
- Mensagens duplicadas não geram registros duplicados.
- Mensagens podem ser pesquisadas por texto.
- Mensagens podem ser filtradas por conversa.
- Mensagens podem ser filtradas por período.
- Mensagens podem ser filtradas por remetente.
- Mensagens podem ser filtradas por tipo.

### Áudio

- Cada áudio é associado à mensagem original.
- O áudio pode ser transcrito automaticamente.
- A transcrição é armazenada para consulta.
- Falhas de transcrição são registradas.
- O sistema nunca inventa uma transcrição.
- A transcrição pode ser solicitada novamente.

### Imagens

- A imagem é associada à mensagem original.
- O sistema pode gerar uma descrição da imagem.
- Texto identificado na imagem pode ser armazenado separadamente.
- A descrição e o texto identificado devem ser distinguidos.
- A imagem pode ser consultada pelo conteúdo processado.

### Vídeos

- O vídeo é associado à mensagem original.
- O conteúdo falado pode ser transcrito.
- O conteúdo visual pode ser descrito.
- Falhas de processamento são registradas.
- O sistema nunca inventa conteúdo.

### Documentos

- O documento é associado à mensagem original.
- O conteúdo textual pode ser extraído.
- Documentos podem ser pesquisados pelo conteúdo extraído.
- O arquivo original pode ser recuperado.

---

# US-02 — Consultar Conversas

**Como** usuário, **quero** encontrar e consultar conversas **para** entender o contexto das interações.

### Critérios de Aceitação

- O sistema permite pesquisar conversas.
- Conversas podem ser encontradas por nome de contato.
- Conversas podem ser encontradas por nome de grupo.
- Conversas podem ser encontradas pelo conteúdo das mensagens.
- O sistema permite consultar o contexto de uma conversa.
- O sistema permite recuperar mensagens relacionadas à conversa.
- Conversas são ordenadas por atividade recente quando aplicável.

---

# US-03 — Consultar Contatos

**Como** usuário, **quero** consultar informações sobre contatos **para** recuperar contexto antes de analisar ou enviar mensagens.

### Critérios de Aceitação

- O sistema permite pesquisar contatos.
- O sistema permite consultar um contato.
- As observações do contato podem ser consultadas.
- O contato pode ser associado às suas conversas.

---

# US-04 — Consultar Grupos

**Como** usuário, **quero** consultar informações sobre grupos **para** compreender o contexto antes de analisar ou enviar mensagens.

### Critérios de Aceitação

- O sistema permite pesquisar grupos.
- O sistema permite consultar um grupo.
- O sistema disponibiliza nome e descrição do grupo.
- As observações do grupo podem ser consultadas.
- O grupo pode ser associado à sua Conversation.

---

# US-05 — Enviar Mensagem

**Como** usuário, **quero** solicitar à IA o envio de uma mensagem **para** poder utilizar o WhatsApp diretamente através do meu agente.

### Critérios de Aceitação

- A IA pode solicitar o envio de uma mensagem.
- O sistema identifica a Conversation de destino.
- A aplicação valida o conteúdo.
- A implementação do `WhatsAppService` envia a mensagem utilizando o provider configurado.
- O provedor realiza o envio.
- O resultado do envio é registrado.
- A mensagem enviada é persistida.
- Falhas de envio são registradas.
- O sistema não informa que uma mensagem foi enviada sem confirmação adequada do provedor.

---

# US-06 — Enviar Mídia

**Como** usuário, **quero** enviar uma mídia por meio da IA **para** utilizar o WhatsApp sem precisar abrir manualmente o aplicativo.

Tipos previstos:

- imagem;
- áudio;
- vídeo;
- documento.

O envio deve seguir o mesmo fluxo de `message.send`.

```text
media
  ↓
message.send
  ↓
Application / Use Case
  ↓
WhatsApp Service
  ↓
Provider
  ↓
WhatsApp
```

---

# US-07 — Contexto de Contatos e Grupos

**Como** usuário, **quero** registrar observações sobre contatos e grupos **para** que a IA tenha contexto adicional ao analisar ou preparar comunicações.

Exemplos:

```text
"Prefere mensagens curtas."

"É responsável pelo financeiro."

"Este grupo é exclusivamente sobre o projeto X."

"Não tratar assuntos financeiros neste grupo."
```

As observações não substituem as informações originais do WhatsApp.

---

# MCP

O MCP deve expor **capacidades**, e não representar diretamente as tabelas do banco.

## Princípios

- Não expor CRUD indiscriminadamente.
- Não expor `instance_id`.
- Não exigir que o agente conheça detalhes internos do banco.
- Operações devem representar ações úteis para um agente.
- Consultas devem retornar contexto suficiente para evitar chamadas redundantes.

---

# Catálogo MCP

## `conversation.search`

Pesquisa conversas.

Possíveis filtros:

```yaml
query: string?
type: contact | group?
contact_id: string?
group_id: string?
limit: integer?
cursor: string?
```

Exemplos:

> "Encontre a conversa com João."

> "Quais conversas mencionaram o contrato?"

---

## `conversation.get`

Obtém o contexto de uma conversa específica.

Pode retornar:

- identificação;
- tipo;
- contato ou grupo;
- observações relevantes;
- última atividade;
- últimas mensagens ou contexto resumido.

---

## `message.search`

Pesquisa mensagens dentro do banco.

Possíveis filtros:

```yaml
conversation_id: string?
query: string?
sender_contact_id: string?
type: string?
direction: inbound | outbound?
from: datetime?
to: datetime?
limit: integer?
cursor: string?
```

Não existe `message.get` inicialmente.

Se uma mensagem específica precisar de detalhes, o resultado de `message.search` já deve fornecer informação suficiente.

---

## `message.send`

Envia uma mensagem.

Exemplo conceitual:

```yaml
conversation_id: string
type: text | image | audio | video | document
content: string?
media_id: string?
```

---

## `contact.search`

Pesquisa contatos.

---

## `contact.get`

Consulta o contexto de um contato, incluindo suas observações.

---

## `group.search`

Pesquisa grupos.

---

## `group.get`

Consulta o contexto de um grupo, incluindo:

- nome;
- descrição;
- observações.

---

## `media.download`

Recupera o arquivo original associado a uma mensagem.

---

## `media.upload`

Adiciona uma mídia ao sistema para posterior utilização em uma mensagem.

---

# Operações que não fazem parte do MCP inicialmente

Não serão expostas inicialmente:

- `message.get`;
- `conversation.list`;
- `contact.list`;
- `group.list`;
- CRUD genérico de entidades;
- seleção de `instance_id`;
- gerenciamento de múltiplas instâncias.

A regra é:

> Se uma operação pode ser resolvida adequadamente por outra operação existente, não criar uma nova tool apenas para representar uma operação CRUD.

---

# Segurança

Como cada instalação possui somente um WhatsApp, o isolamento de instâncias ocorre no nível da implantação.

Não existe necessidade de:

- `instance_id` nas tabelas;
- filtros por `instance_id`;
- Row Level Security baseada em instância;
- `InstanceResolver`;
- seleção de banco por instância;
- roteamento entre instâncias.

O banco da instalação contém exclusivamente os dados daquele WARM.

```text
WARM A
└── DB A
    └── WhatsApp A

WARM B
└── DB B
    └── WhatsApp B
```

---

# Credenciais

Credenciais nunca devem ser armazenadas no banco de dados de domínio.

Devem permanecer em:

- variáveis de ambiente;
- secret manager;
- mecanismo seguro equivalente.

Credenciais não devem aparecer em:

- código;
- logs;
- respostas MCP;
- mensagens de erro;
- documentos versionados.

---

# Segurança do Webhook

O webhook do provedor deve possuir autenticação.

O WARM deve:

1. validar a origem/autenticação;
2. rejeitar requisições não autorizadas;
3. validar o formato do payload;
4. normalizar o evento;
5. processar somente eventos suportados;
6. registrar falhas sem expor credenciais.

---

# Idempotência

O processamento de mensagens recebidas deve ser idempotente.

O identificador fornecido pelo provedor deve ser utilizado quando disponível.

Uma mesma mensagem recebida duas vezes não pode gerar dois registros no banco.

---

# Auditoria

Operações que modificam dados devem possuir rastreabilidade.

Exemplos:

- categorização;
- alteração de observações;
- resolução de conversa;
- envio de mensagem;
- upload de mídia;
- sincronização.

A auditoria deve registrar:

- data/hora;
- operação;
- origem;
- resultado;
- identificador do recurso afetado.

---

# Privacidade

O WARM armazena dados potencialmente pessoais provenientes do WhatsApp.

O sistema deve considerar:

- LGPD;
- controle de acesso;
- criptografia em trânsito;
- proteção dos dados armazenados;
- exclusão dos dados quando solicitada;
- proteção de credenciais;
- minimização de dados;
- auditoria de operações.

---

# Arquitetura de Código

Uma estrutura inicial pode ser:

```text
src/
│
├── domain/
│   ├── entities/
│   │   ├── Contact
│   │   ├── Group
│   │   ├── Conversation
│   │   ├── Message
│   │   └── Media
│   │
│   └── services/
│       └── WhatsAppService
│
├── application/
│   └── use-cases/
│       ├── SendMessage
│       ├── SearchMessages
│       ├── GetConversation
│       ├── SearchContacts
│       └── SearchGroups
│
├── normalize/
│   ├── IncomingMessage
│   └── normalize
│
├── providers/
│   ├── evolution/
│   ├── zapi/
│   └── whatsmeow/
│
├── infrastructure/
│   ├── database/
│   ├── storage/
│   └── transcription/
│
├── webhooks/
│
└── mcp/
    └── tools/
        ├── conversation
        ├── message
        ├── contact
        ├── group
        └── media
```

A separação principal é:

```text
Provider
   ↓
normalize()
   ↓
Domain / Application
   ↓
Persistence
```

e:

```text
MCP
   ↓
Application
   ↓
WhatsAppService
   ↓
Provider
```

---

# Fluxo Interno

## Inbound

```text
Provider
   ↓
Webhook
   ↓
normalize()
   ↓
IncomingMessage
   ↓
Application
   ↓
Conversation
   ↓
Message
   ↓
Media Processing
   ↓
Persistence
```

## Outbound

```text
MCP
   ↓
message.send
   ↓
Application / Use Case
   ↓
WhatsAppService
   ↓
Provider
   ↓
WhatsApp
   ↓
Confirmação
   ↓
Persistence
```

## Query

```text
MCP
   ↓
Application / Use Case
   ↓
QueryService
   ↓
Persistence
```

---

# Múltiplos Provedores

O WARM suporta diferentes provedores, mas **uma instalação utiliza somente um provedor por vez**.

Exemplo:

```env
WHATSAPP_PROVIDER=evolution
```

ou:

```env
WHATSAPP_PROVIDER=zapi
```

ou:

```env
WHATSAPP_PROVIDER=whatsmeow
```

O domínio e os Use Cases permanecem independentes do provider.

No recebimento, o payload externo é convertido para o contrato interno por `normalize()`.

No envio, o `WhatsAppService` é implementado utilizando o provider configurado.

```text
Inbound:

Provider
   ↓
normalize()
   ↓
IncomingMessage
   ↓
Application


Outbound:

Application
   ↓
WhatsAppService
   ↓
Provider
```

A aplicação não precisa saber de onde veio uma mensagem recebida nem qual provider será utilizado para enviá-la.

---

# Limites da versão atual

A versão inicial não contempla:

- múltiplas instâncias dentro da mesma instalação;
- múltiplos números dentro do mesmo banco;
- seleção de instância pelo MCP;
- banco compartilhado entre diferentes WhatsApps;
- isolamento multi-tenant dentro da aplicação.

Se o usuário precisar de outro WhatsApp, deverá criar outra instalação do WARM.

---

# Sequência de Entregas

## 1. Fundação

- Banco de dados.
- Modelo de entidades.
- Integração com o provider.
- Webhook.
- Persistência de mensagens de texto.
- MCP básico.

## 2. Consulta

- `conversation.search`
- `conversation.get`
- `message.search`
- Busca por texto.
- Filtros por período.
- Filtros por remetente.

## 3. Mídia

- Áudios.
- Transcrição.
- Imagens.
- OCR.
- Vídeos.
- Documentos.
- Download de mídia.

## 4. Contexto

- Contatos.
- Grupos.
- Observações.
- Descrição dos grupos.
- Contexto adicional para IA.

## 5. Organização

- Categorias.
- Resolução de conversas.
- Snooze.
- Inbox.
- Auditoria.

## 6. Outbound

- `message.send`.
- Envio de texto.
- Persistência de mensagens enviadas.
- Confirmação de entrega.
- Envio de mídia.

---

# Definição de Pronto

Uma funcionalidade está pronta quando:

- os critérios de aceitação estão implementados;
- testes automatizados passam;
- dados são persistidos corretamente;
- operações MCP funcionam de ponta a ponta;
- mensagens duplicadas não são criadas;
- credenciais não aparecem em logs;
- erros não expõem informações sensíveis;
- o Provider permanece isolado do domínio;
- mudanças de banco são versionadas;
- o sistema mantém a separação entre dados do WhatsApp e contexto criado pelo usuário.

---

# Princípio Arquitetural Central

> **O WARM representa uma única instalação de WhatsApp.**

O sistema não precisa saber administrar múltiplas instâncias.

No recebimento, o provider entrega um payload externo que é convertido por `normalize()` para um contrato tipado do WARM.

Na aplicação, os Use Cases trabalham somente com os contratos internos do WARM.

No envio, a aplicação utiliza `WhatsAppService`, seguindo o padrão Ports and Adapters.

A persistência mantém os dados daquela instalação.

O MCP representa as capacidades disponíveis para a IA.

```text
Inbound:

WhatsApp
   ↓
Provider
   ↓
normalize()
   ↓
Application
   ↓
Persistence


Outbound:

AI Agent
   ↓
WARM MCP
   ↓
Application
   ↓
WhatsAppService
   ↓
Provider
   ↓
WhatsApp


Query:

AI Agent
   ↓
WARM MCP
   ↓
Application
   ↓
QueryService
   ↓
Persistence
```

Se outro WhatsApp for necessário:

```text
WARM #1 → WhatsApp #1
WARM #2 → WhatsApp #2
```

São instalações independentes.
