# Known product gap: dashboard cancel then sequential issue

This is **not** a concurrency miss. After Bite 2A.2c every regular-ticket
admission and lifecycle write shares `tpfw_ticket_issue_{order_line_id}`.
A later sequential `TPFW_Ticket_Line::issue()` still undeletes a
dashboard-cancelled nano so the live count matches purchased quantity.

`tests/php` does not contain this repro. `phpunit.xml` only discovers
`tests/php`, so `bash tests/run.sh` stays green without `--exclude-group`.

## Expected failure

1. Dashboard cancel completes.
2. Every named lock is released.
3. A later sequential `issue()` runs.
4. The same nano-id is live again (`deleted` becomes NULL).

The assertion that must fail:

`dashboard per-nano cancel must outlive a later issue/reissue`

## Run it

From the plugin root, with the same credentials as the unit suite:

```
php tests/phpunit.phar -c phpunit.xml tests/repro/ManualCancelReissueReproTest.php
```

## Next bite

Introduce persistent manual-cancel semantics so a dashboard nano cancel
is not undone by a later issue/reissue. Do not treat this directory as
the place to hide other red tests.
