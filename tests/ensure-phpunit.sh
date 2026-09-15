#!/usr/bin/env bash
# Downloads PHPUnit 11.5.56 into tests/phpunit.phar when missing or checksum-stale.
# 11.5.56 is a current 11.5.x release after the GHSA-vvj3-c3rp-c85p / CVE-2026-24765
# fix (first patched 11.5.x is 11.5.50). The phar is gitignored.
# Official URL + pinned SHA-256 from https://phar.phpunit.de/; no Composer required.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHAR="$ROOT/tests/phpunit.phar"
VERSION="11.5.56"
URL="https://phar.phpunit.de/phpunit-${VERSION}.phar"
# sha256 of the official phpunit-11.5.56.phar (phar.phpunit.de listing)
SHA256="915fa161f496dc04a45cd6032855879bca0bab644048cd0516982dffe678e9f1"

have_hash() {
	if command -v sha256sum >/dev/null 2>&1; then
		echo "$SHA256  $1" | sha256sum -c --status
	else
		php -r 'exit(hash_file("sha256", $argv[1]) === $argv[2] ? 0 : 1);' "$1" "$SHA256"
	fi
}

if [[ -f "$PHAR" ]] && have_hash "$PHAR"; then
	exit 0
fi

if [[ -f "$PHAR" ]]; then
	echo "tests/phpunit.phar checksum mismatch; re-downloading PHPUnit ${VERSION}" >&2
	rm -f "$PHAR"
fi

TMP="${PHAR}.download.$$"
cleanup() { rm -f "$TMP"; }
trap cleanup EXIT

if command -v curl >/dev/null 2>&1; then
	curl -fsSL --retry 3 -o "$TMP" "$URL"
elif command -v wget >/dev/null 2>&1; then
	wget -q -O "$TMP" "$URL"
else
	echo "Need curl or wget to download PHPUnit ${VERSION}" >&2
	exit 1
fi

if ! have_hash "$TMP"; then
	echo "Downloaded PHPUnit phar failed SHA-256 check" >&2
	exit 1
fi

mv "$TMP" "$PHAR"
chmod +x "$PHAR"
trap - EXIT
echo "PHPUnit ${VERSION} ready at tests/phpunit.phar"
