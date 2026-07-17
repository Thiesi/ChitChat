#!/usr/bin/env bash

set -euo pipefail

fail() {
    printf 'release validation failed: %s\n' "$*" >&2
    exit 1
}

VERSION=${1:-}
COMMIT_SHA=${2:-}
PUBLICATION=${3:-validate-only}

[[ -n "$VERSION" ]] || fail 'version argument is required'
[[ -n "$COMMIT_SHA" ]] || fail 'full commit SHA argument is required'
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z]+([.-][0-9A-Za-z]+)*)?$ ]] \
    || fail "version is not a supported semantic version: $VERSION"
[[ "$COMMIT_SHA" =~ ^[0-9a-fA-F]{40}$ ]] \
    || fail 'commit SHA must contain exactly 40 hexadecimal characters'

case "$PUBLICATION" in
    validate-only)
        ;;
    stable)
        [[ "$VERSION" != *-* ]] || fail 'stable publication cannot use a pre-release version'
        ;;
    prerelease)
        [[ "$VERSION" == *-* ]] || fail 'pre-release publication requires a pre-release version'
        ;;
    *)
        fail "unknown publication mode: $PUBLICATION"
        ;;
esac

command -v git >/dev/null 2>&1 || fail 'git is required'

git fetch --quiet --no-tags origin '+refs/heads/main:refs/remotes/origin/main'

RESOLVED_SHA=$(git rev-parse --verify "${COMMIT_SHA}^{commit}") \
    || fail "commit does not exist: $COMMIT_SHA"
CURRENT_SHA=$(git rev-parse --verify HEAD)
[[ "$CURRENT_SHA" == "$RESOLVED_SHA" ]] \
    || fail "checked-out commit $CURRENT_SHA does not match requested commit $RESOLVED_SHA"

git merge-base --is-ancestor "$RESOLVED_SHA" refs/remotes/origin/main \
    || fail "commit $RESOLVED_SHA is not reachable from main"

TAG="v${VERSION}"
NOTES_FILE="docs/releases/${TAG}.md"

[[ -f .env.example ]] || fail '.env.example is missing'
grep -Fqx "APP_VERSION=${VERSION}" .env.example \
    || fail ".env.example does not declare APP_VERSION=${VERSION}"

grep -Fq "applicationVersion: self::env('APP_VERSION', '${VERSION}')" src/Config.php \
    || fail "src/Config.php does not default APP_VERSION to ${VERSION}"

[[ -f "$NOTES_FILE" ]] || fail "release notes are missing: $NOTES_FILE"
[[ "$(head -n 1 "$NOTES_FILE")" == "# ChitChat ${TAG}" ]] \
    || fail "release notes must begin with '# ChitChat ${TAG}'"

grep -Fq "## [${VERSION}] - " CHANGELOG.md \
    || fail "CHANGELOG.md has no dated ${VERSION} release entry"

if git ls-remote --exit-code --tags origin "refs/tags/${TAG}" >/dev/null 2>&1; then
    git fetch --quiet --force origin "refs/tags/${TAG}:refs/tags/${TAG}"
    [[ "$(git cat-file -t "refs/tags/${TAG}")" == 'tag' ]] \
        || fail "existing ${TAG} is lightweight; release tags must be annotated"
    TAG_TARGET_SHA=$(git rev-parse --verify "${TAG}^{commit}")
    [[ "$TAG_TARGET_SHA" == "$RESOLVED_SHA" ]] \
        || fail "existing ${TAG} points to ${TAG_TARGET_SHA}, not ${RESOLVED_SHA}"
    printf 'Existing annotated tag %s already points to the requested commit; publication may resume safely.\n' "$TAG"
fi

printf 'Validated %s at %s for %s publication.\n' "$TAG" "$RESOLVED_SHA" "$PUBLICATION"
printf 'Release notes: %s\n' "$NOTES_FILE"
