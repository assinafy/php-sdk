.PHONY: help install test docker-up docker-down docker-build docker-logs

help:
	@echo "Assinafy PHP SDK - Available commands:"
	@echo ""
	@echo "  make install       Install Composer dependencies"
	@echo "  make test          Run tests"
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

