# Local patches on bundled third-party manifests

The plugin ships Composer-installed runtime libraries under `inc/functions/lib/`.
Those libraries are still the upstream **runtime** releases named in `readme.txt`
and `inc/functions/lib/*/composer/installed.json` (`version`, `source`, `dist`).

This page records the **only** local divergences: `require-dev` pins that
Dependabot (and GitHub’s dependency graph) read from the bundled
`composer.json` files. Those pins are development tools for the **libraries’
own test suites**. They are not plugin production requirements, and this
repository does **not** run `composer install --dev` (or `--no-dev`) inside
those trees.

Replace each patch when the named official library release ships the same
constraint. Do not delete the manifests or dismiss Dependabot alerts just to
clear the security page.

## Plugin PHPUnit phar (not a vendored library)

| | |
|---|---|
| File | `tests/ensure-phpunit.sh` |
| Was | PHPUnit **11.5.42** (`894c651ee0fd38533649e92756022aa46a093d94c78652a4615aae23c846db60`) |
| Now | PHPUnit **11.5.56** (`915fa161f496dc04a45cd6032855879bca0bab644048cd0516982dffe678e9f1`) |
| Advisory | [GHSA-vvj3-c3rp-c85p](https://github.com/sebastianbergmann/phpunit/security/advisories/GHSA-vvj3-c3rp-c85p) / CVE-2026-24765 |
| Why | 11.5.42 is in the affected range (`<= 11.5.49`). First patched 11.5.x is **11.5.50**. 11.5.56 is the current official 11.5.x PHAR on [phar.phpunit.de](https://phar.phpunit.de/). |
| Check | Official SHA-256 from the phar.phpunit.de listing; `tests/ensure-phpunit.sh` still refuses a download that does not match. |
| Production | The phar is gitignored and excluded from the plugin ZIP (`tests/` is not packaged). |

## Dependabot #1 — `squizlabs/php_codesniffer` in PHP-CSS-Parser

| | |
|---|---|
| Manifest | `inc/functions/lib/dompdf/sabberworm/php-css-parser/composer.json` |
| Library kept | **sabberworm/php-css-parser v9.4.0** (runtime code, `source` / `dist` / `version` unchanged) |
| Was | `"squizlabs/php_codesniffer": "4.0.1"` |
| Now | `"squizlabs/php_codesniffer": "4.0.2"` |
| Advisory | [GHSA-hmqg-cxww-wqhq](https://github.com/PHPCSStandards/PHP_CodeSniffer/security/advisories/GHSA-hmqg-cxww-wqhq) / CVE-2026-67434 (4.0.0–4.0.1; first patched 4.x is 4.0.2) |
| Upstream | [MyIntervals/PHP-CSS-Parser#1607](https://github.com/MyIntervals/PHP-CSS-Parser/pull/1607) merged to `main` (security bump of PHPCS). **No official release after v9.4.0** includes that pin. Packagist’s v9.4.0 still declares 4.0.1. Current upstream `main` has since moved further (PHPCS 4.0.4 and other require-dev bumps) — that is **not** copied here. |
| Why this pin | Exact 4.0.2 is the first verified fixed 4.x and is a one-field change. A full library upgrade is not required to close the require-dev alert. |
| Mirrored | The same `require-dev` string in `inc/functions/lib/dompdf/composer/installed.json` (package version stays `v9.4.0`). |
| Replace when | An official `sabberworm/php-css-parser` 9.4.x+ (or later 9.x that Dompdf 3.1.6 can use) ships `"squizlabs/php_codesniffer": ">= 4.0.2"` (or a later exact 4.x pin). Then restore that release’s manifest wholesale. |

## Dependabot #2 — `phpunit/phpunit` in BaconQrCode 2.0.8

| | |
|---|---|
| Manifest | `inc/functions/lib/qrcodegen/bacon/bacon-qr-code/composer.json` |
| Library kept | **bacon/bacon-qr-code 2.0.8** (runtime code, `source` / `dist` / `version` unchanged) |
| Was | `"phpunit/phpunit": "^7 \| ^8 \| ^9"` |
| Now | `"phpunit/phpunit": "^8.5.52 \|\| ^9.6.33"` |
| Advisory | [GHSA-vvj3-c3rp-c85p](https://github.com/sebastianbergmann/phpunit/security/advisories/GHSA-vvj3-c3rp-c85p) / CVE-2026-24765. Affected: PHPUnit `<= 8.5.51`, `<= 9.6.32` (and other majors). Patched: **8.5.52**, **9.6.33**. |
| Compatible require-dev | `spatie/phpunit-snapshot-assertions` `^4.2.9` resolves on 4.2.x to PHPUnit `^8.3\|^9.0`. `squizlabs/php_codesniffer` `^3.4` and `phly/keep-a-changelog` `^2.1` do not constrain PHPUnit. Dropping PHPUnit 7 removes a major that current snapshot-assertions 4.2.x does not support. PHPUnit 10/11 are **not** added — they need a different snapshot-assertions major. |
| Not copied | [Bacon/BaconQrCode#243](https://github.com/Bacon/BaconQrCode/pull/243) (`^10.5.63 \|\| ^11.5.50`) is for current BaconQrCode (PHP `^8.1`, snapshot-assertions 5.x). That constraint does not belong on this 2.0.8 copy. |
| Plugin vs library | The **plugin** test runner is PHPUnit **11.5.56** via `tests/ensure-phpunit.sh`. That is independent of BaconQrCode’s `require-dev`. Shop runtime needs only BaconQrCode’s `require` (`php`, `ext-iconv`, `dasprid/enum`). |
| Mirrored | The same `require-dev` string in `inc/functions/lib/qrcodegen/composer/installed.json` (package version stays `2.0.8`). |
| Replace when | A later `bacon/bacon-qr-code` 2.0.x (or a 2.x that endroid/qr-code 4.8.2 can use) ships a constraint that already excludes the vulnerable PHPUnit ranges. Then restore that release’s manifest wholesale. Do not jump this vendored copy to BaconQrCode 3.x only to inherit the current-major PHPUnit pin. |

## What was not changed

- Library `version`, `source`, `dist`, and `time` in `installed.json` / `installed.php`
- Runtime PHP under `inc/functions/lib/**/src/`
- Plugin version (1.3.0) and existing release ZIP files
- No `composer update` / no install of these libraries’ `require-dev` into the plugin tree
