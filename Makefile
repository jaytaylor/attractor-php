.PHONY: precommit build test test-e2e dev

HOST ?= 127.0.0.1
PORT ?= 7070

precommit:
	@timeout 135 php -v >/dev/null
	@timeout 135 find src public tests -type f -name '*.php' -print0 | xargs -0 -I{} php -l {} >/dev/null

build: precommit
	@echo "build ok"

test: precommit
	@timeout 135 php tests/check_sprint_evidence.php
	@timeout 135 php tests/prompt_system.php
	@timeout 135 php tests/run.php

test-e2e: precommit
	@bash tests/e2e/run_tmux_playwright_e2e.sh

dev: precommit
	@echo "serving on http://$(HOST):$(PORT)"
	@php -S $(HOST):$(PORT) -t public public/index.php
