# fa-stats-bot

Telegram-бот для быстрой работы со статистикой из [AIO](https://app.aio.tech) без сжигания токенов на каждом запросе. Три режима:

1. **Типовые команды без AI** — `/stats`, `/bind`, `/compare`, `/mvt`. Предсказуемо, дёшево, мгновенно.
2. **Периодические MVT-срезы** — подписка на 3-часовые отчёты по забинженным лендингам.
3. **AI-режим (Claude Haiku)** — свободные запросы вида «че там по DK сегодня» или «кто сегодня проебал бабки» разруливаются через tool use: модель вызывает те же функции, что и команды.

## Стек

- PHP 8.4, Laravel 13
- FrankenPHP (через Laravel Octane) — один long-running воркер на HTTP-webhook
- PostgreSQL 16 — биндинги, алиасы, снэпшоты MVT, whitelist
- Redis 7 — кэш ответов AIO, очередь, сессии
- [Nutgram](https://nutgram.dev) — Telegram-клиент
- Claude Haiku 4.5 через прямой HTTP (Laravel Http client)
- Guzzle — клиент к AIO
- Docker Compose — весь стек одной командой

## Структура сервисов

| Сервис      | Что делает                                                  | Образ                          |
|-------------|-------------------------------------------------------------|--------------------------------|
| `app`       | HTTP webhook `/telegram/webhook` на Octane+FrankenPHP        | собирается из `Dockerfile`     |
| `worker`    | `queue:work redis` — асинхронные отправки отчётов           | тот же образ                   |
| `scheduler` | `schedule:work` — запускает 3-часовые MVT-срезы             | тот же образ                   |
| `bot`       | `nutgram:listen` — polling + hot reload (dev без ngrok)     | тот же образ, profile `polling`|
| `postgres`  | БД                                                           | `postgres:16-alpine`           |
| `redis`     | Кэш и очередь                                                | `redis:7-alpine`               |

## Быстрый старт (локально)

### 1. Требования

- Docker Desktop / Docker Engine + Compose v2
- Токен бота от [@BotFather](https://t.me/BotFather) — уже есть для `aio_fa_stats_bot`
- Токен AIO — уже в `.env`
- (опционально для webhook-режима) [ngrok](https://ngrok.com) или любой HTTPS-туннель

### 2. Клонирование и конфиг

```bash
git clone <repo-url> fa-stats-bot
cd fa-stats-bot
cp .env.example .env
```

Открой `.env` и заполни:

- `TELEGRAM_TOKEN` — токен от BotFather
- `AIO_TOKEN` — API-токен AIO
- `TELEGRAM_ALLOWED_USER_IDS` — свой TG user id (узнать: отправь что-нибудь [@userinfobot](https://t.me/userinfobot))
- `ANTHROPIC_API_KEY` — появится на фазе AI, можно оставить пустым

При первом запуске установи composer-зависимости и сгенерируй `APP_KEY`:

```bash
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
```

### 3. Запуск

```bash
docker compose up -d --build
docker compose logs -f app
```

Проверка:

```bash
curl http://localhost:8000/health
# {"ok":true}
```

### 4. Подключить бота к Telegram

**Вариант A — polling (проще, без публичного URL)**

```bash
docker compose --profile polling up bot
```

Пишешь боту в TG — он отвечает. Остановка: Ctrl+C.

**Вариант B — webhook (production-like)**

Открой туннель до `localhost:8000`:

```bash
ngrok http 8000
```

Запиши HTTPS URL (`https://xxxx.ngrok-free.app`) в `.env` → `TELEGRAM_WEBHOOK_URL`, перезапусти `app` и зарегистрируй вебхук:

```bash
docker compose restart app
docker compose exec app php artisan nutgram:hook:set
docker compose exec app php artisan nutgram:hook:info
```

Чтобы снять:

```bash
docker compose exec app php artisan nutgram:hook:remove
```

### 5. Зарегистрировать меню команд в TG

```bash
docker compose exec app php artisan nutgram:register-commands
```

## Что уже работает (Фаза 0)

- `/start` — приветствие
- `/ping` — `pong 🏓`
- `/help` — список команд
- Любое другое сообщение — фолбэк
- Whitelist по `TELEGRAM_ALLOWED_USER_IDS`

## Roadmap (фазы)

- [x] **0** — Каркас: Laravel + FrankenPHP + Docker + webhook-заглушка
- [x] **1** — AIO-клиент с Redis-кэшем, rate-limit, concurrency-limit
- [x] **2** — Алиасы лендингов (`/alias add/list/remove`), БД-схема
- [x] **3** — `/stats <alias> [период]` — основной юзкейс
- [ ] **4** — `/bind`, `/subscribe`, планировщик 3h, снэпшоты MVT
- [x] **5** — `/compare <alias1> <alias2> [...] [период]`
- [x] **6** — AI-режим через Claude tool use
- [x] **7** — Rate-limit, health-checks, прод-готовность
- [x] **8** — [`DEPLOY.md`](DEPLOY.md) — деплой на VPS

## Повседневные команды

```bash
# Логи
docker compose logs -f app

# Перезапуск после правок кода (Octane кэширует bootstrapped state)
docker compose restart app worker scheduler

# Или мягкий reload воркеров Octane
docker compose exec app php artisan octane:reload

# Artisan внутри контейнера
docker compose exec app php artisan <cmd>

# Composer
docker compose exec app composer <cmd>

# Миграции
docker compose exec app php artisan migrate

# Tinker
docker compose exec app php artisan tinker

# Остановить всё
docker compose down

# Снести вместе с volumes (⚠️ потеряешь БД)
docker compose down -v
```

## Деплой

См. [`DEPLOY.md`](DEPLOY.md) — generic Docker-Compose-on-VPS гайд: TLS (FrankenPHP сам или через прокси), регистрация webhook, обновление, бэкапы, troubleshooting.

## Лицензия

MIT.
