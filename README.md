# fa-stats-bot

Telegram-бот + Mini App для быстрой работы со статистикой из [AIO](https://app.aio.tech) без сжигания токенов на каждом запросе. Команда отвечает в чате, на интерактивной форме внутри Telegram, или свободным вопросом к AI — модель за кулисами зовёт те же функции, что и команды.

## Что внутри

1. **Mini App (Telegram WebApp)** — `/open` или кнопка меню. Полная фича-парити с командами: смотреть статы, сравнивать лендинги, рулить алиасами и биндингами, править свои настройки. Vue 3 + Tailwind, ~36kb gzipped.
2. **Команды без AI** — `/stats`, `/compare`, `/alias`, `/bind`, `/unbind`, `/bindings`, `/mvt`. Предсказуемо, дёшево, мгновенно.
3. **Периодические 3h-снэпшоты** — `tracking:snapshot` раз в 3 часа: дёргает AIO один раз на лендинг и веером раздаёт diff'ы подписчикам через redis-очередь.
4. **AI-режим (Claude Haiku 4.5)** — `/ai <вопрос>`. Свободные запросы вида «че там по DK сегодня» разруливаются через tool use.

## Multi-user

База заточена под несколько юзеров:

- **Алиасы — общие** (`landing_aliases.created_by_id` хранит автора, но видны всем в команде). Один словарь на всех — никто не пересоздаёт `dk-blue` для себя.
- **Биндинги, подписки, AI-история — личные** (`user_landing_bindings.user_id` FK на `users.id`). Каждый сам решает, какие лендинги мониторить и куда слать пуши.
- **Кост AIO — общий** (`tracked_landings` shared). 5 человек подписались на тот же лендинг = 1 AIO-запрос в цикл, не 5.

Аутентификация:

- В чате — по `bot->user()` + middleware `TelegramUserResolver` upsert'ит юзера на каждое сообщение
- В Mini App — `initData` верифицируется HMAC-SHA256 с bot token'ом ([спека](https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app))

Allowlist (`TELEGRAM_ALLOWED_USER_IDS` / `TELEGRAM_ALLOWED_USERNAMES`) пока остаётся бинарным (пускаем/не пускаем) — это первый этап на пути к workspace'ам.

## Стек

- PHP 8.4, Laravel 13, FrankenPHP через Laravel Octane
- PostgreSQL 16, Redis 7
- [Nutgram](https://nutgram.dev) — Telegram-клиент
- Claude Haiku 4.5 через прямой HTTP
- Vue 3 + vue-router 4 + Vite 8 + Tailwind 4 — Mini App
- Docker Compose — весь стек одной командой

## Структура сервисов

| Сервис      | Что делает                                                  | Образ                          |
|-------------|-------------------------------------------------------------|--------------------------------|
| `app`       | HTTP webhook `/telegram/webhook` + Mini App `/app` + REST `/api/v1/*` на Octane+FrankenPHP | собирается из `Dockerfile` |
| `worker`    | `queue:work redis` — асинхронные пуш-уведомления             | тот же образ                   |
| `scheduler` | `schedule:work` — `tracking:snapshot` каждые 3ч              | тот же образ                   |
| `bot`       | `nutgram:listen` — polling (dev без публичного URL)          | тот же образ, profile `polling`|
| `postgres`  | БД                                                           | `postgres:16-alpine`           |
| `redis`     | Кэш и очередь                                                | `redis:7-alpine`               |

## Быстрый старт (локально)

### 1. Требования

- Docker Desktop / Docker Engine + Compose v2
- Node.js 20+ (для сборки фронта)
- Токен бота от [@BotFather](https://t.me/BotFather)
- Токен AIO
- (для webhook-режима) ngrok или любой HTTPS-туннель

### 2. Клонирование и конфиг

```bash
git clone <repo-url> fa-stats-bot
cd fa-stats-bot
cp .env.example .env
```

Открой `.env` и заполни:

- `TELEGRAM_TOKEN`
- `AIO_TOKEN`
- `TELEGRAM_ALLOWED_USER_IDS` — свой TG user id ([@userinfobot](https://t.me/userinfobot))
- `ANTHROPIC_API_KEY` — для `/ai`
- `APP_URL` — нужен для Mini App. В деве — твой ngrok URL.

При первом запуске:

```bash
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
npm install
npm run build
```

### 3. Запуск

```bash
docker compose up -d --build
docker compose logs -f app
```

Проверка:

```bash
curl http://localhost:8000/health
# {"ok":true,"components":{...}}
```

### 4. Подключить бота к Telegram

**Polling (без публичного URL):**

```bash
docker compose --profile polling up bot
```

**Webhook (production-like):**

```bash
ngrok http 8000
# скопируй HTTPS URL → APP_URL и TELEGRAM_WEBHOOK_URL в .env
docker compose restart app
docker compose exec app php artisan nutgram:hook:set
```

### 5. Зарегистрировать меню команд и кнопку Mini App

```bash
docker compose exec app php artisan nutgram:register-commands
docker compose exec app php artisan tg:set-menu-button
```

### 6. Фронт в dev-режиме

```bash
npm run dev
```

Vite поднимает HMR на 5173, билд автоматом подтягивается в blade-шаблон. Если работаешь в реальном Telegram-клиенте — лучше `npm run build` после правок (HMR через ngrok не очень стабилен).

## Команды бота

| Команда | Что делает |
|---|---|
| `/open` | Открыть Mini App кнопкой |
| `/stats <alias> [период]` | Метрики лендинга |
| `/compare <a> <b> [...] [период]` | Сравнить лендинги на одной LP |
| `/alias add\|list\|rm` | Управление алиасами |
| `/bind <alias> [silent]` | Отслеживать (3h-пуш) |
| `/unbind <alias>` | Перестать отслеживать |
| `/bindings` | Мои биндинги |
| `/mvt <alias>` | Последний снэпшот |
| `/ai <вопрос>` | Свободный запрос (Claude Haiku tool-use) |
| `/ping` | pong 🏓 |
| `/help` | Справка |

Периоды: `today` (default), `yesterday`, `24h`, `7d`, `week`, `month`.

## Roadmap (фазы)

- [x] **0** — Каркас: Laravel + FrankenPHP + Docker + webhook-заглушка
- [x] **1** — AIO-клиент с Redis-кэшем, rate-limit, concurrency-limit
- [x] **2** — Алиасы лендингов, БД-схема
- [x] **3** — `/stats <alias> [период]`
- [x] **4** — Биндинги, 3h-снэпшоты, scheduler, fan-out через очередь
- [x] **5** — `/compare`
- [x] **6** — AI-режим через Claude tool use
- [x] **7** — Rate-limit, health-checks, прод-готовность
- [x] **8** — [`DEPLOY.md`](DEPLOY.md)
- [x] **9** — Multi-user foundation + Mini App (Vue 3 + Tailwind + WebApp auth)

## Повседневные команды

```bash
# Логи
docker compose logs -f app worker scheduler

# Reload Octane без перезапуска контейнера
docker compose exec app php artisan octane:reload

# Миграции
docker compose exec app php artisan migrate

# Снэпшот вручную (без --no-notify — рассылает в чаты)
docker compose exec app php artisan tracking:snapshot --no-notify

# Tinker
docker compose exec app php artisan tinker

# Тесты
docker compose exec app php artisan test

# Фронт
npm run build      # production
npm run dev        # HMR

# Стоп
docker compose down
docker compose down -v   # ⚠️ снесёт БД
```

## Деплой

См. [`DEPLOY.md`](DEPLOY.md) — generic Docker-Compose-on-VPS гайд: TLS (FrankenPHP сам или через прокси), регистрация webhook, кнопка Mini App, мониторинг через `/health`, обновление, бэкапы, troubleshooting.

## Лицензия

MIT.
