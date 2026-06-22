#!/usr/bin/env bash
set -euo pipefail

VERSION="${1:-}"
if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Usage: bin/release.sh X.Y.Z" >&2
	exit 1
fi

cd "$(dirname "$0")/.."

perl -i -pe "s/^( \* Version:\s+).*/\${1}${VERSION}/" storage-inspector.php
perl -i -pe "s/(define\( 'STORAGE_INSPECTOR_VERSION', ')[^']*(' \))/\${1}${VERSION}\${2}/" storage-inspector.php

php -l storage-inspector.php >/dev/null
find src -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
php -l uninstall.php >/dev/null

git add -A
git commit -m "Release ${VERSION}"
git tag "v${VERSION}"
git push origin "$(git rev-parse --abbrev-ref HEAD)" "v${VERSION}"

echo "Tagged v${VERSION}. GitHub Actions will build storage-inspector.zip and publish the release."
