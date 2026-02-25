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
```

## CLI

Use the minimal CLI to validate or run DOT pipelines:

```bash
bin/attractor validate examples/pipelines/basic.dot
bin/attractor run examples/pipelines/basic.dot .scratch/runs/basic
```

## Terminology

- **NLSpec** (Natural Language Spec): a human-readable spec intended to be  directly usable by coding agents to implement/validate behavior.
