#!/usr/bin/env bash
# Publishes the version written in the manifest: tag v<version> and a release with the archive and SHA256SUMS.txt,
# then updates.xml in main — so Joomla never sees a version whose archive is not there yet.
# Needs git and gh signed in (gh auth login; gh auth setup-git), the work committed and pushed to main.
#   tools/release.sh --dry-run   builds and shows what would happen, publishes nothing.
set -euo pipefail
cd "$(dirname "$0")/.."
DRY=0; [ "${1:-}" = "--dry-run" ] && DRY=1
NAME="Webmarka Clean"
EL=plg_system_wmarka_clean
REPO=bsakhanov/plg_system_wmarka_clean
VER=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' wmarka_clean.xml | head -1)
TAG="v$VER"
run() { if [ "$DRY" = 1 ]; then echo "  [dry-run] $*"; else "$@"; fi; }
fail() { echo "release: $*" >&2; exit 1; }

[ -z "$(git status --porcelain)" ] || fail "commit your changes first"
grep -q "^## $VER " CHANGELOG.md || fail "CHANGELOG.md has no section for $VER"
grep -q "<version>$VER</version>" changelog.xml || fail "changelog.xml has no entry for $VER"
if [ "$DRY" = 0 ]; then
    git fetch -q origin
    [ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || fail "HEAD is not origin/main: push main first"
    ! git ls-remote --exit-code --tags origin "refs/tags/$TAG" >/dev/null || fail "tag $TAG already exists"
fi

./build.sh >/dev/null
NOTES=$(mktemp)
awk -v v="## $VER " 'index($0, v) == 1 {p = 1; next} /^## / && p {exit} p' CHANGELOG.md > "$NOTES"

run git tag -a "$TAG" -m "$NAME $VER"
run git push origin "refs/tags/$TAG"
run gh release create "$TAG" --repo "$REPO" --verify-tag --latest --title "$NAME $VER" --notes-file "$NOTES" \
    "dist/${EL}_v${VER}.zip" dist/SHA256SUMS.txt

if [ "$DRY" = 1 ]; then
    echo "  [dry-run] updates.xml would become version $VER (sha256 $(grep -o '<sha256>[^<]*' dist/updates.xml | cut -c9-))"
else
    cp dist/updates.xml updates.xml
    git add updates.xml
    git commit -q -m "updates.xml: $VER"
    git push -q origin HEAD:main
    echo "Published: https://github.com/$REPO/releases/tag/$TAG"
fi
