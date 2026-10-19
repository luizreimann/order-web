#!/usr/bin/env bash
#
# Orquestra a suíte E2E do Playwright (item E4.5). Um comando, local ou CI:
#
#   npm run e2e                 (de src/)   ==   bash bin/e2e.sh
#   npm run e2e:update          regrava os snapshots visuais (revise o diff antes de commitar!)
#   npm run e2e -- --grep login argumentos extras vão direto ao `playwright test`
#
# O que faz, em ordem:
#   1. builda os assets (Vite) no host (o container PHP não tem Node);
#   2. sobe o servidor ISOLADO `e2e-app` (profile `e2e` do docker-compose: porta 8081) e o `db_test`;
#   3. cria o banco `wl_e2e` (no db_test, tmpfs), migra, semeia com o E2eSeeder e guarda uma cópia
#      pronta (`wl_e2e_tpl`) que os testes usam para restaurar o banco a cada teste;
#   4. roda os testes NO CONTAINER OFICIAL do Playwright (mesmo SO/fontes no Mac, no Linux e no CI:
#      é isso que torna os snapshots comparáveis entre máquinas);
#   5. derruba o servidor e apaga os bancos do E2E (mesmo se o teste falhar).
#
# NUNCA toca o banco `wl` (demo da Carol Doces) nem `wl_test` (suíte PHP).
#
# Variáveis: E2E_SKIP_BUILD=1 (reusa public/build), E2E_KEEP_UP=1 (não derruba ao final, p/ depurar),
#            E2E_SKIP_SETUP=1 (laço de desenvolvimento: NÃO sobe/semeia nada, só roda os testes contra o
#            ambiente que um `E2E_KEEP_UP=1` anterior deixou de pé).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PW_VERSION="$(node -p "require('./src/package.json').devDependencies['@playwright/test']")"
if ! [[ "$PW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "src/package.json: @playwright/test precisa de versão EXATA (achei '$PW_VERSION')." >&2
    exit 1
fi
PW_IMAGE="mcr.microsoft.com/playwright:v${PW_VERSION}-jammy"

DB_NAME="wl_e2e"
DB_TEMPLATE="wl_e2e_tpl"
COMPOSE=(docker compose --profile e2e)
PSQL=("${COMPOSE[@]}" exec -T -e PGPASSWORD=wl_test db_test psql -U wl_test -d postgres -v ON_ERROR_STOP=1 -q)

log() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

cleanup() {
    local status=$?
    if [[ "${E2E_KEEP_UP:-0}" == "1" ]]; then
        log "E2E_KEEP_UP=1: servidor mantido em http://localhost:8081 (derrube com: docker compose --profile e2e rm -sf e2e-app)"
    else
        log "Limpando o ambiente E2E"
        "${PSQL[@]}" -c "DROP DATABASE IF EXISTS ${DB_NAME} WITH (FORCE)" -c "DROP DATABASE IF EXISTS ${DB_TEMPLATE} WITH (FORCE)" >/dev/null 2>&1 || true
        "${COMPOSE[@]}" rm -sf e2e-app >/dev/null 2>&1 || true
    fi
    exit "$status"
}
trap cleanup EXIT

command -v docker >/dev/null || { echo "docker não encontrado." >&2; exit 1; }
[[ -f src/vendor/autoload.php ]] || { echo "src/vendor ausente: rode 'composer install' (docker compose exec app composer install)." >&2; exit 1; }

if [[ "${E2E_SKIP_SETUP:-0}" == "1" ]]; then
    E2E_KEEP_UP=1
    E2E_SKIP_BUILD=1
fi

if [[ "${E2E_SKIP_BUILD:-0}" != "1" ]]; then
    log "Build dos assets (Vite)"
    (cd src && { [[ -d node_modules ]] || npm ci; } && npm run build)
fi

if [[ "${E2E_SKIP_SETUP:-0}" != "1" ]]; then
    log "Subindo db_test e e2e-app (porta 8081)"
    docker image inspect order-web-app >/dev/null 2>&1 || docker compose build app
    "${COMPOSE[@]}" up -d --wait db_test
    "${COMPOSE[@]}" up -d --wait --force-recreate e2e-app

    log "Banco ${DB_NAME}: criar, migrar, semear"
    "${PSQL[@]}" -c "DROP DATABASE IF EXISTS ${DB_NAME} WITH (FORCE)" -c "DROP DATABASE IF EXISTS ${DB_TEMPLATE} WITH (FORCE)" -c "CREATE DATABASE ${DB_NAME}"
    "${COMPOSE[@]}" exec -T -w /var/www/html e2e-app php artisan migrate --force --no-interaction
    "${COMPOSE[@]}" exec -T -w /var/www/html e2e-app php artisan db:seed --class=E2eSeeder --force --no-interaction
    # Cópia limpa: cada teste restaura o banco a partir dela (DROP + CREATE ... TEMPLATE, ~100 ms).
    "${PSQL[@]}" -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '${DB_NAME}'" >/dev/null
    "${PSQL[@]}" -c "CREATE DATABASE ${DB_TEMPLATE} TEMPLATE ${DB_NAME}"
fi

NETWORK="$(docker inspect -f '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{end}}' wl_e2e_app)"

log "Playwright ${PW_VERSION} (${PW_IMAGE}) na rede ${NETWORK}"
docker image inspect "$PW_IMAGE" >/dev/null 2>&1 || docker pull "$PW_IMAGE"

set +e
docker run --rm --init --ipc=host \
    --network "$NETWORK" \
    -v "$ROOT/src:/work" -w /work \
    -e CI="${CI:-}" -e PLAYWRIGHT_JSON_OUTPUT_NAME \
    -e E2E_BASE_URL="http://e2e-app:8000" \
    -e E2E_DB_HOST="db_test" -e E2E_DB_PORT="5432" \
    -e E2E_DB_NAME="$DB_NAME" -e E2E_DB_TEMPLATE="$DB_TEMPLATE" \
    "$PW_IMAGE" \
    npx playwright test "$@"
STATUS=$?
set -e

exit "$STATUS"
