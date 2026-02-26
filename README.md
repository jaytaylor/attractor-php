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
make build
make test
make test-e2e
```

`make test-e2e` includes provider-backed end-to-end tests. They are env-gated and skip unless provider keys are set:
- `OPENAI_API_KEY` (optional `OPENAI_E2E_MODEL`)
- `ANTHROPIC_API_KEY` (optional `ANTHROPIC_E2E_MODEL`)
- `GEMINI_API_KEY` or `GOOGLE_API_KEY` (optional `GEMINI_E2E_MODEL`)

## CLI

Use the minimal CLI to validate or run DOT pipelines:

```bash
bin/attractor validate examples/pipelines/basic.dot
bin/attractor run examples/pipelines/basic.dot .scratch/runs/basic
```

## HTTP Mode

Run the minimal HTTP server mode with PHP's built-in server:

```bash
php -S 127.0.0.1:8080 bin/attractor-http
```

Available endpoints:
- `POST /run` with JSON body containing `dot` (or `dot_path`), optional `run_id`, and optional `logs_root`
- `GET /status?run_id=<id>` for JSON status
- `GET /status?run_id=<id>&stream=1` for SSE event stream
- `POST /answer` with JSON body containing `run_id` and `selected` for paused `wait.human` gates

## Terminology

- **NLSpec** (Natural Language Spec): a human-readable spec intended to be  directly usable by coding agents to implement/validate behavior.
