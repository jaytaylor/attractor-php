.PHONY: precommit build test

precommit:
	@mkdir -p .scratch/verification/SPRINT-002/phase4/backend-tests
	@find src public tests bin -type f -name '*.php' -print0 | xargs -0 -I{} php -l {} > .scratch/verification/SPRINT-002/phase4/backend-tests/php-lint.log

build: precommit
	@echo "build=ok" | tee .scratch/verification/SPRINT-002/phase4/backend-tests/build.log

test: precommit
	@set -o pipefail; php tests/run.php 2>&1 | tee .scratch/verification/SPRINT-002/phase4/backend-tests/test.log
	@node tests/e2e.js
