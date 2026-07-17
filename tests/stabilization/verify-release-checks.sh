#!/usr/bin/env bash

set -euo pipefail

fail() {
    printf 'release check verification failed: %s\n' "$*" >&2
    exit 1
}

COMMIT_SHA=${1:-}
[[ "$COMMIT_SHA" =~ ^[0-9a-fA-F]{40}$ ]] \
    || fail 'full commit SHA argument is required'
[[ -n "${GITHUB_REPOSITORY:-}" ]] || fail 'GITHUB_REPOSITORY is required'
[[ -n "${GH_TOKEN:-}" ]] || fail 'GH_TOKEN is required'

command -v gh >/dev/null 2>&1 || fail 'GitHub CLI is required'
command -v jq >/dev/null 2>&1 || fail 'jq is required'

CHECK_RUNS=$(gh api \
    -H 'Accept: application/vnd.github+json' \
    -H 'X-GitHub-Api-Version: 2022-11-28' \
    "/repos/${GITHUB_REPOSITORY}/commits/${COMMIT_SHA}/check-runs?per_page=100")

REQUIRED_CHECKS=(
    'static'
    'integration'
    'browser (chromium)'
    'browser (firefox)'
    'release-rehearsal'
    'reverse-proxy'
    'browser-webkit'
)

printf 'Required release checks for %s:\n' "$COMMIT_SHA"
for CHECK_NAME in "${REQUIRED_CHECKS[@]}"; do
    MATCH=$(jq -r --arg name "$CHECK_NAME" '
        [
            .check_runs[]
            | select(.name == $name and .app.slug == "github-actions")
            | {id, status, conclusion, html_url}
        ]
        | sort_by(.id)
        | last // empty
        | [.id, .status, .conclusion, .html_url]
        | @tsv
    ' <<<"$CHECK_RUNS")

    [[ -n "$MATCH" ]] || fail "required GitHub Actions check was not found: $CHECK_NAME"

    IFS=$'\t' read -r CHECK_ID STATUS CONCLUSION URL <<<"$MATCH"
    printf '  %-22s id=%-12s status=%-10s conclusion=%-9s %s\n' \
        "$CHECK_NAME" "$CHECK_ID" "$STATUS" "$CONCLUSION" "$URL"

    [[ "$STATUS" == 'completed' && "$CONCLUSION" == 'success' ]] \
        || fail "latest required check is not successful: $CHECK_NAME"
done

printf 'Every required release check succeeded on %s.\n' "$COMMIT_SHA"
