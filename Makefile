PHP_DIR := packages/laravel
GO_DIR := runtime/stagehand
BIN_DIR := bin

.PHONY: test test-php test-go build-stagehand docker-build-stagehand docker-up-example docker-down-example example-app

test: test-go test-php

test-php:
	cd $(PHP_DIR) && composer test

test-go:
	cd $(GO_DIR) && go test ./...

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
