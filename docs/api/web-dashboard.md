# Web Dashboard Contract Notes

## Routing
- `GET /` serves the embedded SPA shell.
- `GET /docs` serves static dashboard/API docs.
- Client-side hash routes:
  - `#monitor` / `#monitor/{runId}`
  - `#create`
  - `#archived`
  - `#docs`

## Error Envelope
All non-2xx API responses return:

```json
{ "status": 400, "error": "human readable message", "code": "MACHINE_CODE" }
```

## SSE Contract
- Content type: `text/event-stream`
- Frame shape: `data: {json}\n\n`
- Ordering: first frame is always `Snapshot`, followed by event deltas.
- Cursoring: both stream endpoints accept optional `sinceTs` (epoch milliseconds) and return only events with `tsMs > sinceTs`.
- Polling model: the built-in dashboard polls run/global streams with `sinceTs` for incremental updates while keeping snapshot-first reconnect semantics.

Per-run snapshot payload:
- Full run detail object for requested run id.

Global snapshot payload:
- Current run list (including archived runs).

`sinceTs` handling:
- Non-numeric, empty, or negative cursor inputs are normalized to `0`.
- Future cursor values return a valid `Snapshot` plus an empty incremental set.

## DOT Streaming Endpoints
- `POST /api/v1/dot/generate/stream`
- `POST /api/v1/dot/fix/stream`
- `POST /api/v1/dot/iterate/stream`

Each emits:
- Zero or more `{"delta":"..."}` frames
- Exactly one terminal `{"done":true,"dotSource":"..."}` frame on success
- Exactly one terminal `{"error":"..."}` frame on malformed input failures

## Security Invariants
- Artifact fetch paths reject traversal (`..`) and cannot escape run artifact root.
- UI uses text-safe rendering for logs and artifact previews to avoid injection.

## Lifecycle Invariants
- Archive and unarchive are allowed only for terminal runs (`completed`, `failed`, `cancelled`).
- Archive on already archived runs returns `409 INVALID_STATE`.
- Unarchive on non-archived runs returns `409 INVALID_STATE`.
- Delete is allowed only for non-running runs.

## Operator Runbook
1. Start local server:
   - `php -S 127.0.0.1:8080 public/index.php`
2. Open dashboard:
   - `http://127.0.0.1:8080/`
3. Run verification:
   - `timeout 180 make build`
   - `timeout 180 make test`
4. Review artifacts:
   - `.scratch/verification/SPRINT-002/phase4/backend-tests/build.log`
   - `.scratch/verification/SPRINT-002/phase4/backend-tests/test.log`
   - `.scratch/verification/SPRINT-002/phase4/e2e/e2e.log`

Troubleshooting:
- If runs are stale in Monitor, use Refresh and verify `/api/v1/pipelines/{id}/events?sinceTs=...` returns `Snapshot` first.
- If Create run is blocked, run Validate and inspect diagnostics before preview/run.
- If artifacts fail to load, confirm path is relative and does not include traversal segments.
