.PHONY: precommit build test

precommit:
	@timeout 135 php -v >/dev/null
	@timeout 135 find src public tests -type f -name '*.php' -print0 | xargs -0 -I{} php -l {} >/dev/null

build: precommit
	@echo "build ok"

test: precommit
	@timeout 135 php tests/run.php
