# =============================================================================
# SurgicalHub — Makefile
# =============================================================================
# Requires: Docker, Docker Compose v2
# Windows : run from Git Bash (cmd.exe / PowerShell not supported)
#
# Usage:
#   make <target>
#   make console cmd="about"
#   make composer cmd="require vendor/package"
#   make npm     cmd="run build"
#   make php     cmd="php -v"
# =============================================================================

SHELL := /bin/sh

DC      := docker compose
PHP     := $(DC) exec php
FRONT   := $(DC) exec frontend
CONSOLE := $(PHP) php bin/console

.DEFAULT_GOAL := help

.PHONY: help \
        up down restart build rebuild logs ps \
        shell php console composer \
        npm frontend-shell \
        migrate migration-diff migration-status \
        fixtures dev-user \
        test-backend test-frontend \
        phpstan lint clear-cache \
        messenger messenger-restart messenger-stop messenger-logs \
        redis-ping mysql-shell \
        tools-up phpmyadmin-url \
        doctor \
        reset-volumes

# =============================================================================
# Help
# =============================================================================

help: ## Show available commands
	@echo ""
	@echo "SurgicalHub — Docker Makefile"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| sort \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-22s\033[0m %s\n", $$1, $$2}'
	@echo ""
	@echo "  Ports:  API=http://localhost:8080   Frontend=https://localhost:5173"
	@echo "          MySQL=localhost:3308        Redis=localhost:6380"
	@echo "          Mailpit=http://localhost:8026"
	@echo "          phpMyAdmin=http://localhost:8081  (make tools-up)"
	@echo ""

# =============================================================================
# General
# =============================================================================

up: ## Start all services in background
	$(DC) up -d

down: ## Stop and remove containers  (volumes are kept)
	$(DC) down

restart: down up ## Restart all services

build: ## Build Docker images
	$(DC) build

rebuild: ## Force-rebuild Docker images  (--no-cache)
	$(DC) build --no-cache

logs: ## Follow logs of all services  [Ctrl+C to stop]
	$(DC) logs -f

ps: ## Show status of all containers
	$(DC) ps

# =============================================================================
# PHP / Backend
# =============================================================================

shell: ## Open a shell inside the php container
	$(PHP) sh

php: ## Run a raw command in the php container.  Usage: make php cmd="php -v"
	$(PHP) $(cmd)

console: ## Run a Symfony console command.  Usage: make console cmd="about"
	$(CONSOLE) $(cmd)

composer: ## Run a Composer command.  Usage: make composer cmd="install"
	$(PHP) composer $(cmd)

# =============================================================================
# Frontend
# =============================================================================

npm: ## Run an npm command in the frontend container.  Usage: make npm cmd="run build"
	$(FRONT) npm $(cmd)

frontend-shell: ## Open a shell inside the frontend container
	$(FRONT) sh

# =============================================================================
# Migrations  (never run automatically)
# =============================================================================

migrate: ## Run pending Doctrine migrations
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

migration-diff: ## Generate a new migration from entity diff
	$(CONSOLE) doctrine:migrations:diff

migration-status: ## Show current migration status
	$(CONSOLE) doctrine:migrations:status

# =============================================================================
# Fixtures
# =============================================================================

fixtures: ## Load Doctrine fixtures
	@echo ""
	@echo "  NOTE: doctrine/doctrine-fixtures-bundle is not installed."
	@echo "  Install it first:"
	@echo "    make composer cmd=\"require --dev doctrine/doctrine-fixtures-bundle\""
	@echo ""

dev-user: ## Create/update the local dev admin/manager account (admin@surgicalhub.local)
	$(CONSOLE) app:create-dev-user

# =============================================================================
# Tests
# =============================================================================

test-backend: ## Run PHP unit tests  (PHPUnit via bin/phpunit wrapper)
	$(PHP) php bin/phpunit

test-frontend: ## Run frontend tests, non-interactive  (Vitest run)
	$(FRONT) npm run test:run

# =============================================================================
# Code quality
# =============================================================================

phpstan: ## Run PHPStan static analysis
	@echo ""
	@echo "  NOTE: phpstan/phpstan is not installed."
	@echo "  Install it first:"
	@echo "    make composer cmd=\"require --dev phpstan/phpstan\""
	@echo ""

lint: ## TypeScript type check (no ESLint / PHP CS Fixer configured yet)
	$(FRONT) npx tsc --noEmit

clear-cache: ## Clear Symfony application cache
	$(CONSOLE) cache:clear

# =============================================================================
# Messenger
# =============================================================================

messenger: ## Run Messenger consumer interactively  (async transport, verbose)
	$(CONSOLE) messenger:consume async -vv

messenger-restart: ## Restart the background messenger worker — required after editing any Messenger handler or Twig template it renders (see docs/docker.md §9)
	$(DC) restart messenger

messenger-stop: ## Stop the background messenger Docker service
	$(DC) stop messenger

messenger-logs: ## Follow messenger service logs  [Ctrl+C to stop]
	$(DC) logs -f messenger

# =============================================================================
# Service tools
# =============================================================================

redis-ping: ## Ping the Redis service
	$(DC) exec redis redis-cli ping

mysql-shell: ## Open a MySQL interactive shell  (database: surgicalhub)
	$(DC) exec mysql mysql -uroot -proot surgicalhub

tools-up: ## Start optional tools (phpMyAdmin)  [profile: tools]
	$(DC) --profile tools up -d

phpmyadmin-url: ## Show the phpMyAdmin URL  (run "make tools-up" first)
	@echo "http://localhost:8081"

# =============================================================================
# Diagnostics
# =============================================================================

doctor: ## Run a full stack diagnostic
	@echo ""
	@echo "======================================================"
	@echo "  SurgicalHub -- Stack diagnostics"
	@echo "======================================================"
	@echo ""
	@echo "-- Docker services -----------------------------------"
	$(DC) ps
	@echo ""
	@echo "-- PHP version ---------------------------------------"
	$(PHP) php -v
	@echo ""
	@echo "-- PHP extensions ------------------------------------"
	$(PHP) php -r "echo 'redis : ' . (extension_loaded('redis')  ? 'OK' : 'MISSING') . PHP_EOL;"
	$(PHP) php -r "echo 'pcntl : ' . (extension_loaded('pcntl')  ? 'OK' : 'MISSING') . PHP_EOL;"
	$(PHP) php -r "echo 'gmp   : ' . (extension_loaded('gmp')    ? 'OK' : 'MISSING') . PHP_EOL;"
	$(PHP) php -r "echo 'xdebug: ' . (extension_loaded('xdebug') ? 'OK (mode=' . ini_get('xdebug.mode') . ')' : 'MISSING') . PHP_EOL;"
	@echo ""
	@echo "-- vendor/autoload.php -------------------------------"
	$(PHP) sh -c "test -f vendor/autoload.php \
		&& echo 'OK' \
		|| echo 'MISSING -- run: make composer cmd=install'"
	@echo ""
	@echo "-- Symfony application -------------------------------"
	$(CONSOLE) about
	@echo ""
	@echo "-- Redis ping ----------------------------------------"
	$(DC) exec redis redis-cli ping
	@echo ""
	@echo "-- node_modules/.bin/vite ----------------------------"
	$(FRONT) sh -c "test -f node_modules/.bin/vite \
		&& echo 'OK' \
		|| echo 'MISSING -- run: make npm cmd=install'"
	@echo ""
	@echo "-- npm version ---------------------------------------"
	$(FRONT) npm --version
	@echo ""
	@echo "======================================================"
	@echo ""

# =============================================================================
# Danger zone
# =============================================================================

reset-volumes: ## [DANGER] Remove ALL Docker volumes. Usage: make reset-volumes CONFIRM=yes
	@if [ "$(CONFIRM)" != "yes" ]; then \
		echo ""; \
		echo "  WARNING: This will permanently destroy ALL data:"; \
		echo "    mysql_data       (database)"; \
		echo "    redis_data       (cache)"; \
		echo "    vendor_data      (PHP dependencies)"; \
		echo "    node_modules_data (JS dependencies)"; \
		echo "    composer_cache   (Composer download cache)"; \
		echo "    npm_cache        (NPM download cache)"; \
		echo ""; \
		echo "  Confirm by running:"; \
		echo "    make reset-volumes CONFIRM=yes"; \
		echo ""; \
		exit 1; \
	fi
	$(DC) down -v
	@echo ""
	@echo "  All volumes removed. Run 'make up' to recreate the environment."
	@echo "  Composer install and npm install will run automatically on next startup."
	@echo ""
