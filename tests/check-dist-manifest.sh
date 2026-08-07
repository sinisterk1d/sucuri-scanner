#!/bin/sh
#
# Verify that the wordpress.org build ships exactly what it should.
#
# .gitattributes is a denylist: a path ships unless someone remembers to add an
# "export-ignore" line for it. Forgetting is silent and nothing fails — the file
# just turns up in the published zip, and the first person to notice is whoever
# reads the release. This turns the denylist into an enforced allowlist: anything
# new at the top level has to be either shipped on purpose or ignored on purpose,
# and either way somebody has to edit this list to say which.
#
# It also catches the opposite mistake, an export-ignore rule that is too broad
# and drops a directory the plugin needs at runtime.
#
# Usage:
#   tests/check-dist-manifest.sh          # check HEAD
#   tests/check-dist-manifest.sh <ref>    # check any commit-ish

set -eu

cd "$(dirname "$0")/.."

ref="${1:-HEAD}"

# Top-level entries that belong in the published plugin. Everything the plugin
# loads at runtime, and the files wordpress.org itself reads.
expected="LICENSE
inc
index.html
lang
readme.txt
src
sucuri.php"

actual=$(git archive "$ref" | tar -t | sed 's|/.*||' | sed '/^$/d' | sort -u)

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

printf '%s\n' "$expected" | sort > "$work/expected"
printf '%s\n' "$actual" > "$work/actual"

extra=$(grep -Fxv -f "$work/expected" "$work/actual" || true)
missing=$(grep -Fxv -f "$work/actual" "$work/expected" || true)

fail=0

if [ -n "$extra" ]; then
    echo "ERROR: these would ship to wordpress.org but are not in the allowlist:" >&2
    echo "$extra" | sed 's/^/  + /' >&2
    echo >&2
    echo "       Add an 'export-ignore' line to .gitattributes if it is a dev-only" >&2
    echo "       file, or add it to the allowlist in $0 if it should ship." >&2
    fail=1
fi

if [ -n "$missing" ]; then
    echo "ERROR: these should ship but are missing from the build:" >&2
    echo "$missing" | sed 's/^/  - /' >&2
    echo >&2
    echo "       An export-ignore rule in .gitattributes is too broad." >&2
    fail=1
fi

if [ "$fail" -ne 0 ]; then
    exit 1
fi

count=$(git archive "$ref" | tar -t | grep -cv '/$' || true)
entries=$(printf '%s\n' "$actual" | wc -l | tr -d ' ')

echo "OK: build ships $count files across $entries top-level entries."
