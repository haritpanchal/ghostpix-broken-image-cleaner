#!/usr/bin/env bash
#
# Build the distribution zip — the thing that actually gets submitted and
# reviewed. Development files listed in .distignore are left out, so what the
# reviewer runs Plugin Check against is what this produces, not the working
# directory.
#
# Usage: bin/build.sh

set -euo pipefail

SLUG="ghostpix-broken-image-cleaner"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="${ROOT}/build"

rm -rf "${BUILD}"
mkdir -p "${BUILD}/${SLUG}"

# rsync skips blank lines and lines starting with # in an exclude file, so
# .distignore can stay commented.
rsync -a \
	--exclude-from="${ROOT}/.distignore" \
	--exclude="build" \
	--exclude="bin" \
	"${ROOT}/" "${BUILD}/${SLUG}/"

# .distignore is easy to forget, and macOS writes .DS_Store files into any
# directory somebody opens in Finder. Prune the usual offenders outright...
find "${BUILD}/${SLUG}" \
	\( -name '.DS_Store' -o -name '._*' -o -name 'Thumbs.db' -o -name '*.log' \) \
	-delete

# ...then refuse to build at all if anything hidden is still staged, rather
# than shipping it and finding out from a reviewer.
if find "${BUILD}/${SLUG}" -name '.*' | grep -q .; then
	echo "Refusing to build: hidden files are staged in the plugin." >&2
	find "${BUILD}/${SLUG}" -name '.*' >&2
	exit 1
fi

( cd "${BUILD}" && zip -rq "${SLUG}.zip" "${SLUG}" )

echo "Built ${BUILD}/${SLUG}.zip"
echo
echo "Contents:"
( cd "${BUILD}/${SLUG}" && find . -type f | sort | sed 's|^\./|  |' )

# Leave only the zip behind. An unpacked copy sitting inside the plugin
# directory gets treated as part of the plugin by anything that scans it —
# Plugin Check included — which makes the results meaningless.
rm -rf "${BUILD:?}/${SLUG}"
