# Architecture Decision Record (ADR)

## ADR-001: Monorepo Package Structure for NLSpec Parity
- Date: 2026-02-25
- Status: Accepted

### Context
Sprint 001 requires implementing three tightly coupled domains from scratch:
- Unified LLM client
- Coding agent loop
- Attractor pipeline runner

All three must ship together with parity tests and shared fixtures.

### Decision
Adopt one PHP composer project with three top-level namespaces:
- `Attractor\LLM`
- `Attractor\Agent`
- `Attractor\Pipeline`

Use a shared test tree with unit, integration, and end-to-end suites.

### Consequences
- Positive:
  - Shared data types and fixtures reduce translation drift.
  - One CI command can validate cross-module behavior.
  - Easier parity matrix execution across specs.
- Tradeoff:
  - Requires strict module boundaries to avoid coupling.

## ADR-002: Evidence-First Verification for Sprint Closure
- Date: 2026-02-25
- Status: Accepted

### Context
Sprint closure depends on proving compliance with three NLSpec definition-of-done sections and parity matrices.

### Decision
Require evidence artifacts for every completed checklist item under:
- `.scratch/verification/SPRINT-001/`

Each completed item must include:
- exact command(s)
- exit code(s)
- artifact path(s)

### Consequences
- Positive:
  - Sprint status is auditable without replaying local history.
  - Regression triage is faster with durable artifacts.
- Tradeoff:
  - Adds documentation overhead to each completion update.
