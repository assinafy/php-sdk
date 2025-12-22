.PHONY: help install test docker-up docker-down docker-build docker-logs phpcs phpcbf phpstan

help:
	@echo "Assinafy PHP SDK - Available commands:"
	@echo ""
	@echo "  make install       Install Composer dependencies"
	@echo "  make test          Run tests"
	@echo "  make phpcs         Run PHP_CodeSniffer (check code style)"
	@echo "  make phpcbf        Run PHP Code Beautifier (fix code style)"
	@echo "  make phpstan       Run PHPStan (static analysis)"
	@echo "  make quality       Run all quality checks (phpcs + phpstan)"
	@echo "  make docker-up     Start Docker environment"
	@echo "  make docker-down   Stop Docker environment"
	@echo "  make docker-build  Build Docker images"
	@echo "  make docker-logs   View Docker logs"
	@echo "  make quickstart    Run quickstart example"
	@echo ""

install:
	composer install

test:
	vendor/bin/phpunit

docker-up:
	docker-compose up -d

docker-down:
	docker-compose down

docker-build:
	docker-compose build

docker-logs:
	docker-compose logs -f

quickstart:
	php docs/quickstart.php

docker-install:
	docker-compose exec php composer install

docker-quickstart:
	docker-compose exec php php docs/quickstart.php

phpcs:
	vendor/bin/phpcs

phpcbf:
	vendor/bin/phpcbf

phpstan:
	vendor/bin/phpstan analyse

quality: phpcs phpstan
	@echo "All quality checks passed!"

