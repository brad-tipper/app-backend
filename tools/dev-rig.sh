#!/usr/bin/env bash
# Throwaway Docker test rig for an {app}-api project (used when DDEV is not handy).
#   tools/dev-rig.sh up <api-dir>      build image, start MariaDB + Mailpit + PHP server on :8080, build the schema
#   tools/dev-rig.sh test <api-dir>    reseed + run tests/flow.mjs
#   tools/dev-rig.sh sake <api-dir> …  run `vendor/bin/sake …` in the project
#   tools/dev-rig.sh down
# Expects the API project and app-backend side by side (../app-backend) and a composer.dev.json that
# path-links the module (see the migration PLAYBOOK in the documents repo). The project's .env must point SS_DATABASE_SERVER at ss-db
# and MAILER_DSN at smtp://ss-mail:1025.
set -euo pipefail
cmd="${1:-}"; shift || true
root="$(cd "$(dirname "$0")/../.." && pwd)"
win_root="$(cd "$root" && pwd -W 2>/dev/null || echo "$root")"
run() { MSYS_NO_PATHCONV=1 docker run --rm --network ss-net -v "$win_root:/w" -w "/w/$1" -e COMPOSER=composer.dev.json ss-php "${@:2}"; }

case "$cmd" in
  up)
    api="$1"
    docker build -t ss-php -f "$root/app-backend/tools/ss-php.Dockerfile" "$root/app-backend/tools"
    docker network create ss-net 2>/dev/null || true
    docker rm -f ss-db ss-web ss-mail >/dev/null 2>&1 || true
    docker run -d --name ss-db --network ss-net -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE="${api%-api}" mariadb:11.8 >/dev/null
    docker run -d --name ss-mail --network ss-net -p 8025:8025 axllent/mailpit >/dev/null
    sleep 8
    run "$api" composer update --no-interaction --no-progress --ignore-platform-reqs
    run "$api" vendor/bin/sake db:build --flush
    MSYS_NO_PATHCONV=1 docker run -d --name ss-web --network ss-net -p 8080:8080 -v "$win_root:/w" -w "/w/$api" ss-php \
      php -S 0.0.0.0:8080 -t public public/index.php >/dev/null
    echo "API on http://localhost:8080, Mailpit on http://localhost:8025" ;;
  test)
    api="$1"
    curl -s "localhost:8080/v1/health?flush=1" >/dev/null
    secret="$(grep -E '^E2E_SEED_SECRET' "$root/$api/.env" | cut -d'"' -f2)"
    curl -s -X POST localhost:8080/v1/_e2e/seed -H "X-E2E-Secret: ${secret:-local-e2e}" -d '{"users":[]}' >/dev/null
    node "$root/$api/tests/flow.mjs" ;;
  sake) api="$1"; shift; run "$api" vendor/bin/sake "$@" ;;
  down) docker rm -f ss-web ss-db ss-mail >/dev/null 2>&1 || true ;;
  *) sed -n 2,9p "$0"; exit 1 ;;
esac
