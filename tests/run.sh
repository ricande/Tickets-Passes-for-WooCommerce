#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
php tests/phpunit.phar -c phpunit.xml
node --test tests/js/*.test.js
