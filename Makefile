.PHONY: precommit build test test-e2e dev

DEV_HOST ?= 127.0.0.1
DEV_PORT ?= 8080

precommit:
	@mkdir -p .scratch/verification/SPRINT-002/phase4/backend-tests
	@find src public tests bin -type f -name '*.php' -print0 | xargs -0 -I{} php -l {} > .scratch/verification/SPRINT-002/phase4/backend-tests/php-lint.log

build: precommit
	@echo "build=ok" | tee .scratch/verification/SPRINT-002/phase4/backend-tests/build.log

test: precommit
	@set -o pipefail; php tests/run.php 2>&1 | tee .scratch/verification/SPRINT-002/phase4/backend-tests/test.log
	@node tests/e2e.js

test-e2e: precommit
	@set -o pipefail; php tests/run.php 2>&1 | tee .scratch/verification/SPRINT-002/phase4/backend-tests/test.log
	@node tests/e2e.js

dev: precommit
	@mkdir -p .scratch/dev/runs
	@echo "Starting dev server at http://$(DEV_HOST):$(DEV_PORT)"
	@ATTRACTOR_LOGS_ROOT=$(CURDIR)/.scratch/dev/runs php -S $(DEV_HOST):$(DEV_PORT) public/index.php
