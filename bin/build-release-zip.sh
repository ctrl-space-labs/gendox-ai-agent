#!/usr/bin/env bash
# Build a wordpress.org-ready zip of Gendox AI Agent.
# Output: builds/gendox-ai-agent-<version>.zip (folder inside zip is gendox-ai-agent/)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

ENTRY="gendox-ai-agent.php"
SLUG="gendox-ai-agent"
BUILDS_DIR="$ROOT/builds"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}-build.XXXXXX")"

cleanup() {
	rm -rf "$STAGING"
}
trap cleanup EXIT

if [[ ! -f "$ENTRY" ]]; then
	echo "error: $ENTRY not found in $ROOT" >&2
	exit 1
fi

VERSION="$(grep -E '^\s*\*\s*Version:' "$ENTRY" | head -1 | awk '{print $NF}')"
if [[ -z "${VERSION:-}" ]]; then
	echo "error: could not read Version from $ENTRY" >&2
	exit 1
fi

ZIP_NAME="${SLUG}-${VERSION}.zip"
ZIP_PATH="${BUILDS_DIR}/${ZIP_NAME}"

mkdir -p "$BUILDS_DIR"
rm -f "$ZIP_PATH"

# Prefer .distignore when present; always drop VCS/IDE/dev-only paths.
RSYNC_EXCLUDES=(
	--exclude='.git'
	--exclude='.github'
	--exclude='.idea'
	--exclude='.DS_Store'
	--exclude='**/.DS_Store'
	--exclude='.wordpress-org'
	--exclude='AGENTS.md'
	--exclude='.gitignore'
	--exclude='.distignore'
	--exclude='builds'
	--exclude='bin'
)

mkdir -p "${STAGING}/${SLUG}"
rsync -a "${RSYNC_EXCLUDES[@]}" "${ROOT}/" "${STAGING}/${SLUG}/"

(
	cd "$STAGING"
	zip -r "$ZIP_PATH" "$SLUG" -x '*.DS_Store' '*/.DS_Store'
)

echo "Built ${ZIP_PATH} ($(du -h "$ZIP_PATH" | awk '{print $1}'))"
unzip -l "$ZIP_PATH" | head -20
echo "…"
unzip -l "$ZIP_PATH" | tail -5
