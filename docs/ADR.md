# Architecture Decision Record (ADR)

## ADR-001: Embedded no-build web dashboard
- Date: 2026-03-04
- Status: Accepted
- Context: Sprint 002 requires a built-in web dashboard served directly by Attractor PHP with no external network dependencies.
- Decision: Serve static assets (`public/index.html`, `public/assets/*`) from the same PHP HTTP process as JSON and SSE endpoints.
- Consequences:
  - Positive: Single deployment artifact and deterministic local development.
  - Positive: No Node or frontend build pipeline required.
  - Tradeoff: Less componentization than framework-based SPA stacks.

## ADR-002: Filesystem-backed run persistence
- Date: 2026-03-04
- Status: Accepted
- Context: NLSpec run directories are the source of truth for runtime state and artifacts.
- Decision: Persist runs under `.scratch/runtime/runs/{runId}` with `manifest.json`, `checkpoint.json`, `context.json`, `events.ndjson`, stage artifacts, and dot source.
- Consequences:
  - Positive: Easy inspection and deterministic artifact export.
  - Positive: No external database dependency.
  - Tradeoff: Single-process write model and limited concurrent coordination.

## ADR-003: Snapshot-first SSE contract
- Date: 2026-03-04
- Status: Accepted
- Context: Monitor UI must reconnect without state divergence.
- Decision: Both global and per-run SSE streams emit a `Snapshot` frame first, followed by lifecycle/event deltas.
- Consequences:
  - Positive: Idempotent client bootstrap after refresh/reconnect.
  - Positive: Simplified frontend state convergence.

## ADR-004: DOT render strategy
- Date: 2026-03-04
- Status: Accepted
- Context: Graphviz may not be available in every runtime environment.
- Decision: Use deterministic server-side SVG generation for DOT preview and run graph endpoints without hard dependency on Graphviz.
- Consequences:
  - Positive: Graph endpoints always available.
  - Tradeoff: Visualization is text-centric and not full Graphviz layout parity.

## ADR-005: Simulation-first execution path
- Date: 2026-03-04
- Status: Accepted
- Context: Sprint testability requires deterministic runs with no external LLM keys/network.
- Decision: `simulate` mode and default test pathways use deterministic stage/event/artifact production while preserving endpoint contracts.
- Consequences:
  - Positive: Reliable offline CI and local verification.
  - Tradeoff: Real-provider behavior differences still require follow-on validation in later sprints.
