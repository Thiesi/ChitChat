#!/usr/bin/env bash

set -euo pipefail

fail() {
    printf 'published release verification failed: %s\n' "$*" >&2
    exit 1
}

VERSION=${1:-}
COMMIT_SHA=${2:-}
PUBLICATION=${3:-}

[[ -n "$VERSION" ]] || fail 'version argument is required'
[[ "$COMMIT_SHA" =~ ^[0-9a-fA-F]{40}$ ]] \
    || fail 'full commit SHA argument is required'
[[ "$PUBLICATION" == 'stable' || "$PUBLICATION" == 'prerelease' ]] \
    || fail 'publication must be stable or prerelease'
[[ -n "${GITHUB_REPOSITORY:-}" ]] || fail 'GITHUB_REPOSITORY is required'
[[ -n "${GH_TOKEN:-}" ]] || fail 'GH_TOKEN is required'

command -v git >/dev/null 2>&1 || fail 'git is required'
command -v gh >/dev/null 2>&1 || fail 'GitHub CLI is required'
command -v jq >/dev/null 2>&1 || fail 'jq is required'

TAG="v${VERSION}"
NOTES_FILE="docs/releases/${TAG}.md"
[[ -f "$NOTES_FILE" ]] || fail "release notes are missing: $NOTES_FILE"

git fetch --quiet --force origin "refs/tags/${TAG}:refs/tags/${TAG}"
[[ "$(git cat-file -t "refs/tags/${TAG}")" == 'tag' ]] \
    || fail "${TAG} is not an annotated tag"
[[ "$(git rev-parse --verify "${TAG}^{commit}")" == "$COMMIT_SHA" ]] \
    || fail "${TAG} does not resolve to ${COMMIT_SHA}"

RELEASE_JSON=$(gh api \
    -H 'Accept: application/vnd.github+json' \
    -H 'X-GitHub-Api-Version: 2022-11-28' \
    "/repos/${GITHUB_REPOSITORY}/releases/tags/${TAG}")

[[ "$(jq -r '.draft' <<<"$RELEASE_JSON")" == 'false' ]] \
    || fail "${TAG} is still a draft release"
[[ "$(jq -r '.tag_name' <<<"$RELEASE_JSON")" == "$TAG" ]] \
    || fail 'release tag metadata does not match'
[[ "$(jq -r '.name // ""' <<<"$RELEASE_JSON")" == "ChitChat ${TAG}" ]] \
    || fail 'release title does not match the expected ChitChat title'

EXPECTED_PRERELEASE=false
if [[ "$PUBLICATION" == 'prerelease' ]]; then
    EXPECTED_PRERELEASE=true
fi
[[ "$(jq -r '.prerelease' <<<"$RELEASE_JSON")" == "$EXPECTED_PRERELEASE" ]] \
    || fail 'release stable/pre-release classification does not match'

EXPECTED_BODY=$(cat "$NOTES_FILE")
ACTUAL_BODY=$(jq -r '.body // ""' <<<"$RELEASE_JSON")
[[ "$ACTUAL_BODY" == "$EXPECTED_BODY" ]] \
    || fail 'published release body does not match the committed release notes'

if [[ "$PUBLICATION" == 'stable' ]]; then
    LATEST_TAG=$(gh api \
        -H 'Accept: application/vnd.github+json' \
        -H 'X-GitHub-Api-Version: 2022-11-28' \
        "/repos/${GITHUB_REPOSITORY}/releases/latest" \
        --jq '.tag_name')
    [[ "$LATEST_TAG" == "$TAG" ]] \
        || fail "stable release ${TAG} is not marked latest"
fi

jq -r '.html_url' <<<"$RELEASE_JSON"
