# Contributing

Thanks for looking at Tickets & Passes for WooCommerce.

## Before a large change

Open an issue first. Describe the behaviour you want to change and why.

## Compatibility

Preserve backward compatibility where possible: existing tickets, passes, QR files, and the random upload folder should keep working after an upgrade.

## Tests

From the plugin root:

```bash
bash tests/run.sh
```

Pull requests should explain the behaviour change and include tests when the change is testable.

## Do not commit

- Credentials, `.wp-credentials`, or environment secrets
- Database dumps
- Live customer codes, mail, or uploads
- Generated `tests/phpunit.phar` or `dist/` zips

## Security

See [SECURITY.md](SECURITY.md). Do not file public issues for vulnerabilities.

Local `require-dev` patches on bundled Composer manifests are listed in [docs/vendor-patches.md](docs/vendor-patches.md). Do not install those libraries’ development dependencies into the plugin tree.

## Authorship

Keep the original author attribution: **Magnus V.** (`macvej`) in the plugin header and `readme.txt` `Contributors:` field. Do not rewrite provenance to the current maintainer.
