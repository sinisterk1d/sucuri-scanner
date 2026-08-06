#!/bin/sh
#
# Verify that every place the plugin states its own version agrees.
#
# WordPress reads the version from four independent files, and nothing makes
# them agree on its own. A mismatch is not caught by any test: the plugin runs
# fine, but wordpress.org publishes whatever "Stable tag" says while the
# installed plugin reports the header version, so update notices and the
# SucuriScanner user agent silently disagree with the released build.
#
# Usage:
#   tests/check-version-consistency.sh              # the four files agree
#   tests/check-version-consistency.sh v2.7.5       # ...and match a release tag
#
# Exits non-zero and prints every value it found when they disagree.

set -eu

cd "$(dirname "$0")/.."

fail=0

report() {
    printf '  %-34s %s\n' "$1" "$2"
}

# " * Version: 2.7.5" in the plugin header WordPress parses.
header=$(sed -n 's/^ \* Version:[[:space:]]*\(.*[^[:space:]]\)[[:space:]]*$/\1/p' sucuri.php | head -1)

# define('SUCURISCAN_VERSION', '2.7.5'); — what the running code reports.
constant=$(sed -n "s/^define('SUCURISCAN_VERSION',[[:space:]]*'\([^']*\)').*$/\1/p" sucuri.php | head -1)

# "Stable tag: 2.7.5" — what wordpress.org actually publishes.
stable=$(sed -n 's/^Stable tag:[[:space:]]*\(.*[^[:space:]]\)[[:space:]]*$/\1/p' readme.txt | head -1)

# "Project-Id-Version: <plugin name> 2.7.5\n" in the translation template.
pot=$(awk '/^"Project-Id-Version:/ { v = $NF; sub(/\\n"$/, "", v); print v; exit }' lang/sucuri-scanner.pot)

echo "Plugin version strings:"
report "sucuri.php (Version header)" "${header:-<not found>}"
report "sucuri.php (SUCURISCAN_VERSION)" "${constant:-<not found>}"
report "readme.txt (Stable tag)" "${stable:-<not found>}"
report "lang/sucuri-scanner.pot" "${pot:-<not found>}"

for value in "$header" "$constant" "$stable" "$pot"; do
    if [ -z "$value" ]; then
        echo "ERROR: a version string could not be read; see <not found> above." >&2
        exit 1
    fi
done

if [ "$header" != "$constant" ] || [ "$header" != "$stable" ] || [ "$header" != "$pot" ]; then
    echo "ERROR: the four version strings do not agree." >&2
    echo "       Update all four, then re-run 'make update-translations'." >&2
    fail=1
fi

# A release with no notes is a release that was not prepared.
if ! grep -q "^= ${header} =\$" readme.txt; then
    echo "ERROR: readme.txt has no '= ${header} =' changelog section." >&2
    fail=1
fi

# On a release build, the git tag is the fifth version string.
if [ "$#" -ge 1 ]; then
    tag="${1#v}"
    report "release tag" "$1"

    if [ "$tag" != "$header" ]; then
        echo "ERROR: release tag '$1' does not match plugin version '${header}'." >&2
        fail=1
    fi
fi

if [ "$fail" -ne 0 ]; then
    exit 1
fi

echo "OK: version ${header} is consistent."
