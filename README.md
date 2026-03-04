# Attractor 

This repository contains [NLSpecs](#terminology) to build your own version of Attractor to create your own software factory.

Although bringing your own agentic loop and unified LLM SDK is not required to build your own Attractor, we highly recommend controlling the stack so you have a strong foundation.

## Specs

- [Attractor Specification](./attractor-spec.md)
- [Coding Agent Loop Specification](./coding-agent-loop-spec.md)
- [Unified LLM Client Specification](./unified-llm-spec.md)

## Building Attractor

Supply the following prompt to a modern coding agent (Claude Code, Codex, OpenCode, Amp, Cursor, etc):

```
codeagent> Implement Attractor as described by https://github.com/strongdm/attractor
```

## Local Verification

Run full backend + Playwright e2e verification:

```
make test-e2e
```

Run local dev server:

```
make dev
```

Verify dev workflows against a running dev server and capture proof screenshot:

```
node tests/dev-verify.js
```

Provider-backed DOT generation/fix/iterate endpoints require:

- `OPENAI_API_KEY` (for `provider=openai`)
- `ANTHROPIC_API_KEY` (for `provider=anthropic`)

Optional overrides:

- `ATTRACTOR_DOT_PROVIDER` default provider (defaults to `openai`)
- `OPENAI_BASE_URL` / `ANTHROPIC_BASE_URL` API base URLs
- `ATTRACTOR_OPENAI_MODEL` / `ATTRACTOR_ANTHROPIC_MODEL` default models

## Terminology

- **NLSpec** (Natural Language Spec): a human-readable spec intended to be  directly usable by coding agents to implement/validate behavior.
