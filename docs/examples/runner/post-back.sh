#!/usr/bin/env bash
# Feedback Board: reference post-back sender for an agent runner (docs/runner-contract.md §4).
# Copy it into the repository whose workflow runs your agent, e.g. .github/scripts/feedback-post-back.sh.
#
#   post-back.sh status <running|completed|failed> [error_code]
#   post-back.sh result [analysis_file] [diff_file]      # the PR link comes from PR_URL
#
# Environment: INGEST_URL, INGEST_SECRET, ITEM_ID, TASK_ID; optional PR_URL.
# FEEDBACK_DRY_RUN=1 prints the timestamp, the signature and the body instead of sending.
# Needs bash, jq, openssl and curl 7.76+ (all on GitHub's ubuntu runners).
set -euo pipefail

: "${INGEST_URL:?INGEST_URL is required}"
: "${INGEST_SECRET:?INGEST_SECRET is required}"
: "${ITEM_ID:?ITEM_ID is required}"
: "${TASK_ID:?TASK_ID is required}"

if [[ ! "$ITEM_ID" =~ ^[1-9][0-9]{0,18}$ ]]; then
  echo "ITEM_ID must be a positive integer" >&2
  exit 2
fi

usage='usage: post-back.sh status <running|completed|failed> [error_code] | result [analysis_file] [diff_file]'
kind="${1:-}"
shift || true

body_file=$(mktemp)
response_file=$(mktemp)
trap 'rm -f "$body_file" "$response_file"' EXIT

# A file that is missing or empty is left out of the body.
present() { [[ -n "${1:-}" && -s "$1" ]]; }

# Build the body with jq, never by pasting strings: any quote, backslash or newline stays data.
# -j: no trailing newline, so the signed bytes are exactly the bytes sent.
case "$kind" in
  status)
    jq -cjn --argjson item_id "$ITEM_ID" --arg task_id "$TASK_ID" --arg status "${1:?$usage}" --arg error_code "${2:-}" \
      '{item_id: $item_id, action: "runner_status", task_id: $task_id, status: $status}
       + (if $error_code == "" then {} else {error_code: $error_code} end)' > "$body_file"
    ;;
  result)
    analysis=/dev/null
    diff=/dev/null
    if present "${1:-}"; then analysis="$1"; fi
    if present "${2:-}"; then diff="$2"; fi
    jq -cjn --argjson item_id "$ITEM_ID" --arg task_id "$TASK_ID" --arg pr_url "${PR_URL:-}" \
      --rawfile analysis "$analysis" --rawfile diff "$diff" \
      '{item_id: $item_id, action: "runner_result", task_id: $task_id}
       + (if $analysis == "" then {} else {analysis: $analysis} end)
       + (if $diff == "" then {} else {diff: $diff} end)
       + (if $pr_url == "" then {} else {pr_url: $pr_url} end)' > "$body_file"
    ;;
  *)
    echo "$usage" >&2
    exit 2
    ;;
esac

# HMAC-SHA256 over "{timestamp}.{body}", bare hex.
sign() {
  { printf '%s.' "$1"; cat "$body_file"; } | openssl dgst -sha256 -hmac "$INGEST_SECRET" | awk '{print $NF}'
}

if [[ "${FEEDBACK_DRY_RUN:-}" == "1" ]]; then
  ts=$(date +%s)
  printf '%s\n%s\n' "$ts" "$(sign "$ts")"
  cat "$body_file"
  echo
  exit 0
fi

# 429 (rate limit), 503 (busy) and a failed connection are temporary: try again with a fresh
# timestamp and signature, because the ingest accepts each signature once.
for attempt in 1 2 3 4 5; do
  ts=$(date +%s)
  set +e
  code=$(curl --fail-with-body -sS -o "$response_file" -w '%{http_code}' \
    --connect-timeout 10 --max-time 30 -X POST "$INGEST_URL" \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    -H "X-Feedback-Timestamp: $ts" -H "X-Feedback-Signature: $(sign "$ts")" \
    --data-binary @"$body_file")
  rc=$?
  set -e

  # --fail-with-body exits 0 on a 3xx too: only a 2xx is success.
  if [[ $rc -eq 0 && "$code" == 2?? ]]; then
    cat "$response_file"
    echo
    exit 0
  fi

  if [[ "$code" == 429 || "$code" == 503 || "$code" == 000 ]] && (( attempt < 5 )); then
    sleep $(( 2 ** attempt ))
    continue
  fi

  echo "post-back failed (HTTP $code):" >&2
  cat "$response_file" >&2
  echo >&2
  exit $(( rc == 0 ? 1 : rc ))
done
