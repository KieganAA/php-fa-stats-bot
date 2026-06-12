# Deploy automation. Day-to-day flow:
#
#   make deploy        # assets (if changed) → push → server pulls & reloads
#
# Pieces:
#   make assets        # vite build + commit public/build if it changed
#   make push          # push master to GitHub (deploy key alias)
#   make deploy-server # ssh to prod and run scripts/deploy.sh (git pull there)
#   make logs          # tail prod app/worker/scheduler logs
#   make status        # prod containers + health
#
# Prod box (164.92.219.14) also hosts aio-support-brain — scripts/deploy.sh
# only ever touches the fa-stats-bot compose project.

SERVER  := root@164.92.219.14
APP_DIR := /opt/fa-stats-bot
COMPOSE := docker compose -f $(APP_DIR)/docker-compose.prod.yml --project-directory $(APP_DIR)

.PHONY: deploy assets push deploy-server logs status test

deploy: assets push deploy-server

assets:
	npm run build
	@if ! git diff --quiet public/build 2>/dev/null || [ -n "$$(git status --porcelain public/build)" ]; then \
		git add public/build && git commit -m "build: mini app assets" public/build; \
		echo "assets committed"; \
	else \
		echo "assets unchanged"; \
	fi

push:
	@if [ -n "$$(git status --porcelain --untracked-files=no)" ]; then \
		echo "ERROR: uncommitted changes — commit them first"; exit 1; \
	fi
	git push master master

deploy-server:
	ssh $(SERVER) 'bash $(APP_DIR)/scripts/deploy.sh'

logs:
	ssh $(SERVER) '$(COMPOSE) logs -f --tail=100 app worker scheduler'

status:
	ssh $(SERVER) '$(COMPOSE) ps && $(COMPOSE) exec -T app php artisan tinker --execute="echo file_get_contents(\"http://127.0.0.1:8000/health\");" 2>/dev/null | tail -1'

test:
	docker exec fa-stats-bot-app-1 php artisan test
