Legend: [ ] Incomplete, [X] Complete

# Sprint #002 - Attractor PHP: Web Dashboard UI (Coreys-Attractor-Inspired)

Evidence rule:
- Mark an item `[X]` only once it includes the exact verification command(s) (wrapped in backticks), exit code(s), and paths to produced artifacts (logs, fixtures, screenshots, `.scratch` transcripts) in the placeholder block directly beneath the item.
- Store evidence under `.scratch/verification/SPRINT-002/...` and link those paths in the placeholder block.

## Objective
Deliver a built-in web dashboard for Attractor PHP that enables:
- Real-time pipeline monitoring (status, stages, logs, rendered graph)
- Remote human-gate operation (view pending questions and submit answers)
- Pipeline creation workflow (paste/upload DOT, generate DOT from a prompt, and iterate an existing run’s DOT via the agentic loop)

This sprint explicitly uses the embedded dashboard in [`coreys-attractor`](../../coreys-attractor/README.md) as a UX and API inspiration source, while implementing behavior consistent with the Attractor NLSpec (`attractor-spec.md`) HTTP server mode and event stream requirements.

## Context
Attractor’s NLSpec makes the engine headless and UI-driven by events. It allows an HTTP server mode and requires that human gates be operable via web controls with real-time event streaming (SSE). Corey’s Attractor demonstrates a practical, dependency-light approach: a single-page dashboard served by the runtime, backed by an HTTP JSON API and SSE streams.

## Dependencies
- This sprint assumes the Attractor engine and core run directory artifacts exist (see Sprint 001’s objective: full NLSpec parity). If that foundation is missing, Phase 1 must include the minimum scaffolding to run pipelines and emit events.

## Current State Snapshot (2026-03-03)
- This repo is currently NLSpecs + docs only (no PHP runtime implementation, no HTTP server, no UI assets).
- A local reference implementation of a similar dashboard approach exists in this worktree (`coreys-attractor/`) and is used only as inspiration (not a deliverable).

## Golden References (Must-Read: Agentic Loops, Especially DOT Expansion/Generation)
This sprint treats DOT pipelines as an agentic artifact: users must be able to **generate**, **validate/render**, **fix**, and **iterate** DOT in a loop until it converges into a runnable pipeline. A one-shot “generate DOT and hope it parses” implementation is explicitly insufficient.

Coreys Attractor references (code + intent):
- Agentic loop notes (local scratch extraction; required reading; created/updated in P0.5): [`../../.scratch/refs/SPRINT-002/coreys-attractor-agentic-loops.md`](../../.scratch/refs/SPRINT-002/coreys-attractor-agentic-loops.md)
- DOT generator + fence stripping: [`../../coreys-attractor/src/main/kotlin/attractor/web/DotGenerator.kt`](../../coreys-attractor/src/main/kotlin/attractor/web/DotGenerator.kt)
- Streaming DOT endpoints + iterate-run endpoint (server) and Create-view generate/iterate/fix JS loop (client): [`../../coreys-attractor/src/main/kotlin/attractor/web/WebMonitorServer.kt`](../../coreys-attractor/src/main/kotlin/attractor/web/WebMonitorServer.kt)
- “Iterate” user story (dotfile expansion loop intent): [`../../coreys-attractor/docs/sprints/drafts/SPRINT-002-INTENT.md`](../../coreys-attractor/docs/sprints/drafts/SPRINT-002-INTENT.md)

What must carry over into Attractor PHP (behavioral contract, not implementation details):
- Streaming DOT generation and streaming DOT iteration (delta chunks appended to the editor).
- Validation-gated “Run”: refuse to run invalid DOT.
- DOT fix loop: take a Graphviz/validator error and converge to renderable DOT.
- Iterate a terminal run into a *new run* that preserves lineage (`familyId`) and keeps the source run unchanged.

## Non-Goals
- Authentication/authorization, multi-user sessions, or RBAC
- Multi-tenant isolation
- A fully general-purpose “software factory UI” (IDE plugin, editor integrations)
- Legacy backwards compatibility for any prior API (there is none in this repo)

## Execution Order
Phase 0 -> Phase 1 -> Phase 2 -> Phase 3 -> Phase 4

## Product Requirements (User Flows)
1. **Create a pipeline run**
   - Paste DOT source into an editor and run it.
   - Describe a goal in natural language and generate candidate DOT (streaming).
   - Validate DOT before running and show actionable diagnostics (block run until valid).
   - If DOT fails render/validate, provide a fix workflow (LLM-assisted) that converges to renderable DOT or refuses to run with clear diagnostics.
2. **Monitor a pipeline run**
   - See overall run status, current node, and stage list with per-stage status.
   - See a rendered graph visualization with stage status overlays.
   - See an append-only live log of events.
3. **Operate human gates**
   - When a run pauses for human input, the UI shows the question and answer options.
   - Submitting an answer resumes execution and is reflected in the event stream.
4. **Inspect and export artifacts**
   - View per-stage artifacts (prompt/response/status) and any additional run artifacts.
   - Download all artifacts as a ZIP.
5. **Run history basics**
   - List recent runs, open an older run, and delete a run (with safeguards).
6. **Iterate a pipeline run (dotfile expansion loop)**
   - From a terminal run, open Create view in “iterate mode” with the run’s DOT pre-populated.
   - Describe modifications in natural language and stream back a modified DOT (without losing the ability to hand-edit the DOT).
   - Start a new run from the modified DOT while preserving lineage (`familyId`) and leaving the source run untouched.

## Architectural Approach (High Level)
- **Server**: Attractor PHP runs the pipeline engine and exposes an HTTP API plus SSE endpoints for events.
- **UI**: A built-in, no-build single-page app (static HTML/CSS/JS) served at `/`, consuming the HTTP API.
- **Storage**: Use the NLSpec run directory structure as the durable “source of truth” for run state and artifacts:
  - `{logs_root}/{run_id}/manifest.json`
  - `{logs_root}/{run_id}/checkpoint.json`
  - `{logs_root}/{run_id}/{node_id}/...`
  - `{logs_root}/{run_id}/artifacts/...`

## UX Spec (Coreys-Attractor-Inspired)
This UI intentionally mirrors the flow that makes Corey’s Attractor productive while keeping the implementation dependency-light.

### Views
- **Monitor** (default): run list + run details (stages, graph, live log, artifacts, human gate controls)
- **Create**: DOT editor + validate + graph preview + run
- **Archived**: list archived runs and re-open or unarchive
- **Docs**: built-in documentation of UI + API + DOT

### Monitor View - Minimum Behaviors
- Run list shows: name, status, started time, current node, archived flag.
- Run details shows:
  - Status badge + elapsed time
  - Stage list with per-stage status and error detail
  - Graph panel (SVG) with zoom/pan and per-node status highlighting
  - Live log panel (append-only)
  - Human gate panel/modal when a question is pending
  - Artifacts viewer (list + preview + download)
- Actions (conditioned on run state):
  - Cancel (running only)
  - Archive/unarchive (terminal only)
  - Delete (terminal only, explicit confirmation)

### Create View - Minimum Behaviors
- Paste DOT, validate, preview as SVG, then run.
- Validation diagnostics must be shown inline and block run creation until fixed.
- Generate DOT from a natural-language prompt using a streaming endpoint (delta chunks) and show progressive DOT output in the editor as it arrives.
- Support “iterate mode”: pre-populate DOT from an existing run and stream back modifications from a natural-language change request.
- Provide a DOT fix workflow that takes a render/validator error and converges the DOT into a renderable graph, or refuses to run with clear diagnostics.

### DOT Agentic Loop Contract (Do Not Skip)
This is the critical “dotfile expansion / generation” loop. The implementation must follow this shape (Coreys Attractor reference: [`../../.scratch/refs/SPRINT-002/coreys-attractor-agentic-loops.md`](../../.scratch/refs/SPRINT-002/coreys-attractor-agentic-loops.md)).

1. Generate (streaming): UI starts generation with `POST /api/v1/dot/generate/stream` and receives `delta` chunks until `done`.
2. Validate: UI calls `POST /api/v1/dot/validate`; if invalid, disable Run and show diagnostics.
3. Render: UI calls `POST /api/v1/dot/render` for preview; if render fails, show the error.
4. Fix (streaming): UI can call `POST /api/v1/dot/fix/stream` with `{dotSource, error}`; then re-validate and re-render.
5. Iterate (streaming): when iterating, UI calls `POST /api/v1/dot/iterate/stream` with `{baseDot, changes}`; then re-validate and re-render.
6. Run: only when DOT validates does the UI enable Run and call `POST /api/v1/pipelines` (or iterate-run endpoint when preserving lineage).

Streaming DOT SSE payload expectations (minimum):
- `data: {"delta":"...chunk..."}` (0..N occurrences)
- `data: {"done":true,"dotSource":"...full dot..."}` (exactly once on success)
- `data: {"error":"...message..."}` (0..1, terminal)

## API Shapes (Selected, Starting Point)
The OpenAPI deliverable in Phase 0 is authoritative; these examples are here to remove ambiguity for implementers and test authors.

### `PipelineListItem` (response item for `GET /api/v1/pipelines`)
```json
{
  "id": "run-1700000000000-1",
  "displayName": "Autumn Falcon",
  "fileName": "pipeline.dot",
  "status": "running",
  "archived": false,
  "simulate": false,
  "autoApprove": true,
  "familyId": "run-1700000000000-1",
  "originalPrompt": "Write and test a REST API",
  "startedAtMs": 1700000000000,
  "finishedAtMs": null,
  "currentNodeId": "plan",
  "stages": []
}
```

### `PipelineDetail` (response for `GET /api/v1/pipelines/{id}`)
```json
{
  "id": "run-1700000000000-1",
  "displayName": "Autumn Falcon",
  "fileName": "pipeline.dot",
  "status": "running",
  "archived": false,
  "simulate": false,
  "autoApprove": true,
  "familyId": "run-1700000000000-1",
  "originalPrompt": "Write and test a REST API",
  "startedAtMs": 1700000000000,
  "finishedAtMs": null,
  "currentNodeId": "plan",
  "stages": [],
  "logs": [],
  "dotSource": "digraph P { ... }"
}
```

### `PendingQuestion` (response item for `GET /api/v1/pipelines/{id}/questions`)
```json
{
  "id": "q-1",
  "stage": "review_gate",
  "type": "MULTIPLE_CHOICE",
  "text": "Approve changes?",
  "options": [
    { "key": "A", "label": "Approve" },
    { "key": "F", "label": "Fix" }
  ]
}
```

### `Checkpoint` (response for `GET /api/v1/pipelines/{id}/checkpoint`)
```json
{
  "current_node": "plan",
  "completed_nodes": ["start"],
  "timestamp": "2026-03-03T00:00:00Z"
}
```

### `Context` (response for `GET /api/v1/pipelines/{id}/context`)
```json
{
  "graph.goal": "Write and test a REST API",
  "notes": "..."
}
```

### SSE Event Envelope (per-run and global streams)
```json
{
  "runId": "run-1700000000000-1",
  "tsMs": 1700000000123,
  "type": "StageCompleted",
  "payload": { "nodeId": "plan", "durationMs": 1200 }
}
```

Event types (starting point; align with `attractor-spec.md` Section 9.6):
- `PipelineStarted`
- `PipelineCompleted`
- `PipelineFailed`
- `StageStarted`
- `StageCompleted`
- `StageFailed`
- `StageRetrying`
- `InterviewStarted`
- `InterviewCompleted`
- `InterviewTimeout`
- `CheckpointSaved`

SSE snapshot strategy (required):
- On connect, the server must emit a `Snapshot` event first (per-run stream includes the run detail; global stream includes the run list), then emit incremental events.
- Clients must be able to reconnect without losing correctness: “snapshot then deltas” must converge UI state.

Error codes (starting point, align with OpenAPI):
- `BAD_REQUEST` (400): invalid field, invalid DOT, invalid answer payload
- `NOT_FOUND` (404): run id or artifact path not found
- `INVALID_STATE` (409): action not permitted in current run state
- `INTERNAL_ERROR` (500): unexpected server error

## Security and Robustness Invariants
- Artifact file routes must prevent path traversal and must not allow reading outside the run directory.
- The UI must HTML-escape any untrusted strings (LLM outputs, log lines, DOT labels) to prevent injection.
- Large text artifacts must be rendered with bounded UI (truncation + explicit “download full file” path).

## Risks and Mitigations
| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| Graph rendering depends on external Graphviz | UI loses the “graph preview” and “monitor graph” panels | Medium | Decide in ADR: server-side Graphviz, client-side rendering, or a fallback “DOT only” view; ensure Create/Monitor still usable without SVG. |
| SSE reconnect semantics are underspecified | UI becomes inconsistent after refresh/reconnect | Medium | Require `Snapshot`-first stream behavior and write explicit SSE contract tests (Phase 4). |
| Large logs/artifacts cause slow UI or crashes | Bad UX, browser hangs | Medium | Enforce bounded preview sizes, paginate lists, and always offer “download full file”. |
| Artifact path traversal or HTML injection | Security issue (local file read / XSS) | Medium | Centralize sanitization (path normalization + HTML escaping) and add negative tests for both. |
| `simulate=true` diverges from real execution behavior | Tests give false confidence | Medium | Ensure simulation uses the same event pipeline and artifact writers as real mode; include at least one non-simulated smoke path once LLM backend exists. |

## Evidence Tree (Required)
Plan for evidence artifacts (logs, screenshots, transcripts) to land under `.scratch/verification/SPRINT-002/`:
```text
.scratch/verification/SPRINT-002/
  phase0/
    adr/
    agentic-loops/
    openapi/
    sse-contract/
    diagrams/
  phase1/
    api/
    dot-agentic/
    sse/
    artifacts/
    security/
  phase2/
    ui-monitor/
    screenshots/
  phase3/
    ui-create/
    ui-archived/
    ui-docs/
  phase4/
    backend-tests/
    e2e/
    docs/
```

## Deliverables
### Phase 0 - Contracts, IA, and Decision Log
- [ ] **P0.0 - Evidence scaffolding: create `.scratch/verification/SPRINT-002/` tree + an index README**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P0.1 - Capture key architecture decisions in `docs/ADR.md` (ADR log)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P0.2 - Define the HTTP API contract (OpenAPI + written invariants)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P0.3 - Define the SSE event contract (event types and JSON envelopes)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P0.4 - Define UI information architecture and view-to-endpoint mapping**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P0.5 - Agentic loop reference extraction (Coreys Attractor): DOT generate/fix/iterate + iterate-run loop**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P0.6 - Mermaid appendix diagrams render via `mmdc` (outputs under `.scratch/verification/SPRINT-002/phase0/diagrams/`)**
```{placeholder for verification justification/reasoning and evidence log}```

#### Acceptance Criteria (Phase 0)
- [ ] ADR(s) exist and explicitly justify: UI stack choice, API surface choice, persistence approach, and SSE format
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Agentic DOT loops are explicitly specified as contracts (generate → validate/render → fix → iterate; plus iterate-run lineage) and include links to the Coreys Attractor reference notes
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] OpenAPI spec covers every endpoint the UI will call, including error envelopes
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] SSE contract describes ordering expectations, replay/initial snapshot behavior, and disconnect semantics
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Appendix diagrams exist and `mmdc` can render them without errors (artifacts under `.scratch/verification/SPRINT-002/phase0/diagrams/`)
```{placeholder for verification justification/reasoning and evidence log}```

---

### Phase 1 - Backend HTTP + SSE (UI-Serving API)
- [ ] **P1.1 - Server serves the dashboard shell at `/` (static HTML/CSS/JS)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.2 - List runs: `GET /api/v1/pipelines`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.3 - Create run: `POST /api/v1/pipelines`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.4 - Get run: `GET /api/v1/pipelines/{id}`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.5 - Cancel run: `POST /api/v1/pipelines/{id}/cancel`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.6 - Delete run: `DELETE /api/v1/pipelines/{id}` (refuse if running)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.7 - Per-run event stream (SSE): `GET /api/v1/pipelines/{id}/events`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.8 - Global event stream (SSE): `GET /api/v1/events`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.9 - List pending questions: `GET /api/v1/pipelines/{id}/questions`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.10 - Submit answer: `POST /api/v1/pipelines/{id}/questions/{qid}/answer`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.11 - Graph render endpoint returning SVG for a run: `GET /api/v1/pipelines/{id}/graph`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.12 - List artifacts: `GET /api/v1/pipelines/{id}/artifacts`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.13 - Fetch artifact file: `GET /api/v1/pipelines/{id}/artifacts/{path}`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.14 - Download artifacts ZIP: `GET /api/v1/pipelines/{id}/artifacts.zip`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.15 - DOT validate endpoint: `POST /api/v1/dot/validate`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.16 - DOT render endpoint: `POST /api/v1/dot/render`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.17 - Spec-core endpoint aliases (`/pipelines/...`) implemented as thin wrappers to the v1 API (including `/checkpoint` and `/context`)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.18 - Robust error envelope + CORS behavior for all endpoints**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.19 - Archive/unarchive endpoints: `POST /api/v1/pipelines/{id}/archive` and `/unarchive`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.20 - Runs listing can include/exclude archived runs (explicit contract, tested)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.21 - Security invariants enforced for artifacts and UI-served HTML**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.22 - Checkpoint endpoint: `GET /api/v1/pipelines/{id}/checkpoint`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.23 - Context endpoint: `GET /api/v1/pipelines/{id}/context`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.24 - Hermetic simulation mode for UI + tests (`simulate=true` runs without real LLM calls)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.25 - DOT generate endpoints: `POST /api/v1/dot/generate` and `POST /api/v1/dot/generate/stream`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.26 - DOT fix endpoints (LLM-assisted): `POST /api/v1/dot/fix` and `POST /api/v1/dot/fix/stream`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.27 - DOT iterate endpoints (LLM-assisted): `POST /api/v1/dot/iterate` and `POST /api/v1/dot/iterate/stream`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P1.28 - Iterate-run endpoint (lineage-preserving): `POST /api/v1/pipelines/{id}/iterate` (new run; source run unchanged)**
```{placeholder for verification justification/reasoning and evidence log}```

#### Acceptance Criteria (Phase 1)
- [ ] `GET /` returns a functional dashboard shell and loads without external network dependencies
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Creating a run persists a run directory and emits lifecycle + stage events to SSE clients
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Invalid DOT is rejected by validation endpoints with structured diagnostics and a stable error envelope
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] DOT generation/iteration endpoints stream deltas, strip markdown fences, and (in simulation mode) can be tested without network access
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Human-gate operations work end-to-end: pending question appears, answer submission is validated and recorded, run resumes
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Artifact file download endpoints prevent path traversal and handle binary vs text safely
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Archive/unarchive updates the run’s `archived` flag and affects listing behavior as specified
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Checkpoint and context endpoints return a consistent snapshot for a given run id (and 404 for unknown ids)
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] `simulate=true` runs do not require network access or API keys and still emit a realistic event stream and artifacts
```{placeholder for verification justification/reasoning and evidence log}```

---

### Phase 2 - UI: Monitor View (Real-Time Observability)
- [ ] **P2.1 - Navigation shell + persistent theme preference**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P2.2 - Run list + run selection (including deep-link by run id)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P2.3 - Run details panel: status, metadata, stage list**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P2.4 - Live log panel (append-only)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P2.5 - Graph panel: rendered SVG with zoom/pan and “download .dot”**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P2.6 - Human-gate UI: question modal/panel with answer buttons**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P2.7 - Artifact viewer: list files, preview text, download binary**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P2.8 - Archive/unarchive + delete actions wired into the Monitor view with confirmations**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P2.9 - Iterate action for terminal runs: “Iterate” opens Create view in iterate mode (base DOT + original prompt)**
```{placeholder for verification justification/reasoning and evidence log}```

#### Acceptance Criteria (Phase 2)
- [ ] Monitor view reflects state changes in near-real-time from SSE without manual refresh
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Stage list clearly shows stage status transitions and error details when present
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Graph visualization updates when a new checkpoint is saved (or equivalent state change)
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Human-gate interaction is usable: question is visible, answer submission has immediate feedback, and pipeline continues
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Artifact viewer can preview large text artifacts safely (bounded rendering) and provides download links
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Archive/unarchive and delete are guarded by state and require explicit confirmation in the UI
```{placeholder for verification justification/reasoning and evidence log}```

---

### Phase 3 - UI: Create View + History Utilities
- [ ] **P3.1 - DOT editor with validate-before-run workflow**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P3.2 - Graph preview for the edited DOT (render to SVG)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P3.3 - Create run from edited DOT and automatically navigate to Monitor view**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P3.4 - DOT generation from a natural-language prompt (streaming UI; validate-gated)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P3.5 - DOT fix workflow: on render/validate failure, provide a “Fix DOT” flow (streaming) that converges or refuses to run**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P3.6 - Iterate mode (dotfile expansion loop): modify an existing run’s DOT (streaming) and create a new run preserving lineage (`familyId`)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P3.7 - Run history basics: list recent runs and delete a run with confirmation**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P3.8 - Built-in documentation page (`/docs`) describing UI + API + DOT**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P3.9 - Archived view: list archived runs and allow unarchive/open**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P3.10 - Create view options: `simulate` toggle (and `autoApprove` if implemented) are passed to run creation**
```{placeholder for verification justification/reasoning and evidence log}```

#### Acceptance Criteria (Phase 3)
- [ ] Users can paste DOT, validate, preview, and run without leaving the UI
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Validation failures are actionable (diagnostics point to node/edge when available) and do not start a run
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] DOT generation is agentic: generation streams, run is validation-gated, and a DOT fix flow exists for render failures
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Iterating a pipeline run works end-to-end (terminal run → iterate mode → modified DOT → new run) and the source run is unchanged
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Run delete is safe: requires explicit confirmation and refuses to delete a currently running run
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] `/docs` is fully served by the application and remains readable without external network access
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Archived view clearly differentiates archived runs and provides a path to restore visibility
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] A user can run a pipeline in simulation mode from the Create view and observe completion without external dependencies
```{placeholder for verification justification/reasoning and evidence log}```

---

### Phase 4 - Test Strategy, E2E Proof, and Documentation
- [ ] **P4.1 - Backend unit/integration tests for every API endpoint the UI calls (logs under `.scratch/verification/SPRINT-002/phase4/backend-tests/`)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P4.2 - SSE tests: per-run stream and global stream contracts (logs under `.scratch/verification/SPRINT-002/phase4/backend-tests/`)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P4.3 - Browser E2E tests covering Create, Monitor, human-gate, and artifacts flows (screenshots/logs under `.scratch/verification/SPRINT-002/phase4/e2e/`)**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P4.4 - Manual UX verification checklist (desktop + mobile layouts) with screenshots + walkthrough notes in `.scratch/verification/SPRINT-002/phase4/ui/manual-ui-walkthrough.md`**
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] **P4.5 - Operator/developer docs: run server, run tests, troubleshoot**
```{placeholder for verification justification/reasoning and evidence log}```

#### Acceptance Criteria (Phase 4)
- [ ] All automated tests pass in CI-equivalent local runs
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] E2E tests include both positive and negative cases, and failures provide useful artifacts (screenshots/logs)
```{placeholder for verification justification/reasoning and evidence log}```
- [ ] Documentation is sufficient for a new developer to run the UI locally and understand the API surface
```{placeholder for verification justification/reasoning and evidence log}```

---

## API Contract (Proposed, UI-Facing)
This section is a concrete starting point; the authoritative version must live in the OpenAPI artifact produced in Phase 0.

### Error Envelope (All non-2xx)
```json
{ "error": "human readable message", "code": "MACHINE_CODE" }
```

### Endpoint Matrix (UI-Facing)
| Category | Method | Path | Purpose | Used By |
|---|---|---|---|---|
| UI | `GET` | `/` | Dashboard SPA shell (static HTML/CSS/JS) | All views |
| UI | `GET` | `/docs` | Built-in documentation (UI + API + DOT) | Docs view |
| Runs | `GET` | `/api/v1/pipelines` | List runs (with archived filtering per contract) | Monitor, Archived |
| Runs | `POST` | `/api/v1/pipelines` | Create + start run from DOT | Create |
| Runs | `GET` | `/api/v1/pipelines/{id}` | Get run detail (includes `dotSource`) | Monitor |
| Runs | `POST` | `/api/v1/pipelines/{id}/cancel` | Cancel a running run | Monitor |
| Runs | `DELETE` | `/api/v1/pipelines/{id}` | Delete a non-running run | Monitor |
| Runs | `POST` | `/api/v1/pipelines/{id}/archive` | Archive a terminal run | Monitor |
| Runs | `POST` | `/api/v1/pipelines/{id}/unarchive` | Unarchive a terminal run | Archived, Monitor |
| Events | `GET` | `/api/v1/events` | Global SSE stream (`Snapshot` then deltas) | Monitor |
| Events | `GET` | `/api/v1/pipelines/{id}/events` | Per-run SSE stream (`Snapshot` then deltas) | Monitor |
| Human | `GET` | `/api/v1/pipelines/{id}/questions` | List pending questions | Monitor |
| Human | `POST` | `/api/v1/pipelines/{id}/questions/{qid}/answer` | Submit answer | Monitor |
| State | `GET` | `/api/v1/pipelines/{id}/checkpoint` | Checkpoint snapshot | Monitor |
| State | `GET` | `/api/v1/pipelines/{id}/context` | Context key/value store | Monitor |
| Graph | `GET` | `/api/v1/pipelines/{id}/graph` | Rendered run graph as SVG | Monitor |
| Artifacts | `GET` | `/api/v1/pipelines/{id}/artifacts` | List run artifact files | Monitor |
| Artifacts | `GET` | `/api/v1/pipelines/{id}/artifacts/{path}` | Fetch one artifact file | Monitor |
| Artifacts | `GET` | `/api/v1/pipelines/{id}/artifacts.zip` | Download all artifacts as ZIP | Monitor |
| DOT | `POST` | `/api/v1/dot/validate` | Validate DOT and return diagnostics | Create |
| DOT | `POST` | `/api/v1/dot/render` | Render DOT to SVG | Create |
| DOT | `POST` | `/api/v1/dot/generate` | Generate DOT from NL prompt | Create |
| DOT | `POST` | `/api/v1/dot/generate/stream` | Generate DOT from NL prompt (streaming) | Create |
| DOT | `POST` | `/api/v1/dot/fix` | Fix DOT using Graphviz/validator error | Create |
| DOT | `POST` | `/api/v1/dot/fix/stream` | Fix DOT (streaming) | Create |
| DOT | `POST` | `/api/v1/dot/iterate` | Modify an existing DOT using NL changes | Create (Iterate mode) |
| DOT | `POST` | `/api/v1/dot/iterate/stream` | Modify DOT (streaming) | Create (Iterate mode) |
| Runs | `POST` | `/api/v1/pipelines/{id}/iterate` | Create a new run from modified DOT, inheriting the family | Monitor → Create |

### Create Run Request (`POST /api/v1/pipelines`)
Request body:

| Field | Type | Required | Default | Semantics |
|---|---|---|---|---|
| `dotSource` | string | yes | n/a | Full DOT source for the pipeline run. |
| `fileName` | string | no | `""` | Display-only name shown in the UI. |
| `displayName` | string | no | `""` | Optional friendly name; if empty, UI derives one from fileName/id. |
| `simulate` | boolean | no | `false` | Run without real LLM calls. Must still emit realistic events and artifacts. |
| `autoApprove` | boolean | no | `true` | If `true`, human gates are automatically answered (for CI/demo). |
| `originalPrompt` | string | no | `""` | If DOT was generated from a prompt, store it for later display. |

Response 201:
```json
{ "id": "run-1700000000000-1", "status": "running" }
```

### Core Endpoints (Minimum)
- `GET /api/v1/pipelines` list runs (summary)
- `POST /api/v1/pipelines` create a run from DOT (and start execution)
- `GET /api/v1/pipelines/{id}` get run (includes DOT and current checkpoint summary)
- `POST /api/v1/pipelines/{id}/cancel` cancel a running run
- `DELETE /api/v1/pipelines/{id}` delete a non-running run
- `POST /api/v1/pipelines/{id}/archive` archive a terminal run
- `POST /api/v1/pipelines/{id}/unarchive` unarchive a terminal run
- `GET /api/v1/pipelines/{id}/events` per-run SSE stream
- `GET /api/v1/events` global SSE stream (aggregated)
- `GET /api/v1/pipelines/{id}/graph` rendered SVG for the run’s DOT
- `GET /api/v1/pipelines/{id}/questions` pending questions
- `POST /api/v1/pipelines/{id}/questions/{qid}/answer` submit answer
- `GET /api/v1/pipelines/{id}/checkpoint` fetch current checkpoint snapshot
- `GET /api/v1/pipelines/{id}/context` fetch current context key-value store
- `GET /api/v1/pipelines/{id}/artifacts` list artifact files
- `GET /api/v1/pipelines/{id}/artifacts/{path}` fetch a single artifact file
- `GET /api/v1/pipelines/{id}/artifacts.zip` download all artifacts as zip
- `POST /api/v1/dot/validate` validate DOT and return diagnostics
- `POST /api/v1/dot/render` render DOT to SVG (used by Create preview)
- `POST /api/v1/dot/generate` generate DOT from a natural-language prompt
- `POST /api/v1/dot/generate/stream` generate DOT from a natural-language prompt (streaming)
- `POST /api/v1/dot/fix` fix a broken DOT using a provided error message
- `POST /api/v1/dot/fix/stream` fix a broken DOT (streaming)
- `POST /api/v1/dot/iterate` modify an existing DOT using a natural-language change request
- `POST /api/v1/dot/iterate/stream` modify an existing DOT (streaming)
- `POST /api/v1/pipelines/{id}/iterate` create a new run from modified DOT, preserving lineage (`familyId`)

Spec-core aliases (non-versioned, thin wrappers around the v1 implementation):
- `POST /pipelines`
- `GET /pipelines/{id}`
- `GET /pipelines/{id}/events` (SSE)
- `POST /pipelines/{id}/cancel`
- `GET /pipelines/{id}/graph`
- `GET /pipelines/{id}/questions`
- `POST /pipelines/{id}/questions/{qid}/answer`
- `GET /pipelines/{id}/checkpoint`
- `GET /pipelines/{id}/context`

## Test Matrix (Explicit Positive + Negative Coverage)
The implementation must include tests proving the following scenarios.

### Backend API (Selected)
Positive cases:
1. Create run with valid DOT returns 201 + run id; run directory exists; status transitions to terminal.
2. Per-run SSE streams stage lifecycle events in order for a simple linear pipeline.
3. Human gate: pending question appears; answer submission accepts a valid option; pipeline continues.
4. Artifact list shows per-stage files; fetching a text artifact returns correct content-type and contents.
5. Graph render returns valid SVG for well-formed DOT.
6. Cancel run transitions status to `cancelled` and emits a terminal event on SSE.
7. `GET /api/v1/pipelines/{id}/checkpoint` and `/context` return expected shapes while the run is active.
8. Create run with `simulate=true` completes deterministically and produces the expected artifact tree.
9. Generate DOT from NL prompt (simulation/mocked mode) returns DOT that validates and renders.
10. Iterate DOT from a base DOT + NL changes (simulation/mocked mode) returns modified DOT and preserves required graph invariants (start/exit nodes present, reachable nodes, etc.).
11. Fix DOT with a provided error message returns DOT that validates and renders (or returns a stable error envelope explaining refusal).

Negative cases:
1. Create run rejects missing/empty `dotSource` with 400 + error envelope.
2. Validate rejects syntactically invalid DOT with diagnostics and does not start execution.
3. Fetching a nonexistent run id returns 404 + error envelope.
4. Artifact path traversal attempt (e.g. `../`) is rejected with 400/404 (stable code), and no file is read.
5. Answer submission for unknown `qid` returns 404; submission for invalid option returns 400.
6. Cancel on a terminal run is rejected with 409 + error envelope.
7. Delete on a running run is rejected with 409 + error envelope.
8. Delete on a nonexistent run id returns 404 + error envelope.
9. Archive on a running run is rejected with 409 + error envelope.
10. Unarchive on a running run is rejected with 409 + error envelope.
11. Checkpoint/context endpoints on a nonexistent run id return 404 + error envelope.
12. DOT generate missing `prompt` returns 400 + error envelope.
13. DOT iterate missing `baseDot` or `changes` returns 400 + error envelope.
14. DOT fix missing `dotSource` returns 400 + error envelope.
15. Iterate-run endpoint refuses to iterate a running source run with 409 + error envelope.

### UI E2E (Selected)
Positive cases:
1. Paste DOT, validate, preview, run, then observe stage updates in Monitor view via SSE.
2. When a human gate appears, the UI presents answer buttons and the pipeline proceeds after selection.
3. Artifact viewer previews prompt/response and downloads the artifacts zip.
4. Generate DOT from NL prompt (streaming), validate passes, preview renders, and run starts successfully.
5. Iterate a terminal run: click Iterate, modify description, stream back modified DOT, and run as a new run (source run preserved).

Negative cases:
1. Invalid DOT shows a validation error and disables Run until fixed.
2. Network error (API returns 500) shows a user-visible error banner without breaking the SPA.
3. Selecting a deleted run id shows a clear not-found state and navigates safely back to the run list.
4. Graph render failure triggers the “Fix DOT” flow (or shows a clear fix affordance) and refuses to run until DOT is valid.

---

## Appendix (Mermaid Diagrams)

### A1. Core Domain Model (UI + API)
```mermaid
classDiagram
  class PipelineRun {
    +string id
    +string displayName
    +string fileName
    +string status
    +bool archived
    +bool simulate
    +bool autoApprove
    +string familyId
    +string originalPrompt
    +int startedAtMs
    +int finishedAtMs
    +string currentNodeId
  }

  class StageRun {
    +int index
    +string nodeId
    +string name
    +string status
    +int startedAtMs
    +int durationMs
    +string error
    +bool hasLog
  }

  class PendingQuestion {
    +string id
    +string stage
    +string type
    +string text
  }

  class ArtifactFile {
    +string path
    +int sizeBytes
    +bool isText
  }

  PipelineRun "1" o-- "*" StageRun
  PipelineRun "1" o-- "*" PendingQuestion
  PipelineRun "1" o-- "*" ArtifactFile
```

### A2. E-R Diagram (Durable Run Store on Disk)
```mermaid
erDiagram
  PIPELINE_RUN ||--o{ STAGE_RUN : contains
  PIPELINE_RUN ||--o{ QUESTION : pending
  PIPELINE_RUN ||--o{ ARTIFACT : stores

  PIPELINE_RUN {
    string id PK
    string logs_root
    string status
    datetime started_at
    datetime finished_at
    string dot_source_path
    string checkpoint_path
    string manifest_path
  }

  STAGE_RUN {
    string run_id FK
    string node_id
    int index
    string status
    string status_path
    string prompt_path
    string response_path
  }

  QUESTION {
    string run_id FK
    string qid
    string stage
    string type
    string payload_path
  }

  ARTIFACT {
    string run_id FK
    string rel_path
    int size_bytes
    bool is_text
  }
```

### A3. Workflow (Create -> Run -> Human Gate -> Complete)
```mermaid
flowchart TD
  A[User opens Create view] --> B[Paste DOT or Generate DOT]
  B --> C[Validate DOT]
  C -->|valid| D[Run pipeline]
  C -->|invalid| E[Show diagnostics]
  D --> F[Monitor view subscribes to SSE]
  F --> G{Human gate reached?}
  G -->|no| H[Run continues]
  G -->|yes| I[UI shows question + options]
  I --> J[User submits answer]
  J --> H
  H --> K{Terminal status?}
  K -->|no| F
  K -->|yes| L[User inspects artifacts/export]
```

### A4. Data Flow (SSE + HTTP)
```mermaid
sequenceDiagram
  participant U as Browser UI
  participant S as Attractor PHP Server
  participant E as Engine
  participant FS as Run Directory (Disk)

  U->>S: POST /api/v1/pipelines (dotSource)
  S->>E: start run
  E->>FS: write manifest.json
  E->>FS: write checkpoint.json (after each stage)
  E->>S: emit events
  U->>S: GET /api/v1/pipelines/{id}/events (SSE)
  S-->>U: data: {event}
  U->>S: GET /api/v1/pipelines/{id}/artifacts
  S->>FS: list files
  S-->>U: JSON list
```

### A5. Architecture (Runtime + UI)
```mermaid
flowchart LR
  subgraph Browser
    UI[Dashboard SPA]
  end

  subgraph Server
    HTTP[HTTP Router]
    SSE[SSE Stream]
    API[JSON API]
    ENG[Pipeline Engine]
    Q[Question Queue / Interviewer Adapter]
  end

  subgraph Storage
    DISK[(Run Directories)]
  end

  UI -->|fetch| API
  UI -->|connect| SSE
  HTTP --> API
  HTTP --> SSE
  API --> ENG
  ENG --> Q
  ENG --> DISK
  API --> DISK
```

### A6. DOT Agentic Loop (Generate / Validate / Fix / Iterate)
```mermaid
sequenceDiagram
  participant U as Browser UI
  participant S as Attractor PHP Server
  participant L as DOT LLM Backend
  participant V as DOT Validator/Renderer

  Note over U,S: Generate DOT (new pipeline)
  U->>S: POST /api/v1/dot/generate/stream {prompt}
  S->>L: generate DOT (stream)
  L-->>S: delta chunks
  S-->>U: data: {"delta":"..."}
  S-->>U: data: {"done":true,"dotSource":"..."}

  U->>S: POST /api/v1/dot/validate {dotSource}
  S->>V: validate
  V-->>S: {valid, diagnostics}
  S-->>U: {valid, diagnostics}

  alt valid
    U->>S: POST /api/v1/dot/render {dotSource}
    S->>V: render SVG
    V-->>S: {svg}
    S-->>U: {svg}
  else invalid or render error
    Note over U,S: Fix DOT (agentic convergence)
    U->>S: POST /api/v1/dot/fix/stream {dotSource, error}
    S->>L: fix DOT (stream)
    L-->>S: delta chunks
    S-->>U: data: {"delta":"..."}
    S-->>U: data: {"done":true,"dotSource":"..."}
  end

  Note over U,S: Iterate existing run DOT (dotfile expansion)
  U->>S: POST /api/v1/dot/iterate/stream {baseDot, changes}
  S->>L: iterate DOT (stream)
  L-->>S: delta chunks
  S-->>U: data: {"delta":"..."}
  S-->>U: data: {"done":true,"dotSource":"..."}

  U->>S: POST /api/v1/pipelines/{id}/iterate {dotSource}
  S-->>U: 200 {newId}
```
