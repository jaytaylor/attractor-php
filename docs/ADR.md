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

## ADR-003: Provider Smoke Testing Uses Native HTTP Transport
- Date: 2026-02-25
- Status: Accepted

### Context
Sprint closure requires provider-backed smoke tests for OpenAI, Anthropic, and Gemini that can run from the same PHP library runtime used by deterministic tests.

### Decision
Add `Attractor\LLM\Http\NativeHttpTransport` and wire `Client::fromEnv()` to native HTTP transport instead of placeholder array responses.

Provider smoke tests run in an explicit group:
- `composer run test:e2e:provider-smoke`

### Consequences
- Positive:
  - Real provider validation is available without a secondary harness.
  - `fromEnv()` is production-viable for direct API calls.
- Tradeoff:
  - Runtime behavior depends on network access and provider credentials.

## ADR-004: HTTP Server Mode Is Deferred for Sprint 001 Closure
- Date: 2026-02-25
- Status: Accepted

### Context
Attractor HTTP mode is optional in Sprint 001 and not required for deterministic parity closure. The sprint still requires observability coverage for the runner core.

### Decision
Implement runner observability event emission in core execution paths and defer optional HTTP server mode to a follow-on sprint.

### Consequences
- Positive:
  - Closure focuses on deterministic parity and auditable evidence.
  - Event stream semantics are test-covered and ready for future HTTP/SSE surfacing.
- Tradeoff:
  - Remote run/status/answer endpoints are not part of Sprint 001 deliverables.
