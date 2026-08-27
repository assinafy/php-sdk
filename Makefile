.PHONY: help install test integration check quality phpcs phpcbf phpstan phpmd audit \
	docker-up docker-down docker-build docker-logs docker-install quickstart docker-quickstart

help:
	@echo "Assinafy PHP SDK commands"
	@echo "  make install       Install Composer dependencies"
	@echo "  make test          Run unit tests (no network)"
	@echo "  make integration   Run explicitly configured sandbox integration tests"
	@echo "  make check         Validate, test, analyze, lint, and audit dependencies"
	@echo "  make phpcbf        Apply PSR-12 formatting fixes"
	@echo "  make docker-up     Build and start the local Docker environment"
	@echo "  make docker-down   Stop the local Docker environment"

install:
	composer install

test:
	composer test

integration:
	composer test:integration

check quality:
	composer check

phpcs:
	composer phpcs

phpcbf:
	vendor/bin/phpcbf

phpstan:
	composer phpstan

phpmd:
	composer phpmd

audit:
	composer audit:dependencies

quickstart:
	php docs/quickstart.php

docker-up:
	docker compose up -d --build

docker-down:
	docker compose down

docker-build:
	docker compose build

docker-logs:
	docker compose logs -f

docker-install:
	docker compose exec php composer install

docker-quickstart:
	docker compose exec php php docs/quickstart.php
