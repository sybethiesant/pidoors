#!/bin/bash
#
# PiDoors Release Builder
# Builds the React SPA and creates a release tarball that includes
# pre-built dist/ files. This eliminates the Node.js requirement
# on servers during updates.
#
# Usage:
#   ./build-release.sh              # builds tarball for current VERSION
#   ./build-release.sh --publish    # builds, tags, pushes, and creates GitHub release
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
fail() { echo -e "  ${RED}✗${NC} $1"; }
warn() { echo -e "  ${YELLOW}!${NC} $1"; }
info() { echo -e "  ${BLUE}→${NC} $1"; }

VERSION=$(cat VERSION | tr -d '[:space:]')
TAG="v${VERSION}"
PUBLISH=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --publish) PUBLISH=true; shift ;;
        *) fail "Unknown argument: $1"; exit 1 ;;
    esac
done

echo
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  PiDoors Release Builder — $TAG${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo

# Pre-flight checks
if [ ! -f "pidoors-ui/package.json" ]; then
    fail "pidoors-ui/package.json not found. Run from the repo root."
    exit 1
fi

if ! command -v node > /dev/null 2>&1; then
    fail "Node.js is required to build the React SPA."
    exit 1
fi
ok "Node.js $(node --version)"

if ! command -v npm > /dev/null 2>&1; then
    fail "npm is required to build the React SPA."
    exit 1
fi
ok "npm $(npm --version)"

# Build React SPA
info "Building React SPA..."
(cd pidoors-ui && npm install --loglevel=error && npm run build) || {
    fail "React SPA build failed"
    exit 1
}

if [ ! -f "pidoors-ui/dist/index.html" ]; then
    fail "Build produced no dist/index.html"
    exit 1
fi
ok "React SPA built ($(find pidoors-ui/dist -type f | wc -l) files)"

# Create release tarball
OUTDIR="release"
STAGING="$OUTDIR/pidoors-${VERSION}"
TARBALL="$OUTDIR/${TAG}.tar.gz"

rm -rf "$OUTDIR"
mkdir -p "$STAGING"

# Copy project files (excluding dev artifacts)
info "Packaging release..."

# Core files
cp VERSION "$STAGING/"
cp database_migration.sql "$STAGING/"
cp install.sh "$STAGING/"
cp server-update.sh "$STAGING/"
cp build-release.sh "$STAGING/"
cp README.md "$STAGING/"

# PHP web app
cp -r pidoorserv "$STAGING/"
# Remove config.php if it exists (it's site-specific)
rm -f "$STAGING/pidoorserv/includes/config.php"

# Bundle pre-built SPA inside pidoorserv/ so that old v2.x updaters
# (which only copy pidoorserv/*) will also copy the SPA dist files.
# The new update.php auto-deploys this on first load after upgrade.
mkdir -p "$STAGING/pidoorserv/pidoors-ui-dist"
cp -r pidoors-ui/dist/* "$STAGING/pidoorserv/pidoors-ui-dist/"

# Door controller
cp -r pidoors "$STAGING/"

# Nginx config
cp -r nginx "$STAGING/"

# Pre-built React SPA dist
mkdir -p "$STAGING/pidoors-ui-dist"
cp -r pidoors-ui/dist/* "$STAGING/pidoors-ui-dist/"

# React SPA source (for install.sh builds from scratch)
mkdir -p "$STAGING/pidoors-ui"
cp pidoors-ui/package.json "$STAGING/pidoors-ui/"
cp pidoors-ui/package-lock.json "$STAGING/pidoors-ui/" 2>/dev/null || true
cp pidoors-ui/tsconfig.json "$STAGING/pidoors-ui/" 2>/dev/null || true
cp pidoors-ui/tsconfig.app.json "$STAGING/pidoors-ui/" 2>/dev/null || true
cp pidoors-ui/tsconfig.node.json "$STAGING/pidoors-ui/" 2>/dev/null || true
cp pidoors-ui/vite.config.ts "$STAGING/pidoors-ui/" 2>/dev/null || true
cp pidoors-ui/tailwind.config.js "$STAGING/pidoors-ui/" 2>/dev/null || \
cp pidoors-ui/tailwind.config.ts "$STAGING/pidoors-ui/" 2>/dev/null || true
cp pidoors-ui/postcss.config.js "$STAGING/pidoors-ui/" 2>/dev/null || true
cp pidoors-ui/index.html "$STAGING/pidoors-ui/" 2>/dev/null || true
[ -f pidoors-ui/.env ] && cp pidoors-ui/.env "$STAGING/pidoors-ui/"
[ -d pidoors-ui/src ] && cp -r pidoors-ui/src "$STAGING/pidoors-ui/"
[ -d pidoors-ui/public ] && cp -r pidoors-ui/public "$STAGING/pidoors-ui/"

ok "Staged $(find "$STAGING" -type f | wc -l) files"

# Create tarball
(cd "$OUTDIR" && tar czf "../$TARBALL" "pidoors-${VERSION}")
rm -rf "$STAGING"
TARBALL_SIZE=$(du -h "$TARBALL" | cut -f1)
ok "Created $TARBALL ($TARBALL_SIZE)"

# Generate a SHA-256 checksum asset alongside the tarball.
# Updaters (server-update.sh, pidoors-update.sh) download this and verify
# the tarball with `sha256sum -c` BEFORE extracting — supply-chain integrity.
# The sum file references the tarball by basename only so it verifies
# regardless of the directory it is downloaded into.
SHA256_FILE="${TARBALL}.sha256"
if command -v sha256sum > /dev/null 2>&1; then
    ( cd "$OUTDIR" && sha256sum "$(basename "$TARBALL")" > "$(basename "$SHA256_FILE")" )
elif command -v shasum > /dev/null 2>&1; then
    # macOS / systems without coreutils sha256sum
    ( cd "$OUTDIR" && shasum -a 256 "$(basename "$TARBALL")" > "$(basename "$SHA256_FILE")" )
else
    fail "Neither sha256sum nor shasum found — cannot generate release checksum."
    exit 1
fi
ok "Created checksum $SHA256_FILE"

if [ "$PUBLISH" = true ]; then
    echo
    info "Publishing release..."

    # Check for uncommitted changes
    if [ -n "$(git status --porcelain)" ]; then
        fail "Uncommitted changes found. Commit first."
        exit 1
    fi

    # Tag if not already tagged
    if git rev-parse "$TAG" > /dev/null 2>&1; then
        warn "Tag $TAG already exists"
    else
        git tag "$TAG"
        ok "Created tag $TAG"
    fi

    git push origin main --tags
    ok "Pushed to origin"

    # Create GitHub release with the tarball AND its checksum as assets.
    # Publishing the .sha256 is what lets updaters verify integrity before
    # extracting/deploying.
    gh release create "$TAG" "$TARBALL" "$SHA256_FILE" --title "$TAG" --notes "Release $VERSION" --latest
    ok "GitHub release created with assets: $TARBALL, $SHA256_FILE"
else
    echo
    info "Tarball ready: $TARBALL"
    info "Checksum ready: $SHA256_FILE"
    info "To publish: ./build-release.sh --publish"
    info "Or manually: gh release create $TAG $TARBALL $SHA256_FILE --title \"$TAG\" --notes \"Release $VERSION\""
fi

echo
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  Done!${NC}"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo
