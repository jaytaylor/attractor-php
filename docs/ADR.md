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

## ADR-2026-03-04-008: Real Provider Adapters for DOT Authoring
- Status: Accepted
- Context: DOT generation, repair, and iteration flows must use real provider APIs rather than deterministic in-process stubs.
- Decision: Route DOT authoring operations through provider-backed adapters (OpenAI Responses API and Anthropic Messages API) with configurable base URLs, API keys, and model overrides.
- Consequences:
  - Runtime behavior now reflects real upstream LLM API semantics and failures.
  - Tests use provider-compatible local mock endpoints to validate end-to-end request/response contract without requiring external secrets.

## ADR-2026-03-04-009: DOT-Normalization Guardrail for Non-DOT LLM Output
- Status: Accepted
- Context: Real providers may return prose, fenced content, or raw SVG even when DOT is requested, causing graph preview failures.
- Decision: Normalize provider output by extracting the first `digraph` block when present and falling back to deterministic DOT synthesis when output is non-DOT or SVG/XML.
- Consequences:
  - Prompt flows like "create a svg of a dog" remain renderable in DOT preview and run paths.
  - DOT endpoints become resilient to provider formatting drift without introducing stubs.

## ADR-2026-03-04-010: Example-Primed, Validation-First DOT Generation Prompt
- Status: Accepted
- Context: Generated DOT graphs should consistently encode validation and rework behavior, and prompt quality should match known high-quality Attractor-style graphs.
- Decision: For generation requests, embed a curated corpus of seven DOT exemplar files in the base system prompt and require explicit validation nodes with pass/fail branches that kick work back to planning/implementation before final proof.
- Consequences:
  - New generated graphs preserve Attractor’s validation-driven execution intent.
  - Prompt quality improves through concrete in-context DOT patterns.

## ADR-2026-03-04-011: Graph-Driven Runtime Execution with Provider-Backed Node Work
- Status: Accepted
- Context: Runtime path must not rely on simulation flags or synthetic timeline events; monitor status should reflect real node execution against parsed DOT workflows.
- Decision: Replace timeline/simulated pipeline progression with DOT graph parsing plus node-by-node execution. Execute codergen/validation nodes via provider-backed task LLM calls, execute tool nodes via shell commands, and pause/resume only on real human-gate nodes.
- Consequences:
  - Run completion now depends on actual graph traversal and upstream/tool behavior.
  - Stage/event/checkpoint history mirrors true execution state, including waiting-human and validation pass/fail routing.
  - Tests require provider-compatible mocks for deterministic CI while preserving real runtime contracts.

## ADR-2026-03-04-005: Run Directory as Source of Truth
- Status: Accepted
- Context: NLSpec aligns around durable run artifacts and resumable state snapshots.
- Decision: Persist each run under logs root with `manifest.json`, `checkpoint.json`, `context.json`, `events.ndjson`, `dot.dot`, and `artifacts/`.
- Consequences:
  - API handlers are stateless wrappers over persisted run data.
  - Artifact export and audit trails are straightforward.

## ADR-2026-03-04-006: Strict Lifecycle Transition Guards
- Status: Accepted
- Context: Monitor and Archived workflows require deterministic invalid-action behavior for operator trust and replay-safe UI state.
- Decision: Enforce terminal-only archive/unarchive transitions and reject idempotent archive/unarchive requests with `409 INVALID_STATE`.
- Consequences:
  - UI actions can provide clear guardrail messaging on invalid transitions.
  - Backend behavior remains contract-stable for negative lifecycle tests.

## ADR-2026-03-04-007: Error Envelope and Stream Error Consistency
- Status: Accepted
- Context: Dashboard clients need deterministic error handling across JSON API and SSE-style DOT streaming endpoints.
- Decision: Standardize JSON error responses to include `status`, `code`, and `error`; redact unexpected throwable details behind a generic internal error message; make malformed streaming DOT requests terminate with a single SSE `error` frame.
- Consequences:
  - UI error rendering can rely on consistent machine and human fields.
  - Internal exception text is not exposed to clients.
  - Streaming workflows can fail without mixed payload formats.
