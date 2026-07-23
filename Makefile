-include .env
SHELL := /bin/bash
.DEFAULT_GOAL := help

DC        := docker compose
DC_PROD   := docker compose -f compose.yaml -f compose.prod.yaml
export UID := $(shell id -u)
export GID := $(shell id -g)

# 🔴 artisan ที่เขียนลง storage/ หรือ bootstrap/cache ต้องรันเป็น www-data
#    `docker exec` เข้าเป็น root แต่ php-fpm worker เป็น www-data และไม่มี CAP_FOWNER
#    ไฟล์ที่ root สร้างไว้ worker จะ touch() ไม่ได้ → 500 touch(): Utime failed
#    ทุก target ด้านล่างห่อ -u www-data ไว้แล้ว เพื่อไม่ให้ใครต้องจำ (CLAUDE.md §5)
ARTISAN     := $(DC) exec -u www-data portal php artisan
ARTISAN_API := $(DC) exec -u www-data api php artisan
COMPOSER := $(DC) exec -u www-data portal composer

.PHONY: help
help: ## แสดงคำสั่งทั้งหมด
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

# ── setup ────────────────────────────────────────────────────
.PHONY: init
init: .env secrets ## เตรียมไฟล์ที่จำเป็นครั้งแรก (ปลอดภัยที่จะรันซ้ำ)
	@mkdir -p .data/dist/app/v1 .data/secrets
	@echo "พร้อมแล้ว → ต่อไป: make install (ถ้ายังไม่มี Laravel) หรือ make up"

.env: ## สร้าง .env จาก .env.example พร้อมสุ่ม secret ของ dev
	@if [ -f .env ]; then echo ".env มีอยู่แล้ว — ข้าม"; else \
		cp .env.example .env; \
		echo "สุ่ม secret สำหรับ dev..."; \
		sed -i "s|^APP_KEY=.*|APP_KEY=base64:$$(openssl rand -base64 32)|" .env; \
		sed -i "s|^POSTGRES_PASSWORD=.*|POSTGRES_PASSWORD=$$(openssl rand -hex 16)|" .env; \
		sed -i "s|^DEVICE_UUID_PEPPER=.*|DEVICE_UUID_PEPPER=$$(openssl rand -hex 32)|" .env; \
		sed -i "s|^DOCUMENT_KEK=.*|DOCUMENT_KEK=$$(openssl rand -base64 32)|" .env; \
		sed -i "s|^NATIONAL_ID_ENCRYPTION_KEY=.*|NATIONAL_ID_ENCRYPTION_KEY=$$(openssl rand -base64 32)|" .env; \
		sed -i "s|^GARAGE_ACCESS_KEY=.*|GARAGE_ACCESS_KEY=GK$$(openssl rand -hex 12)|" .env; \
		sed -i "s|^GARAGE_SECRET_KEY=.*|GARAGE_SECRET_KEY=$$(openssl rand -hex 32)|" .env; \
		echo "สร้าง .env แล้ว — ค่าเหล่านี้ใช้กับ dev เท่านั้น ห้ามใช้บน production"; \
	fi

.PHONY: secrets
secrets: ## สร้างไฟล์ secret ของ Garage สำหรับ dev
	@mkdir -p .data/secrets
	@[ -f .data/secrets/garage_rpc_secret ]  || openssl rand -hex 32 > .data/secrets/garage_rpc_secret
	@[ -f .data/secrets/garage_admin_token ] || openssl rand -hex 32 > .data/secrets/garage_admin_token
	@chmod 600 .data/secrets/*

.PHONY: install
install: init ## ติดตั้ง Laravel ครั้งแรกลง apps/api (ต้องว่างอยู่)
	@if [ -f apps/api/artisan ]; then echo "apps/api มี Laravel อยู่แล้ว — ข้าม"; exit 0; fi
	$(DC) run --rm --no-deps -u www-data portal \
		composer create-project laravel/laravel:^13.0 . --no-interaction
	@echo "ต่อไป: make up && make migrate"

# ── วงจรประจำวัน ──────────────────────────────────────────────
.PHONY: up
up: ## เปิดทุก service (dev)
	$(DC) up -d --build
	@$(MAKE) --no-print-directory ps

.PHONY: down
down: ## ปิดทุก service (volume ยังอยู่)
	$(DC) down

.PHONY: restart
restart: down up ## ปิดแล้วเปิดใหม่

.PHONY: ps
ps: ## สถานะ service
	@$(DC) ps

.PHONY: logs
logs: ## ตาม log ทุก service (make logs s=api ดูตัวเดียว)
	$(DC) logs -f --tail=100 $(s)

.PHONY: sh
sh: ## เข้า shell ของ portal เป็น www-data (แนะนำ)
	$(DC) exec -u www-data portal sh

.PHONY: sh-api
sh-api: ## เข้า shell ของ api เป็น www-data
	$(DC) exec -u www-data api sh

.PHONY: sh-root
sh-root: ## เข้า shell เป็น root — ใช้เฉพาะติดตั้ง package ระบบ
	@echo "เตือน: ห้ามรัน artisan ที่เขียน storage/ ในนี้ (CLAUDE.md §5)"
	$(DC) exec portal sh

# ── Laravel ──────────────────────────────────────────────────
.PHONY: artisan
artisan: ## รัน artisan (make artisan c="route:list")
	$(ARTISAN) $(c)

.PHONY: migrate
migrate: ## รัน migration
	$(ARTISAN) migrate

.PHONY: migrate-status
migrate-status: ## ดูสถานะ migration
	$(ARTISAN) migrate:status

.PHONY: cache
cache: ## config/route/view cache — ต้องแคชแยกทั้ง 2 role ไม่งั้น route ทับกัน
	@echo "-- portal --"
	$(ARTISAN) config:cache
	$(ARTISAN) route:cache
	$(ARTISAN) view:cache
	@echo "-- api (route/provider คนละชุด จึงต้องแคชแยก) --"
	$(ARTISAN_API) config:cache
	$(ARTISAN_API) route:cache

.PHONY: cache-clear
cache-clear: ## ล้าง cache ทั้งหมด (ทั้ง 2 role)
	$(ARTISAN) optimize:clear
	$(ARTISAN_API) optimize:clear

.PHONY: key
key: ## สร้าง APP_KEY ใหม่
	$(ARTISAN) key:generate

.PHONY: composer
composer: ## รัน composer (make composer c="require x/y")
	$(COMPOSER) $(c)

# ── คุณภาพ ───────────────────────────────────────────────────
.PHONY: test
test: ## รัน test ทั้งหมด
	$(DC) exec -u www-data portal php artisan test

.PHONY: lint
lint: ## ตรวจ code style
	$(DC) exec -u www-data portal ./vendor/bin/pint --test

.PHONY: lint-fix
lint-fix: ## แก้ code style
	$(DC) exec -u www-data portal ./vendor/bin/pint

.PHONY: analyse
analyse: ## static analysis
	$(DC) exec -u www-data portal ./vendor/bin/phpstan analyse

.PHONY: openapi
openapi: ## ตรวจว่า openapi.yaml ยัง parse ได้และ $$ref ครบ
	@python3 -c "import yaml,json,re,sys; \
d=yaml.safe_load(open('docs/api/openapi.yaml')); \
ok=lambda r:all(True for _ in [0]) and (lambda n=[d]: True)(); \
refs=set(re.findall(r'\"\\\$$ref\": \"([^\"]+)\"', json.dumps(d))); \
bad=[r for r in refs if not __import__('functools').reduce(lambda n,p:(n or {}).get(p) if isinstance(n,dict) else None, r.lstrip('#/').split('/'), d)]; \
print('OpenAPI', d['openapi'], '| paths', len(d['paths']), '| broken refs:', bad or 'none'); \
sys.exit(1 if bad else 0)"

.PHONY: secrets-scan
secrets-scan: ## หา secret ที่หลุดเข้า git
	@command -v gitleaks >/dev/null || { echo "ยังไม่ได้ติดตั้ง gitleaks — ข้าม"; exit 0; }
	gitleaks detect --source . --config .gitleaks.toml --redact -v

.PHONY: verify-isolation
verify-isolation: ## 🔴 ยืนยันการแยก api/portal ครบทุกชั้น (ADR 0007, S18)
	@bash scripts/verify-isolation.sh

.PHONY: dev-hosts
dev-hosts: ## พิมพ์บรรทัดสำหรับ /etc/hosts (แล็ปท็อป) + วิธีตั้ง DNS มือถือ
	@echo "# เพิ่มบรรทัดนี้ใน /etc/hosts ของแล็ปท็อป (ครั้งเดียว ไม่ต้องแก้เวลาย้ายที่):"
	@echo "127.0.0.1 api.driver.test staff.driver.test dl.driver.test"
	@echo ""
	@echo "# มือถือ: ตั้ง DNS ใน Wi-Fi ให้ชี้มาที่ IP แล็ปท็อป ($(shell grep -E '^HOST_LAN_IP=' .env 2>/dev/null | cut -d= -f2))"
	@echo "#   dnsmasq ในสแตกจะตอบ *.driver.test เป็น IP นั้น"
	@echo "#   ย้ายที่: แก้ HOST_LAN_IP ใน .env แล้ว 'docker compose up -d dnsmasq'"

# ── ฐานข้อมูล / storage ──────────────────────────────────────
.PHONY: psql
psql: ## เปิด psql
	$(DC) exec postgres psql -U $${POSTGRES_USER:-mvp} -d $${POSTGRES_DB:-mvp}

.PHONY: valkey-cli
valkey-cli: ## เปิด valkey-cli
	$(DC) exec valkey valkey-cli

.PHONY: backup
backup: ## dump ฐานข้อมูล (⚠️ ยังไม่ครอบ Garage — ดู architecture.md §8.5)
	@mkdir -p .data/backup
	@echo "เตือน: backup นี้ครอบแค่ Postgres — ข้อมูลไม่ครบถ้าไม่ backup Garage ด้วย"
	$(DC) exec -T postgres pg_dump -U $${POSTGRES_USER:-mvp} $${POSTGRES_DB:-mvp} \
		| gzip > .data/backup/pg-$$(date +%Y%m%d-%H%M%S).sql.gz
	@ls -lh .data/backup/ | tail -1

# ── prod ─────────────────────────────────────────────────────
.PHONY: prod-config
prod-config: ## ตรวจ compose ของ prod (ไม่รันอะไร)
	$(DC_PROD) config --quiet && echo "compose ของ prod ถูกต้อง"

.PHONY: prod-build
prod-build: ## build image ของ prod
	$(DC_PROD) build

# ── อันตราย ──────────────────────────────────────────────────
.PHONY: nuke
nuke: ## ⚠️ ลบ volume ทั้งหมด — ข้อมูลหายถาวร ต้องพิมพ์ยืนยัน
	@echo "จะลบ volume ทั้งหมด: Postgres, Valkey, Garage (เอกสารคนขับด้วย)"
	@read -p "พิมพ์ 'DESTROY' เพื่อยืนยัน: " c; [ "$$c" = "DESTROY" ] || { echo "ยกเลิก"; exit 1; }
	$(DC) down -v
