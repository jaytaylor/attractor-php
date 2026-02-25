# Attractor PHP

This repository contains [NLSpecs](#terminology) plus a PHP reference implementation of:
- Unified LLM client (`Attractor\LLM`)
- Coding agent loop (`Attractor\Agent`)
- Attractor pipeline runner (`Attractor\Pipeline`)

The implementation focuses on deterministic tests and verifiable sprint evidence under `.scratch/verification/SPRINT-001/`.

## Specs

- [Attractor Specification](./attractor-spec.md)
- [Coding Agent Loop Specification](./coding-agent-loop-spec.md)
- [Unified LLM Client Specification](./unified-llm-spec.md)

## Build and Test

```bash
timeout 180 make build
timeout 180 make test
timeout 180 ./bin/composer run test:e2e:provider-smoke
```

`test:e2e:provider-smoke` is env-gated and skips unless provider keys are set:
- `OPENAI_API_KEY` (optional `OPENAI_SMOKE_MODEL`)
- `ANTHROPIC_API_KEY` (optional `ANTHROPIC_SMOKE_MODEL`)
- `GEMINI_API_KEY` or `GOOGLE_API_KEY` (optional `GEMINI_SMOKE_MODEL`)

## CLI

Use the minimal CLI to validate or run DOT pipelines:

```bash
bin/attractor validate examples/pipelines/basic.dot
bin/attractor run examples/pipelines/basic.dot .scratch/runs/basic
```

## Terminology

- **NLSpec** (Natural Language Spec): a human-readable spec intended to be  directly usable by coding agents to implement/validate behavior.
