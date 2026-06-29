# Deploy

## Production (current setup)

Live since 2026-06-12 on `root@164.92.219.14` (DigitalOcean, 2 GB / 2 vCPU), domain `dicta-voluptatem-voluptatem.top` proxied through Cloudflare.

**⚠️ The box is shared.** It also runs **aio-support-brain** (its nginx owns port :80, compose project in `/var/www/aio-support-brain`) and a host mysqld on :3306. Never touch those. Everything fa-stats-bot is scoped to the `fa-stats-bot` compose project in `/opt/fa-stats-bot` and publishes **only port 443**.

| Piece | Value |
|---|---|
| Checkout | `/opt/fa-stats-bot` (git, deploy key `~/.ssh/fa_stats_bot`, host alias `github.com-fa-stats`) |
| Compose | `docker-compose.prod.yml` — app/worker/scheduler + postgres + redis + **caddy:443** |
| TLS | Caddy `tls internal` (self-signed); Cloudflare zone SSL mode **Full** (not strict). ACME is impossible — :80 belongs to the neighbour. |
| Assets | `public/build` is **committed** (no Node on the box) — run `make assets` after touching `resources/` |
| RAM | 2 GB + 2 GB swapfile (`/swapfile`); per-container `mem_limit` in compose |

**Day-to-day deploy from your machine:**

```bash
make deploy        # = make assets (vite build + commit if changed) → git push → server: scripts/deploy.sh
make logs          # tail prod app/worker/scheduler
make status        # containers + /health
```

`scripts/deploy.sh` (runs on the server, idempotent): git pull → re-exec fresh copy → chown for uid 1000 → composer install → migrate → up -d → config/route/event:cache → octane:reload → health check.

One-off commands on prod:

```bash
ssh root@164.92.219.14 'cd /opt/fa-stats-bot && docker compose -f docker-compose.prod.yml exec app php artisan <cmd>'
```

**Changing `.env` on prod:** edit `/opt/fa-stats-bot/.env`, then **force-recreate** the app containers — `config:cache`/`octane:reload`/`restart` are NOT enough. Compose injects `env_file` vars into the container environment at *create* time, and Laravel's immutable dotenv won't override an already-set env var, so the old value sticks until the container is recreated:

```bash
docker compose -f docker-compose.prod.yml up -d --force-recreate app worker scheduler
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
```

Telegram wiring (already done; re-run only after URL/secret changes):

```bash
php artisan tg:set-webhook      # TELEGRAM_WEBHOOK_URL + secret (NOT nutgram:hook:set — that one ignores our secret)
php artisan tg:set-commands     # slash-command menu
php artisan tg:set-menu-button  # Mini App button → APP_URL/app
```

---

## Generic guide

Generic Docker-Compose-on-VPS guide. The same image runs locally and in production — the only differences are env vars, the host that terminates TLS, and the webhook URL registered with Telegram.

## 1. Prerequisites

On the target host:

- Linux VPS (Ubuntu 22.04+ / Debian 12+ tested; anything with kernel ≥ 5.x works)
- Docker Engine ≥ 24 + Compose v2
- A domain (or subdomain) with an A/AAAA record pointing at the VPS — Telegram requires HTTPS for webhooks
- Outbound HTTPS to `api.telegram.org`, `app.aio.tech`, and `api.anthropic.com`
- Inbound 443/tcp open (and 80/tcp if you let FrankenPHP do ACME directly)

In hand:

- Telegram bot token from [@BotFather](https://t.me/BotFather)
- AIO API token
- Anthropic API key (for `/ai`)
- Your own Telegram user id (or username) for the allowlist

## 2. Initial setup

```bash
ssh deploy@your-host
git clone <repo-url> /opt/fa-stats-bot
cd /opt/fa-stats-bot
cp .env.example .env
```

Edit `.env`. Production-relevant fields:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://bot.example.com
APP_KEY=                          # filled by `php artisan key:generate` below

DB_PASSWORD=<long-random-string>  # do NOT keep the example default

TELEGRAM_TOKEN=...
TELEGRAM_BOT_USERNAME=aio_fa_stats_bot
TELEGRAM_WEBHOOK_URL=https://bot.example.com/telegram/webhook
TELEGRAM_WEBHOOK_SECRET=<long-random-string>
TELEGRAM_ALLOWED_USER_IDS=123456789
TELEGRAM_ALLOWED_USERNAMES=

AIO_TOKEN=...

ANTHROPIC_API_KEY=...
```

Generate secrets:

```bash
openssl rand -hex 32   # → TELEGRAM_WEBHOOK_SECRET
openssl rand -hex 24   # → DB_PASSWORD
```

## 3. First boot

```bash
docker compose build
docker compose run --rm app composer install --no-dev --optimize-autoloader
docker compose run --rm app php artisan key:generate

# Mini App assets — built once, served as static files from public/build/.
# Node lives on the host (no node in the production image), so install + build
# from the working directory:
npm ci
npm run build

docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan event:cache
```

Verify:

```bash
docker compose ps
curl -sf http://127.0.0.1:8000/health | jq
# {"ok":true,"components":{"database":{"ok":true},"redis":{"ok":true}}}
curl -sI http://127.0.0.1:8000/app | head -1
# HTTP/1.1 200 OK  ← Mini App shell renders
```

## 4. TLS

Two options. Pick one.

### A) FrankenPHP terminates TLS directly

Simplest — FrankenPHP has Caddy embedded and can do ACME on its own. Edit `docker-compose.yml` to expose 80/443 instead of 8000, and set `SERVER_NAME=bot.example.com` so FrankenPHP uses HTTPS:

```yaml
services:
  app:
    ports:
      - "80:80"
      - "443:443"
    environment:
      SERVER_NAME: bot.example.com
```

Restart: `docker compose up -d app`. FrankenPHP fetches a Let's Encrypt cert on first request. Cert state lives in the `/data/caddy` volume — add a named volume if you want it to survive `down -v`.

### B) Reverse proxy in front (Caddy / nginx / Traefik on the host)

Keep `app` on `127.0.0.1:8000` (drop the `0.0.0.0` binding) and proxy from the host. Minimal Caddy config:

```caddy
bot.example.com {
    reverse_proxy 127.0.0.1:8000
}
```

Use this if you already run a reverse proxy on the box, or if you want to share TLS with other services.

## 5. Register the Telegram webhook + Mini App

```bash
docker compose exec app php artisan nutgram:hook:set
docker compose exec app php artisan nutgram:hook:info
```

`hook:set` posts `TELEGRAM_WEBHOOK_URL` and `TELEGRAM_WEBHOOK_SECRET` to Telegram. From this point Telegram will deliver updates with the `X-Telegram-Bot-Api-Secret-Token` header; the [VerifyTelegramWebhook](app/Http/Middleware/VerifyTelegramWebhook.php) middleware rejects anything else with 403.

Register the slash-command menu (one-off):

```bash
docker compose exec app php artisan nutgram:register-commands
```

Wire up the bot's menu button (the icon next to the message field) to open the Mini App:

```bash
docker compose exec app php artisan tg:set-menu-button
# uses APP_URL/app — pass --url to override
```

Telegram requires HTTPS for Mini App URLs — the command refuses if `APP_URL` is non-HTTPS.

Smoke test from your phone:
- `/ping` → `pong 🏓`
- `/open` → tap → Mini App opens, lists your aliases / bindings

The Mini App authenticates each `/api/v1/*` request by verifying `initData` HMAC against `TELEGRAM_TOKEN`. If verification ever fails after a token rotation, force-close the Mini App from Telegram (Settings → Telegram → Mini Apps) — the cached old token is the usual culprit.

## 6. Monitoring

- **Liveness**: `GET /health` returns 200 when DB and Redis both respond, 503 otherwise. Wire this into your uptime monitor (UptimeRobot, BetterStack, healthchecks.io, …).
- **Logs**: `docker compose logs -f app worker scheduler`. Anthropic / AIO / Telegram errors land in stderr → Docker's json-file driver. Ship to Loki / CloudWatch / Papertrail by configuring the daemon's log driver if you want retention.
- **Rate limit signals**: search logs for `ai.tool_loop.cap_hit` (AI tool loop hit the 6-step cap) and 429s from AIO (AIO budget exhausted).

## 7. Update workflow

```bash
cd /opt/fa-stats-bot
git pull
docker compose build app
docker compose run --rm app composer install --no-dev --optimize-autoloader
npm ci && npm run build               # only if resources/js or resources/css changed
docker compose exec app php artisan migrate --force
docker compose exec app php artisan config:cache route:cache event:cache
docker compose up -d app worker scheduler
docker compose exec app php artisan octane:reload
```

`octane:reload` is a graceful worker-pool restart — open requests finish on the old workers, new requests hit the new code. Avoid `restart` unless you changed `Dockerfile` or env.

## 8. Backups

The `pgdata` named volume holds Postgres state. Daily dump to a sibling host or object storage:

```bash
docker compose exec -T postgres \
    pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" --format=custom \
    > /var/backups/fa-stats-$(date +%F).dump
```

Cron it. Restore: `pg_restore -U $DB_USERNAME -d $DB_DATABASE --clean < dump`.

Redis holds rate-limit windows and AIO response cache — disposable. No backup needed; on restart the cache fills back up and rate-limit windows reset.

## 9. Troubleshooting

| Symptom | Likely cause | Check |
|---|---|---|
| Telegram menu shows commands but bot is silent | Webhook not registered or wrong URL | `nutgram:hook:info` |
| Bot replies to `/ping` but `/stats` errors out | AIO token wrong, expired, or rate-limited | `docker compose logs app \| grep -i aio` |
| `/ai` answers «Слишком много шагов, прерываю.» | Tool loop hit the 6-step cap | log line `ai.tool_loop.cap_hit` |
| `/ai` says «Слишком много запросов» | Per-user Anthropic limit hit | `ANTHROPIC_RATE_LIMIT` / `_WINDOW_SECONDS` |
| `/health` returns 503 with `database.ok: false` | Postgres down or migration failed | `docker compose ps`, `docker compose logs postgres` |
| 403 on `/telegram/webhook` from real Telegram | Secret drift between `.env` and registered webhook | re-run `nutgram:hook:set` |
| Container restart loop after deploy | Stale cached config | `php artisan config:clear && config:cache` |
| Mini App shows blank / 401 from `/api/v1/me` | `TELEGRAM_TOKEN` not yet loaded into the container, or token rotated | `docker compose exec app php artisan config:cache` then force-close Mini App in Telegram |
| Mini App page loads but no styles | Forgot `npm run build` after a `git pull` | run it, then `octane:reload` |

## 10. External resources

- [Telegram Bot API — webhooks](https://core.telegram.org/bots/api#setwebhook)
- [Nutgram docs](https://nutgram.dev)
- [Laravel Octane — FrankenPHP](https://laravel.com/docs/octane#frankenphp)
- [Anthropic Messages API](https://docs.anthropic.com/en/api/messages)
