# Migração de e-mail da Matizze: poste.io → Microsoft 365

Documento operacional da migração do e-mail de `matizze.com.br` do **poste.io**
(servidor antigo, `srv1`) para o **Microsoft 365**. Reúne o diagnóstico, o DNS
final no Cloudflare, a configuração dos apps que enviam e-mail, a ordem segura
de execução e o rollback.

- **Domínio:** `matizze.com.br`
- **Destino:** Microsoft 365 (Exchange Online)
- **Origem:** poste.io em `srv1` (stack `posteio`, contêiner `posteio_mailserver`)
- **Issue de origem:** MTZ-50 (fonte viva do andamento)
- **Última revisão deste documento:** 16/11/2026

> Este documento não contém credenciais. Senhas e API keys de envio ficam no
> Passbolt e no env das stacks; valores de DNS são públicos.

## Resumo

O domínio usava dois provedores de e-mail ao mesmo tempo: o Microsoft 365
recebia o e-mail novo (MX), enquanto o poste.io continuava ativo e capturava
envios internos. A autenticação estava inconsistente — DMARC duplicado, DKIM do
Microsoft 365 ausente e SPF com mecanismos legados. O resultado era bloqueio e
quarentena de mensagens em destinatários externos.

A correção teve três frentes:

1. **DNS no Cloudflare** alinhado ao Microsoft 365 (DMARC único, SPF limpo,
   DKIM publicado, curinga removido).
2. **Apps que enviam e-mail** movidos do poste.io para o **Resend**
   (`smtp.resend.com`), com From em `no-reply@notifications.matizze.com.br` —
   Passbolt concluído em 18/10/2026; Chatwoot, Directus, twenty-crm e
   gmdisparos ainda pendentes.
3. **Desativação do poste.io** somente depois de migrar todos os apps e
   preservar o histórico das caixas.

## Por que migrar

- E-mails da Matizze eram **quarentenados/bloqueados** por organizações
  externas (caso observado: `mindworks.com.br`, via Trend Micro).
- **Dois registros DMARC** (`p=reject` e `p=none`) coexistiam, o que invalida a
  política.
- **DKIM do Microsoft 365 ausente** no DNS.
- **SPF** continha mecanismos do servidor antigo (`mx`,
  `a:cloud.matizze.com.br`).
- O **curinga `*.matizze.com.br`** capturava `selector1/2._domainkey` e apontava
  o DKIM para o alvo errado, gerando *"CNAME record does not exist"* no painel
  da Microsoft.
- O **poste.io seguia ativo** e, por ser o destino de envios para o próprio
  domínio, fazia entrega local: notificações do Passbolt para `henrique@`,
  `admin@` e `marcos@` nunca chegavam ao Outlook.

Observação: o bloqueio específico da `mindworks.com.br` vinha de uma regra de
**Keyword Expressions do Trend Micro do destinatário**. A correção de
autenticação reduz bloqueios em geral, mas não substitui um pedido de whitelist
de `@matizze.com.br` junto ao destinatário.

## Estado atual

| Item | Estado | Evidência |
| --- | --- | --- |
| DMARC consolidado em registro único | Feito | descrição da MTZ-50 |
| Curinga `*.matizze.com.br` removido | Feito | idem |
| `webmail.matizze.com.br` removido | Feito | idem |
| SPF limpo para o Microsoft 365 | Feito | idem |
| DKIM `selector1`/`selector2` publicado | Feito | idem |
| DKIM habilitado no painel do Microsoft 365 | Feito | idem |
| Envio de teste do Outlook com SPF/DKIM/DMARC `pass` | Feito (02/10/2026) | cabeçalhos na MTZ-50 |
| Passbolt enviando pelo Resend | Feito (18/10/2026) | envio real com `spf`/`dkim`/`dmarc` `pass` (MTZ-50, 1.1) |
| Chatwoot fora do poste.io | Pendente | SMTP ainda em `mail.matizze.com.br` (MTZ-50, 1.2) |
| Directus, twenty-crm e gmdisparos fora do poste.io | Pendente | stacks paradas, SMTP em `mail.matizze.com.br` (MTZ-50, 1.2) |
| Backup do volume `poste_data` | Feito (17/10/2026) | `poste_data-2026-10-16.tar.gz`, 401 MiB, 24.797 entradas, checksum e teste de listagem (MTZ-50, 2.2) |
| Export das caixas em uso | Pendente | — |
| Remoção de `mail.matizze.com.br` e DKIM antigo | Pendente | — |
| Desativação da stack `posteio` | Pendente | — |
| Reconfiguração de clientes/dispositivos | Pendente | — |

Este retrato reflete o que estava registrado na MTZ-50 na data de revisão. A
issue prevalece sobre esta tabela.

## DNS final no Cloudflare

Registros que fazem o Microsoft 365 funcionar:

| Tipo | Nome | Valor | Função |
| --- | --- | --- | --- |
| MX | `matizze.com.br` | `matizze-com-br.mail.protection.outlook.com` | recebimento |
| TXT | `matizze.com.br` | `v=spf1 include:spf.protection.outlook.com -all` | SPF |
| CNAME | `selector1._domainkey` | `selector1-matizze-com-br._domainkey.matizze.a-v1.dkim.mail.microsoft` | DKIM |
| CNAME | `selector2._domainkey` | `selector2-matizze-com-br._domainkey.matizze.a-v1.dkim.mail.microsoft` | DKIM |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:dmarc@matizze.com.br; ruf=mailto:dmarc@matizze.com.br; fo=1` | DMARC |
| CNAME | `autodiscover` | `autodiscover.outlook.com` | autoconfiguração |

### Mudanças feitas

1. **DMARC:** dois TXT (`p=reject` e `p=none`) foram consolidados no registro
   único acima. Dois registros DMARC anulam a política.
2. **Curinga removido:** `*.matizze.com.br` (CNAME → `cloud.matizze.com.br`)
   foi excluído. Ele capturava `selector1/2._domainkey` e quebrava o DKIM.
3. **`webmail.matizze.com.br` removido:** CNAME → `mail.matizze.com.br`, sem uso
   após a saída do poste.io.
4. **SPF limpo:** de
   `v=spf1 mx a:cloud.matizze.com.br include:spf.protection.outlook.com ~all`
   para `v=spf1 include:spf.protection.outlook.com -all`.
5. **DKIM publicado:** `selector1` e `selector2` apontando para o Microsoft 365.

### Registros a remover (somente após as Fases 1 e 2)

| Tipo | Nome | Valor atual | Quando remover |
| --- | --- | --- | --- |
| A | `mail.matizze.com.br` | `217.216.67.74` | depois de migrar **todos** os consumidores listados abaixo e fazer backup/export |
| TXT | `s20251211435._domainkey` | DKIM antigo do poste.io | depois que o poste.io parar de enviar |

#### Consumidores de `mail.matizze.com.br`

Antes de autorizar a remoção do registro, todos precisam estar fora do host
antigo (Resend ou envio desativado):

| Aplicação | Configuração atual | Estado |
| --- | --- | --- |
| Passbolt | Resend (`smtp.resend.com`) | migrado em 18/10/2026 |
| Chatwoot | `mail.matizze.com.br:465`, senha placeholder | rodando, não envia; migração para o Resend pendente |
| Directus | `mail.matizze.com.br:587`, usuário `no-reply@` | stack parada; migração pendente |
| twenty-crm | SMTP apontando para `mail.matizze.com.br` | stack inativa; migração pendente |
| gmdisparos | SMTP apontando para `mail.matizze.com.br` | stack inativa; migração pendente |

Um app que volte a subir com a configuração antiga tenta enviar pelo servidor
desligado e quebra — por isso a lista é pré-requisito da Fase 3.

## Microsoft 365

- **DKIM habilitado** no painel, com as chaves dos dois seletores publicadas e
  resolvendo.
- **SMTP AUTH não é usado pelos apps.** A decisão de 18/10 é que envio de
  aplicação sai pelo Resend (ver seção seguinte); a caixa placeholder
  `no-reply@matizze.com.br` foi removida do tenant.
- **Teste real de envio** (Outlook → caixa de teste, 02/10/2026 21:14) validado
  nos cabeçalhos:
  - `spf=pass smtp.mailfrom=matizze.com.br`
  - `dkim=pass header.d=matizze.com.br` (`s=selector1`)
  - `dmarc=pass action=none`
  - `DKIM-Signature: ... d=matizze.com.br; s=selector1`

## Aplicações que enviam e-mail

O poste.io hospedava 25 caixas. A auditoria de logs (13/09 a 02/10/2026) mostrou
o Passbolt como único app enviando por ele; outros apps estavam configurados
para `mail.matizze.com.br` e voltam a apontar para lá se subirem.

A diretriz de 18/10 (MTZ-50, 1.1) decide o envio de aplicação pelo **Resend**,
não por caixa ou usuário de e-mail e não por SMTP AUTH:

- **Host:** `smtp.resend.com`, porta `587` com STARTTLS (o transporte do
  Passbolt/CakePHP não faz SSL implícito, por isso 587 e não 465).
- **Usuário:** `resend`. **Senha:** API key de envio do Resend, no env da stack;
  nunca versionar.
- **From:** `no-reply@notifications.matizze.com.br` — domínio verificado no
  Resend, com SPF/DKIM/DMARC passando.
- **Reply-To recomendado:** `contato@matizze.com.br`, para compensar o
  `no-reply`.
- **Aceite:** e-mail real entregue com `spf=pass`, `dkim=pass` (s=resend) e
  `dmarc=pass` no cabeçalho.

### Passbolt

Migrado em 18/10/2026 e validado com envio real.

- **Serviço:** `passbolt` (stack do Passbolt, fora deste repositório)
- **Variáveis aplicadas:**
  - `EMAIL_TRANSPORT_DEFAULT_HOST=smtp.resend.com`
  - `EMAIL_TRANSPORT_DEFAULT_PORT=587`
  - `EMAIL_TRANSPORT_DEFAULT_TLS=true` (STARTTLS)
  - `EMAIL_TRANSPORT_DEFAULT_USERNAME=resend`
  - `EMAIL_DEFAULT_FROM=no-reply@notifications.matizze.com.br`
  - senha (API key de envio do Resend) no env da stack; nunca versionar
- **Achado:** o remetente estava hardcoded em `/etc/passbolt/passbolt.php`
  (volume `passbolt_config`) e ignorava a env; corrigido para ser dirigido por
  env nos dois nós, com backup `.bak-mtz50` em cada.
- **Evidência:** `passbolt send_test_email -r henrique@matizze.com.br` recebido
  no Outlook em 18/10/2026 16:27Z, com `spf=pass`
  (`smtp.mailfrom=rsend.notifications.matizze.com.br`), `dkim=pass`
  (`d=notifications.matizze.com.br`, `s=resend`) e `dmarc=pass`.
- **Pendência:** ~430 notificações antigas da fila ficaram parqueadas
  (`send_at=2030`); recomenda-se purgar para não dispararem em massa.

### Chatwoot

- **Estado:** rodando, SMTP em `mail.matizze.com.br:465` com senha placeholder —
  ou seja, não envia.
- **Decisão (16/11):** migrar para o Resend, mesmo padrão do Passbolt.
- **Aceite:** teste de envio chega ao destino **ou** envio explicitamente
  desabilitado e documentado.

### Directus, twenty-crm e gmdisparos

- Stacks paradas/inativas, mas com SMTP configurado para
  `mail.matizze.com.br` — sobem apontando para o servidor antigo.
- **Decisão (16/11):** migrar para o Resend, mesmo padrão do Passbolt,
  reversível por env.
- **Aceite:** cada app com env do Resend aplicado **ou** envio desabilitado
  de forma documentada.

## Ordem segura de execução

A regra de ouro: **não remover DNS (Fase 3) nem desligar o poste.io (Fase 4)
antes das Fases 1 e 2.**

| Fase | O que fazer | Depende de |
| --- | --- | --- |
| 0 | Pré-requisitos: acesso admin ao Microsoft 365, Cloudflare, SSH/Docker no `srv1`, admin do Passbolt; definir quais caixas precisam de histórico | — |
| 1 | Migrar apps que enviam para o Resend: Passbolt (feito); Chatwoot, Directus, twenty-crm e gmdisparos | Fase 0 |
| 2 | Preservar dados: confirmar uso do histórico, backup do volume `poste_data`, export das caixas em uso | Fase 0 (backup pode rodar em paralelo) |
| 3 | Limpeza de DNS: remover `mail.matizze.com.br` e o DKIM antigo `s20251211435._domainkey` | Fases 1 e 2 |
| 4 | Desativar a stack `posteio` e fechar as portas de e-mail | Fase 2 + Fase 3 |
| 5 | Reconfigurar clientes para Outlook e verificação final de autenticação | Fase 4 |

### Fase 2 em detalhe

- **Caixas de webmail em uso:** `eduardo.sales@` (último acesso 30/09),
  `giovane@` (14/09) e `laercio.figueiredo@` (14/09). Confirmar com cada um se
  precisam do histórico.
- **Backup (feito em 17/10/2026):** volume `poste_data` copiado para fora do
  `srv1` em `poste_data-2026-10-16.tar.gz` — 401 MiB, 24.797 entradas, as 25
  caixas presentes, com checksum e teste de listagem (MTZ-50, 2.2).
- **Export (pendente):** exportar em mbox/maildir as caixas que precisam de
  histórico, a partir do backup, e validar a abertura dos arquivos.

### Fase 4 em detalhe

- Parar/remover a stack `posteio` (contêiner `posteio_mailserver`).
- Confirmar portas fechadas: 25, 110, 143, 465, 587, 993, 995 e 4190.
- Validar envio e recebimento no Microsoft 365 depois do desligamento.

## O que não pode ser tocado

Enquanto o Microsoft 365 for o provedor:

- **MX** (`matizze-com-br.mail.protection.outlook.com`)
- **SPF** (`v=spf1 include:spf.protection.outlook.com -all`)
- **DKIM `selector1` e `selector2`** (CNAMEs para o Microsoft 365)
- **DMARC** (registro único em `_dmarc`)
- **autodiscover** (`autodiscover.outlook.com`)

Remover ou alterar qualquer um desses derruba recebimento, envio ou
autenticação. Também não remover `mail.matizze.com.br` nem desligar o poste.io
antes de tirar **todos** os consumidores do host antigo (Passbolt já migrado;
Chatwoot, Directus, twenty-crm e gmdisparos pendentes) e exportar as caixas em
uso.

## Rollback

Todas as mudanças de DNS são reversíveis. As Fases 3 e 4 só são seguras com o
backup da Fase 2.

| Mudança | Rollback |
| --- | --- |
| SPF limpo | recriar `v=spf1 mx a:cloud.matizze.com.br include:spf.protection.outlook.com ~all` |
| DMARC consolidado | recriar o registro anterior desejado (`p=reject` ou `p=none`) — manter **apenas um** |
| Curinga removido | recriar `*.matizze.com.br` CNAME → `cloud.matizze.com.br` (só se houver dependência real) |
| `webmail` removido | recriar `webmail.matizze.com.br` CNAME → `mail.matizze.com.br` |
| DKIM antigo removido | recuperar o valor no audit log do Cloudflare antes da remoção; o valor não foi registrado na MTZ-50 |
| `mail.matizze.com.br` removido | recriar A → `217.216.67.74` |
| Passbolt no Resend | restaurar o stack anterior no Portainer (envs antigas em `mail.matizze.com.br:587`), reverter `passbolt.php` a partir do `.bak-mtz50` nos dois nós e revogar a API key no Resend |
| Chatwoot/Directus/twenty-crm/gmdisparos no Resend | voltar o SMTP do app para `mail.matizze.com.br` (enquanto o host existir) e redeployar |
| Stack `posteio` desligada | subir a stack novamente; dados preservados no volume `poste_data` e no backup |

## Verificação

Comandos úteis (sem credenciais):

```bash
dig +short MX matizze.com.br
dig +short TXT matizze.com.br
dig +short TXT _dmarc.matizze.com.br
dig +short CNAME selector1._domainkey.matizze.com.br
dig +short CNAME selector2._domainkey.matizze.com.br
dig +short CNAME autodiscover.matizze.com.br
dig +short mail.matizze.com.br
```

Esperado no estado final:

- MX aponta para `matizze-com-br.mail.protection.outlook.com`.
- SPF contém apenas `include:spf.protection.outlook.com` e termina em `-all`.
- `selector1`/`selector2` resolvem para os CNAMEs do Microsoft 365.
- DMARC é um único TXT.
- `mail.matizze.com.br` não resolve.
- Teste externo (ex.: mail-tester) retorna `SPF pass`, `DKIM pass` com
  `d=matizze.com.br` e `DMARC pass`.

## Decisões registradas

- **Um único DMARC.** Registros duplicados anulam a política; consolidar antes
  de qualquer endurecimento.
- **Remover o curinga.** O `*.matizze.com.br` quebrava o DKIM e não é necessário
  para o Microsoft 365.
- **SPF estrito (`-all`).** Com todos os envios legítimos pelo Microsoft 365,
  não há motivo para `~all`.
- **Não usar webmail próprio.** O webmail do poste.io é descontinuado; o acesso
  passa a ser pelo Outlook.
- **Envio de aplicação sai pelo Resend.** Não usar caixa/usuário de e-mail nem
  SMTP AUTH para apps: From `no-reply@notifications.matizze.com.br`, API key por
  stack, `smtp.resend.com:587` com STARTTLS (decisão de 18/10).
- **Preservar histórico antes de desligar.** Backup do volume e export das
  caixas em uso são pré-requisitos para a desativação.
- **DMARC `p=none` por enquanto.** Endurecer para `quarantine` e depois
  `reject` somente após 1–2 semanas de relatórios `rua`/`ruf`.
- **Bloqueio da `mindworks.com.br` é externo.** Depende de whitelist da regra do
  Trend Micro do destinatário, não de DNS.

## Pendências

1. Migrar Chatwoot, Directus, twenty-crm e gmdisparos para o Resend (ou desativar
   o envio de cada um).
2. Confirmar com `eduardo.sales`, `giovane` e `laercio.figueiredo` a necessidade
   do histórico.
3. Exportar em mbox/maildir as caixas em uso, a partir do backup validado.
4. Remover `mail.matizze.com.br` e o DKIM antigo `s20251211435._domainkey`, com
   todos os consumidores já fora do host.
5. Desativar a stack `posteio` e validar portas fechadas.
6. Reconfigurar clientes/dispositivos para `outlook.office365.com` /
   `smtp.office365.com`.
7. Purgar as notificações antigas parqueadas na fila do Passbolt.
8. Opcional: endurecer o DMARC após monitoramento.

## Referências

- MTZ-50 — E-mail Matizze: concluir migração poste.io → Microsoft 365 e limpeza
  de DNS (issue de origem, com auditoria das caixas e checklist detalhado).
- MTZ-53 — viabilidade de acesso do agente ao admin do Microsoft 365 via MCP.
