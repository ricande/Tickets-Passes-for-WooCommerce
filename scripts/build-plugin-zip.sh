#!/usr/bin/env bash
# Builds a production WordPress plugin ZIP (no tests, no credentials, no .git).
# Usage: bash scripts/build-plugin-zip.sh
# Optional: TPFW_ZIP_OUT=/tmp/plugin.zip
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="tickets-passes-for-woocommerce"
VERSION="$(php -r '
$s = file_get_contents($argv[1]);
if (!preg_match("/^\s*\*\s*Version:\s*(\S+)/m", $s, $m)) { fwrite(STDERR, "no Version header\n"); exit(1); }
echo $m[1];
' "$ROOT/tickets-passes-for-woocommerce.php")"

OUT="${TPFW_ZIP_OUT:-$ROOT/dist/${SLUG}-${VERSION}.zip}"
STAGE="$(mktemp -d)"
cleanup() { rm -rf "$STAGE"; }
trap cleanup EXIT

DEST="$STAGE/$SLUG"
mkdir -p "$DEST"

# Runtime tree. Bundled libs under inc/functions/lib/ and lib/ stay in.
rsync -a \
	--exclude '.git/' \
	--exclude '.github/' \
	--exclude '.cursor/' \
	--exclude 'tests/' \
	--exclude 'scripts/' \
	--exclude 'vendor/' \
	--exclude 'node_modules/' \
	--exclude 'dist/' \
	--exclude '.wp-credentials' \
	--exclude '.gitignore' \
	--exclude 'composer.json' \
	--exclude 'package.json' \
	--exclude 'README.md' \
	--exclude 'phpunit.xml' \
	--exclude '*.log' \
	--exclude '.phpunit.cache/' \
	--exclude 'docs/' \
	"$ROOT/" "$DEST/"

mkdir -p "$DEST/docs/server-config"
rsync -a "$ROOT/docs/server-config/" "$DEST/docs/server-config/"

mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"
(cd "$STAGE" && zip -qr "$OUT" "$SLUG")

echo "$OUT"
echo "version=${VERSION} slug=${SLUG}"
