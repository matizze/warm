# Migração de e-mail da Matizze: poste.io → Microsoft 365

Documento operacional da migração do e-mail de `matizze.com.br` do **poste.io**
(servidor antigo, `srv1`) para o **Microsoft 365**. Reúne o diagnóstico, o DNS
final no Cloudflare, a configuração dos apps que enviam e-mail, a ordem segura
de execução e o rollback.

- **Domínio:** `matizze.com.br`
- **Destino:** Microsoft 365 (Exchange Online)
- **Origem:** poste.io em `srv1` (stack `posteio`, contêiner `posteio_mailserver`)
- **Issue de origem:** MTZ-50 (fonte viva do andamento)
- **Última revisão deste documento:** 17/10/2026

> Este documento não contém credenciais. Senhas de caixas e de SMTP AUTH ficam
> no Passbolt; valores de DNS são públicos.

## Resumo

O domínio usava dois provedores de e-mail ao mesmo tempo: o Microsoft 365
recebia o e-mail novo (MX), enquanto o poste.io continuava ativo e capturava
envios internos. A autenticação estava inconsistente — DMARC duplicado, DKIM do
Microsoft 365 ausente e SPF com mecanismos legados. O resultado era bloqueio e
quarentena de mensagens em destinatários externos.

A correção teve três frentes:

1. **DNS no Cloudflare** alinhado ao Microsoft 365 (DMARC único, SPF limpo,
   DKIM publicado, curinga removido).
2. **Apps que enviam e-mail** (Passbolt e Chatwoot) movidos do poste.io para o
   SMTP do Microsoft 365.
3. **Desativação do poste.io** somente depois de migrar os apps e preservar o
   histórico das caixas.

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
| Passbolt no SMTP do Microsoft 365 | Pendente | — |
| Chatwoot migrado ou envio desativado | Pendente | — |
| Backup do volume `poste_data` | Pendente | — |
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
| A | `mail.matizze.com.br` | `217.216.67.74` | depois de migrar Passbolt/Chatwoot e fazer backup |
| TXT | `s20251211435._domainkey` | DKIM antigo do poste.io | depois que o poste.io parar de enviar |

## Microsoft 365

- **DKIM habilitado** no painel, com as chaves dos dois seletores publicadas e
  resolvendo.
- **SMTP AUTH** precisa estar habilitado nas caixas usadas por aplicativos
  (`no-reply@`, `contato@`), pois apps enviam autenticando em
  `smtp.office365.com:587` com STARTTLS.
- **Teste real de envio** (Outlook → caixa de teste, 02/10/2026 21:14) validado
  nos cabeçalhos:
  - `spf=pass smtp.mailfrom=matizze.com.br`
  - `dkim=pass header.d=matizze.com.br` (`s=selector1`)
  - `dmarc=pass action=none`
  - `DKIM-Signature: ... d=matizze.com.br; s=selector1`

## Aplicações que enviam e-mail

O poste.io hospedava 25 caixas, mas só dois aplicativos enviavam por ele. A
auditoria de logs (13/09 a 02/10/2026) mostrou:

| Caixa | Aplicação | Situação |
| --- | --- | --- |
| `no-reply@matizze.com.br` | Passbolt | enviava pelo poste.io (entrega local, notificação presa) |
| `contato@matizze.com.br` | Chatwoot | configurado no poste.io, sem envio recente; senha placeholder |

### Passbolt

Migrar o transporte de e-mail do Passbolt para o Microsoft 365.

- **Serviço:** `passbolt` (stack do Passbolt, fora deste repositório)
- **Variáveis alvo:**
  - `EMAIL_TRANSPORT_DEFAULT_HOST=smtp.office365.com`
  - `EMAIL_TRANSPORT_DEFAULT_PORT=587`
  - `EMAIL_TRANSPORT_DEFAULT_TLS=true` (STARTTLS)
  - usuário: `no-reply@matizze.com.br`
  - senha: guardada no Passbolt; nunca versionar
- **Pré-requisito:** caixa/alias `no-reply@matizze.com.br` no Microsoft 365 com
  **SMTP AUTH habilitado**.
- **Aceite:** notificação de teste do Passbolt chega ao **Outlook** de
  `henrique@`/`admin@`, com `spf=pass` e `dkim=pass` no cabeçalho.
- **Atenção:** o remetente deve ser uma caixa licenciada do tenant. Sem SMTP
  AUTH habilitado, a autenticação falha.

### Chatwoot

- **Decisão pendente:** migrar `contato@matizze.com.br` para o Microsoft 365
  (mesmo padrão do Passbolt) **ou** desativar o envio.
- Antes de decidir, validar o estado atual: o SMTP aponta para
  `mail.matizze.com.br` com senha placeholder, portanto o envio provavelmente já
  está quebrado.
- **Aceite:** teste de envio chega ao destino (migrado) **ou** envio
  explicitamente desabilitado e documentado.

## Ordem segura de execução

A regra de ouro: **não remover DNS (Fase 3) nem desligar o poste.io (Fase 4)
antes das Fases 1 e 2.**

| Fase | O que fazer | Depende de |
| --- | --- | --- |
| 0 | Pré-requisitos: acesso admin ao Microsoft 365, Cloudflare, SSH/Docker no `srv1`, admin do Passbolt; definir quais caixas precisam de histórico | — |
| 1 | Migrar apps que enviam: Passbolt → SMTP do Microsoft 365; decidir/migrar Chatwoot | Fase 0 |
| 2 | Preservar dados: confirmar uso do histórico, backup do volume `poste_data`, export das caixas em uso | Fase 0 (backup pode rodar em paralelo) |
| 3 | Limpeza de DNS: remover `mail.matizze.com.br` e o DKIM antigo `s20251211435._domainkey` | Fases 1 e 2 |
| 4 | Desativar a stack `posteio` e fechar as portas de e-mail | Fase 2 + Fase 3 |
| 5 | Reconfigurar clientes para Outlook e verificação final de autenticação | Fase 4 |

### Fase 2 em detalhe

- **Caixas de webmail em uso:** `eduardo.sales@` (último acesso 30/09),
  `giovane@` (14/09) e `laercio.figueiredo@` (14/09). Confirmar com cada um se
  precisam do histórico.
- **Backup:** copiar o volume `poste_data` para fora do `srv1`, com checksum e
  teste de listagem/restauração. Volume total pequeno (~366 MB), backup rápido.
- **Export:** exportar em mbox/maildir as caixas que precisam de histórico e
  validar a abertura dos arquivos.

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
antes de migrar Passbolt/Chatwoot e exportar as caixas em uso.

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
| Passbolt no Microsoft 365 | restaurar as variáveis anteriores (transporte em `mail.matizze.com.br:587`) e redeployar |
| Chatwoot alterado | restaurar a configuração anterior ou manter envio desabilitado |
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
- **Apps usam caixas próprias no Microsoft 365** (`no-reply@`, `contato@`) com
  SMTP AUTH habilitado, em vez de relay aberto.
- **Preservar histórico antes de desligar.** Backup do volume e export das
  caixas em uso são pré-requisitos para a desativação.
- **DMARC `p=none` por enquanto.** Endurecer para `quarantine` e depois
  `reject` somente após 1–2 semanas de relatórios `rua`/`ruf`.
- **Bloqueio da `mindworks.com.br` é externo.** Depende de whitelist da regra do
  Trend Micro do destinatário, não de DNS.

## Pendências

1. Migrar o Passbolt para `smtp.office365.com:587` com SMTP AUTH.
2. Decidir e executar o Chatwoot (migrar `contato@` ou desativar envio).
3. Confirmar com `eduardo.sales`, `giovane` e `laercio.figueiredo` a necessidade
   do histórico.
4. Backup do volume `poste_data`, com checksum e teste de restauração.
5. Exportar as caixas em uso.
6. Remover `mail.matizze.com.br` e o DKIM antigo `s20251211435._domainkey`.
7. Desativar a stack `posteio` e validar portas fechadas.
8. Reconfigurar clientes/dispositivos para `outlook.office365.com` /
   `smtp.office365.com`.
9. Opcional: endurecer o DMARC após monitoramento.

## Referências

- MTZ-50 — E-mail Matizze: concluir migração poste.io → Microsoft 365 e limpeza
  de DNS (issue de origem, com auditoria das caixas e checklist detalhado).
- MTZ-53 — viabilidade de acesso do agente ao admin do Microsoft 365 via MCP.
