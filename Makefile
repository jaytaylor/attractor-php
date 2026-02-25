SHELL := /bin/bash
COMPOSER := ./bin/composer

.PHONY: precommit vendor build test test-unit test-integration test-e2e lint fmt doctor ci

precommit: doctor vendor lint

vendor:
	$(COMPOSER) install --no-interaction --prefer-dist

doctor:
	$(COMPOSER) run doctor

lint:
	$(COMPOSER) run lint

fmt:
	$(COMPOSER) run fmt

build: precommit
	$(COMPOSER) dump-autoload -o

test: precommit
	$(COMPOSER) run test

test-unit: precommit
	$(COMPOSER) run test:unit

test-integration: precommit
	$(COMPOSER) run test:integration

test-e2e: precommit
	$(COMPOSER) run test:e2e

ci: precommit
	$(COMPOSER) run ci
