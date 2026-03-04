# Architecture Decision Record (ADR)

## ADR-2026-03-04-001: Embedded Dashboard Stack
- Status: Accepted
- Context: Sprint 002 requires a built-in UI with no external dependencies and predictable local execution.
- Decision: Serve a no-build static SPA (`web/index.html`, `web/app.js`, `web/styles.css`) from the PHP runtime at `/`.
- Consequences:
  - Fast local startup and no Node build pipeline requirement.
  - UI logic remains explicit and testable through API contracts.

## ADR-2026-03-04-002: SSE Snapshot-First Contract
- Status: Accepted
- Context: Reconnect correctness requires deterministic state bootstrapping.
- Decision: Both `/api/v1/events` and `/api/v1/pipelines/{id}/events` emit `Snapshot` as first SSE frame, followed by event deltas.
- Consequences:
  - Clients can fully rebuild state from each connection.
  - Contract tests can assert ordering and frame shape.

## ADR-2026-03-04-003: DOT Rendering and Validation Strategy
- Status: Accepted
- Context: Sprint requires validate/render loops and robust local behavior without hard failing on Graphviz availability.
- Decision: Implement server-side DOT validation heuristics and SVG preview generation in PHP; return structured diagnostics and block run creation when invalid.
- Consequences:
  - Works in fully local environments.
  - Keeps API stable while allowing future Graphviz renderer swap-in.

## ADR-2026-03-04-004: Agentic DOT Generation/Fix/Iterate with Simulation Support
- Status: Accepted
- Context: Sprint mandates streaming DOT generate/fix/iterate loops and deterministic local test mode.
- Decision: Implement sync + SSE streaming endpoints that emit `delta` chunks and final `done` frame with full `dotSource`; sanitize markdown fences.
- Consequences:
  - UI can append streaming chunks and validate before run.
  - CI/test flows remain deterministic without external LLM calls.

## ADR-2026-03-04-005: Run Directory as Source of Truth
- Status: Accepted
- Context: NLSpec aligns around durable run artifacts and resumable state snapshots.
- Decision: Persist each run under logs root with `manifest.json`, `checkpoint.json`, `context.json`, `events.ndjson`, `dot.dot`, and `artifacts/`.
- Consequences:
  - API handlers are stateless wrappers over persisted run data.
  - Artifact export and audit trails are straightforward.
