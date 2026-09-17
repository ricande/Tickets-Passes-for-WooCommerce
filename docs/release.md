# Tests and production ZIP

This is the source/review vs production split. Behaviour docs stay in the other `docs/` pages.

## Source / review — run tests

From a clean checkout of this plugin directory (PHP 8.0+, `curl` or `wget`, Node for the JS tests):

```bash
bash tests/run.sh
```

`tests/run.sh` calls `tests/ensure-phpunit.sh`, which downloads the official **PHPUnit 11.5.56** phar into `tests/phpunit.phar` when it is missing or the SHA-256 does not match. The phar is gitignored. No Composer install is required. Local `require-dev` patches on two bundled Composer manifests are listed in [vendor-patches.md](vendor-patches.md).

The suite is PHPUnit under `tests/php/` (including package-contract and documentation-contract checks), concurrency workers under `tests/bin/`, and `node --test tests/js/*.test.js`.

Database-backed tests still use the existing credentials model: `TPFW_TEST_CREDENTIALS` or a `.wp-credentials` file next to (or one level above) the plugin root. That file is never committed.

Tests must not read `../deploy/`, `/var/www/`, or other host paths except that credentials lookup.

A **source / review** checkout is this repository: tests, workers, `scripts/`, and developer `docs/` are present so `bash tests/run.sh` works after a clean clone.

The plugin header **1.3.3** is published as GitHub pre-release tag `1.3.3-RC`. It is a release candidate, not a stable WordPress.org build.

## Production ZIP

```bash
bash scripts/build-plugin-zip.sh
```

Writes `dist/tickets-passes-for-woocommerce-1.3.3.zip` (version from the plugin header). Optional: `TPFW_ZIP_OUT=/tmp/plugin.zip`.

Top folder inside the zip: `tickets-passes-for-woocommerce/`.

**Included:** plugin PHP/JS/CSS, `languages/`, bundled runtime libs (`inc/functions/lib/`, `lib/`), `readme.txt`, `changelog.txt`, `uninstall.php`, `docs/server-config/` (nginx/IIS deny contract).

**Excluded:** `.git/`, `tests/`, `scripts/`, PHPUnit, `composer.json`, `package.json`, `README.md` (developer index), `phpunit.xml`, `vendor/`, `node_modules/`, `.wp-credentials`, other `docs/` pages, local logs, `dist/`.
