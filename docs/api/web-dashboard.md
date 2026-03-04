# Web Dashboard Contract Notes

## Routing
- `GET /` serves the embedded SPA shell.
- `GET /docs` serves static dashboard/API docs.

## Error Envelope
All non-2xx API responses return:

```json
{ "error": "human readable message", "code": "MACHINE_CODE" }
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

## DOT Streaming Endpoints
- `POST /api/v1/dot/generate/stream`
- `POST /api/v1/dot/fix/stream`
- `POST /api/v1/dot/iterate/stream`

Each emits:
- Zero or more `{"delta":"..."}` frames
- Exactly one terminal `{"done":true,"dotSource":"..."}` frame on success
- Optional terminal `{"error":"..."}` frame on failure

## Security Invariants
- Artifact fetch paths reject traversal (`..`) and cannot escape run artifact root.
- UI uses text-safe rendering for logs and artifact previews to avoid injection.
