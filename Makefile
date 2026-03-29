PHP_DIR := packages/laravel
GO_DIR := runtime/stagehand
BIN_DIR := bin

.PHONY: test test-php test-go check-release build-stagehand docker-build-stagehand docker-up-example docker-down-example example-app split-package smoke-launch

test: test-go test-php

test-php:
	cd $(PHP_DIR) && composer test

test-go:
	cd $(GO_DIR) && go test ./...

check-release:
	./scripts/check-release-surface.sh

build-stagehand:
	./scripts/build-stagehand.sh $(BIN_DIR)/stagehand

docker-build-stagehand:
	docker build -f docker/stagehand.Dockerfile -t canio-stagehand:local .

docker-up-example:
	docker compose -f docker/docker-compose.example.yml up --build

docker-down-example:
	docker compose -f docker/docker-compose.example.yml down -v

example-app:
	./examples/laravel-app/create-project.sh

split-package:
	./scripts/publish-split-package.sh

smoke-launch:
	./scripts/smoke-launch.sh
