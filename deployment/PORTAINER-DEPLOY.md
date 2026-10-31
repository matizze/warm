# Deploy do WARM no Portainer (por digest)

O stack `warm` (Swarm, endpoint `primary`) roda a imagem definida pela
variável de stack `APP_IMAGE`: `app`, `worker` e `scheduler` usam
`image: ${APP_IMAGE}`. Deploy = trocar `APP_IMAGE` por um digest imutável e
redeployar o stack. Produção não consome tag móvel (`:latest`, `:main`).

## Contrato do job de deploy

Job separado no GitHub Actions, apenas em `push` para `main`, depois do
build+push concluídos.

| Item | Valor |
|---|---|
| Environment | `production` (aprovação obrigatória; branch policy: só `main`) |
| Secret | `PORTAINER_API_KEY` (header `X-API-Key`) |
| Variables | `PORTAINER_URL`, `PORTAINER_ENDPOINT_ID`, `PORTAINER_STACK_ID` |
| Entrada | `DIGEST` = `sha256:<hex>` do índice multi-arch; `IMAGE_REPO` = `ghcr.io/matizze/warm` |
| Saída | exit 0 somente com os 3 serviços no digest e `/up` 200; `previous_app_image` no `$GITHUB_OUTPUT` |

`DIGEST` vem do `docker/build-push-action` (`steps.<id>.outputs.digest`) ou de
`docker buildx imagetools inspect <imagem>:<sha>`. Nunca derive o digest de uma
tag móvel.

Endpoints usados (API do Portainer):

- `GET /api/stacks/{id}?endpointId={eid}` — stack e `Env` atual;
- `GET /api/stacks/{id}/file` — conteúdo atual do compose;
- `PUT /api/stacks/{id}?endpointId={eid}` com
  `{"stackFileContent": "...", "env": [...], "prune": false}` — troca
  `APP_IMAGE` e redeploya;
- `GET /api/endpoints/{eid}/docker/services` e `/tasks` — verificação.

### Verificação (imagem em execução = imagem construída)

1. `Spec.TaskTemplate.ContainerSpec.Image` de `warm_app`, `warm_worker` e
   `warm_scheduler` é **exatamente** `ghcr.io/matizze/warm@sha256:<digest>`
   (o Swarm preserva o digest do índice).
2. Cada serviço tem uma task `DesiredState=running` e `Status.State=running`.
3. `GET https://warm.matizze.com.br/up` responde 200.

Se a verificação falhar, o job falha — não considere o deploy concluído. **O
step não faz rollback automático**: a stack permanece no digest recém-aplicado e
o retorno é manual (seção Rollback), usando o `previous_app_image` capturado
antes do `PUT`.

Saída esperada (exemplo redigido, só os serviços do WARM):

```json
[
  {"service":"warm_app","image":"ghcr.io/matizze/warm@sha256:<digest>","update":"completed","running_tasks":1,"task_state":"running"},
  {"service":"warm_scheduler","image":"ghcr.io/matizze/warm@sha256:<digest>","update":"completed","running_tasks":1,"task_state":"running"},
  {"service":"warm_worker","image":"ghcr.io/matizze/warm@sha256:<digest>","update":"completed","running_tasks":1,"task_state":"running"}
]
```

### Step de referência

```bash
set -euo pipefail

: "${PORTAINER_URL:?}" "${PORTAINER_API_KEY:?}"
: "${PORTAINER_ENDPOINT_ID:?}" "${PORTAINER_STACK_ID:?}"
: "${DIGEST:?}" "${IMAGE_REPO:?}"

TARGET="${IMAGE_REPO}@${DIGEST}"
BASE="${PORTAINER_URL%/}/api"
API=(-sS --fail-with-body -H "X-API-Key: ${PORTAINER_API_KEY}")
HEALTH_URL="${APP_HEALTH_URL:-https://warm.matizze.com.br/up}"

stack_json="$(mktemp)"; file_json="$(mktemp)"; payload="$(mktemp)"
svcs_json="$(mktemp)"; tasks_json="$(mktemp)"
trap 'rm -f "$stack_json" "$file_json" "$payload" "$svcs_json" "$tasks_json"' EXIT

curl "${API[@]}" "${BASE}/stacks/${PORTAINER_STACK_ID}?endpointId=${PORTAINER_ENDPOINT_ID}" -o "$stack_json"
curl "${API[@]}" "${BASE}/stacks/${PORTAINER_STACK_ID}/file" -o "$file_json"

previous="$(jq -r '(.Env // [])[] | select(.name=="APP_IMAGE") | .value' "$stack_json" | head -1)"
echo "previous_app_image=${previous}" >> "${GITHUB_OUTPUT:-/dev/null}"

jq -n \
  --arg content "$(jq -r '.StackFileContent' "$file_json")" \
  --argjson env "$(jq -c --arg t "$TARGET" '((.Env // []) | map(select(.name!="APP_IMAGE"))) + [{name:"APP_IMAGE",value:$t}]' "$stack_json")" \
  '{stackFileContent:$content, env:$env, prune:false}' > "$payload"

curl "${API[@]}" -X PUT -H 'Content-Type: application/json' \
  --data-binary @"$payload" \
  "${BASE}/stacks/${PORTAINER_STACK_ID}?endpointId=${PORTAINER_ENDPOINT_ID}" > /dev/null

for _ in $(seq 1 60); do
  curl "${API[@]}" "${BASE}/endpoints/${PORTAINER_ENDPOINT_ID}/docker/services" -o "$svcs_json"
  curl "${API[@]}" "${BASE}/endpoints/${PORTAINER_ENDPOINT_ID}/docker/tasks" -o "$tasks_json"
  if jq -e -n --arg t "$TARGET" --slurpfile s "$svcs_json" --slurpfile k "$tasks_json" '
      ["warm_app","warm_worker","warm_scheduler"] as $names
      | ($s[0] | map(select(.Spec.Name as $n | $names | index($n)))) as $mine
      | ($mine | length == 3)
        and all($mine[]; .Spec.TaskTemplate.ContainerSpec.Image == $t)
        and all($mine[];
              (.ID as $id
               | ($k[0] | map(select(.ServiceID==$id and .DesiredState=="running")))
               | length == 1 and .[0].Status.State == "running"))
    ' > /dev/null; then
    code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "$HEALTH_URL" || true)"
    if [ "$code" = "200" ]; then
      echo "deployed ${TARGET}"
      exit 0
    fi
  fi
  sleep 5
done

echo "::error::deploy verification failed for ${TARGET}"
exit 1
```

## Escopo

O step troca somente `APP_IMAGE` (Environment variable do stack) e reenvia o
compose vivo inalterado; o compose do Portainer é a fonte de verdade. Mudanças
de compose (env, labels, serviços) continuam manuais, e hoje o stack vivo diverge
de `deployment/stack.yaml` (env hardcoded, sem labels do `/mcp`) — o deploy não
sincroniza essa diferença.

## Rollback

`previous_app_image` guarda o digest que estava em produção antes do deploy.
Rollback = rodar o mesmo step de deploy com `DIGEST` = digest anterior (novo
run do job, ou reexecução manual do step).

Alternativa sem workflow: no Portainer, **Stacks → warm → Environment
variables → `APP_IMAGE` = `ghcr.io/matizze/warm@sha256:<digest anterior>` →
Update the stack**.

Rollback que envolva migração de schema **não** é só repin: siga a seção
"Rollback de versão" do README (schema primeiro, imagem depois).

## Segurança

- O token fica somente no secret do environment `production`; nunca ecoar,
  logar ou usar `curl -v` / `set -x` no step.
- O corpo da stack contém variáveis de aplicação: não imprima o compose nem o
  payload do `PUT`.
- `:latest`/`:main` são tags móveis e não são usadas para deploy.
