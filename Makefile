# NEXORA Distribution OS — Makefile
# Local development and CI helper targets.
# Requires: make, docker, docker-compose, node/npm, composer, php 8.3

.PHONY: help backend-serve backend-test backend-migrate backend-seed \
        frontend-dev frontend-build frontend-lint frontend-test-e2e \
        docker-up docker-down docker-logs db-migrate db-seed \
        install-local start-local stop-local clean-test

help: ## Show available make targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-28s\033[0m %s\n", $$1, $$2}'

# ---------------- Backend ----------------

backend-serve: ## Start Laravel API on http://127.0.0.1:8017
	cd backend && ../.tools/php83/php.exe -c ../.tools/php83/php.ini artisan serve --host=127.0.0.1 --port=8017

backend-test: ## Run backend PHPUnit suite
	cd backend && ../.tools/php83/php.exe -c ../.tools/php83/php.ini artisan test

backend-migrate: ## Run backend migrations
	cd backend && ../.tools/php83/php.exe -c ../.tools/php83/php.ini artisan migrate

backend-seed: ## Run backend seeders
	cd backend && ../.tools/php83/php.exe -c ../.tools/php83/php.ini artisan db:seed

backend-lint: ## Run Pint code style check on backend
	cd backend && ../.tools/php83/php.exe -c ../.tools/php83/php.ini vendor/bin/pint --test

# ---------------- Frontend ----------------

frontend-dev: ## Start Next.js dev on http://127.0.0.1:3017
	cd frontend && npm run dev -- --hostname 127.0.0.1 --port 3017

frontend-build: ## Next.js production build
	cd frontend && npm run build

frontend-lint: ## TypeScript type check (tsc --noEmit)
	cd frontend && npx tsc --noEmit

frontend-test-e2e: ## Playwright E2E suite
	cd frontend && npm run test:e2e

# ---------------- Docker ----------------

docker-up: ## Start full stack with Docker Compose
	docker compose up -d

docker-down: ## Stop and remove Docker Compose services
	docker compose down

docker-logs: ## Follow Docker Compose logs
	docker compose logs -f

# ---------------- Database ----------------

db-migrate: ## Run migrations on local Docker MySQL
	docker compose exec backend php artisan migrate

db-seed: ## Run seeders on local Docker MySQL
	docker compose exec backend php artisan db:seed

# ---------------- Local Windows helper scripts ----------------

install-local: ## Run Windows local setup script
	pwsh -File tools/setup-windows.ps1

start-local: ## Start local API + frontend services
	pwsh -File tools/start-local.ps1

stop-local: ## Stop local repository services
	pwsh -File tools/stop-local.ps1

clean-test: ## Remove temporary test databases/artifacts
	@echo "Cleaning temporary test artifacts..."
	@if exist backend\database\distribution-local.sqlite del backend\database\distribution-local.sqlite
	@if exist frontend\.next rmdir /s /q frontend\.next
