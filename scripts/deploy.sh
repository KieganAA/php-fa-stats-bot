#!/usr/bin/env bash
# Production deploy — runs ON THE SERVER (invoked by `make deploy` over ssh,
# or by hand: bash scripts/deploy.sh). Idempotent; safe to re-run.
#
# This box also hosts aio-support-brain — every docker command below is scoped
# to this compose project (-p fa-stats-bot + explicit -f), so the neighbours
# are never touched.
set -euo pipefail

APP_DIR="/opt/fa-stats-bot"
COMPOSE=(docker compose -f docker-compose.prod.yml)

cd "$APP_DIR"

# git pull may update THIS file mid-run; bash reads scripts streamingly, so we
# re-exec the fresh copy right after pulling (once — guarded by the flag).
if [ "${1:-}" != "--post-pull" ]; then
    echo "==> git pull"
    git config --global --add safe.directory "$APP_DIR" 2>/dev/null || true
    git pull --ff-only
    exec bash "$APP_DIR/scripts/deploy.sh" --post-pull
fi

echo "==> fix ownership (app container runs as uid 1000; checkout is root's)"
mkdir -p vendor storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chown -R 1000:1000 .

echo "==> composer install"
"${COMPOSE[@]}" run --rm --no-deps app composer install --no-dev --optimize-autoloader --no-interaction

echo "==> migrate"
"${COMPOSE[@]}" up -d postgres redis
"${COMPOSE[@]}" run --rm app php artisan migrate --force

echo "==> start/refresh services"
"${COMPOSE[@]}" up -d --remove-orphans

echo "==> rebuild caches"
"${COMPOSE[@]}" exec -T app php artisan config:cache
"${COMPOSE[@]}" exec -T app php artisan route:cache
"${COMPOSE[@]}" exec -T app php artisan event:cache

echo "==> reload octane workers"
"${COMPOSE[@]}" exec -T app php artisan octane:reload || true

echo "==> health"
sleep 2
"${COMPOSE[@]}" exec -T app php artisan tinker --execute='
$h = json_decode(file_get_contents("http://127.0.0.1:8000/health"), true);
echo ($h["ok"] ?? false) ? "health OK\n" : ("health FAIL: ".json_encode($h)."\n");
' 2>/dev/null | tail -1 || echo "health probe skipped"

echo "==> done"
"${COMPOSE[@]}" ps --format 'table {{.Name}}\t{{.Status}}'
