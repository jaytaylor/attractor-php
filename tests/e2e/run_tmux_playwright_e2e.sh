#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
PORT="${E2E_PORT:-18084}"
BASE_URL="http://127.0.0.1:${PORT}"
SESSION_NAME="attractor_e2e_${PORT}_$$"
EVIDENCE_DIR="${ROOT_DIR}/.scratch/verification/SPRINT-002/e2e"
SERVER_LOG="${EVIDENCE_DIR}/php-server-e2e.log"
PLAYWRIGHT_LOG="${EVIDENCE_DIR}/playwright-e2e.log"

if [[ -z "${OPENAI_API_KEY:-}" || -z "${ANTHROPIC_API_KEY:-}" || -z "${GEMINI_API_KEY:-}" ]]; then
  echo "OPENAI_API_KEY, ANTHROPIC_API_KEY, and GEMINI_API_KEY must be set for e2e" >&2
  exit 1
fi

mkdir -p "${EVIDENCE_DIR}"

cleanup() {
  tmux kill-session -t "${SESSION_NAME}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

tmux new-session -d -s "${SESSION_NAME}" \
  "cd '${ROOT_DIR}' && php -S 127.0.0.1:${PORT} -t public public/index.php > '${SERVER_LOG}' 2>&1"

ready=0
for _ in $(seq 1 120); do
  if curl -fsS "${BASE_URL}/" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 0.25
done

if [[ "${ready}" -ne 1 ]]; then
  echo "server failed to start in tmux session ${SESSION_NAME}" >&2
  exit 1
fi

cd "${ROOT_DIR}"
BASE_URL="${BASE_URL}" npx --yes -p playwright node tests/e2e/playwright_e2e.js | tee "${PLAYWRIGHT_LOG}"
