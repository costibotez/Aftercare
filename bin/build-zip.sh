#!/usr/bin/env bash
#
# Builds the WordPress.org distribution zip.
#
# The archive contains a single top-level "aftercare/" directory, as the
# plugin directory expects, and excludes everything listed in .distignore:
# repository scaffolding and the premium-only sources that must not ship in
# the free plugin.
#
# Usage: bin/build-zip.sh [output-directory]

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
SLUG="aftercare"
OUT_DIR="${1:-$ROOT/dist}"

VERSION="$( grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9A-Za-z.\-]+' "$ROOT/$SLUG.php" || true )"
if [ -z "$VERSION" ]; then
	echo "Could not read the version from $SLUG.php" >&2
	exit 1
fi

README_TAG="$( grep -m1 -oP '^Stable tag:\s*\K[0-9A-Za-z.\-]+' "$ROOT/readme.txt" || true )"
if [ "$VERSION" != "$README_TAG" ]; then
	echo "Version mismatch: $SLUG.php says '$VERSION', readme.txt stable tag says '$README_TAG'" >&2
	exit 1
fi

STAGE="$( mktemp -d )"
trap 'rm -rf "$STAGE"' EXIT

DEST="$STAGE/$SLUG"
mkdir -p "$DEST"
cp -a "$ROOT/." "$DEST/"

# Never ship the working directory's own build output or tooling.
rm -rf "$DEST/dist" "$DEST/bin" "$DEST/vendor" "$DEST/.git"

# Apply .distignore. A pattern containing a slash is matched from the plugin
# root; a bare name is matched at any depth.
while IFS= read -r pattern; do
	case "$pattern" in
		''|\#*) continue ;;
	esac
	pattern="${pattern%/}"
	if [ "$pattern" != "${pattern#*/}" ]; then
		rm -rf "${DEST:?}/$pattern"
	else
		find "$DEST" -depth -name "$pattern" -exec rm -rf {} +
	fi
done < "$ROOT/.distignore"

# A declared Domain Path must survive the exclusions above, or the directory
# reports a header pointing at a folder that is not in the package.
DOMAIN_PATH="$( grep -m1 -oP '^\s*\*\s*Domain Path:\s*\K\S+' "$DEST/$SLUG.php" || true )"
if [ -n "$DOMAIN_PATH" ] && [ ! -d "$DEST/${DOMAIN_PATH#/}" ]; then
	echo "Domain Path header says '$DOMAIN_PATH' but that folder is not in the build" >&2
	exit 1
fi

mkdir -p "$OUT_DIR"
ZIP="$OUT_DIR/$SLUG.$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -rq -X "$ZIP" "$SLUG" )

echo "Built $ZIP"
