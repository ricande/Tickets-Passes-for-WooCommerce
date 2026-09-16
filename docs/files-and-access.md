# Files and access

Overview: [architecture.md](architecture.md). Folder keys: [data.md](data.md).

Generated QR images, PDFs and pass photos are not public upload URLs. `TPFW_File_Access` is loaded unconditionally so turning the scanner or API off cannot break a customer’s own ticket.

## Layout

Base directory: `wp-content/uploads/tpfw-{slug}/` where `{slug}` is a 10-character hex stored in `tpfw_upload_slug`. The slug is **not** the access control; it only stops a guessed nano id from mapping to a filesystem path. The option is self-healing if missing. Profile photos live under the same slug (`profile-images/`), not a custom web-reachable folder.

### Web server (direct static access)

`TPFW_File_Access` (`?tpfw_file=`) is the plugin route. The physical folder still needs a server deny:

| Server | What happens |
|---|---|
| Apache / LiteSpeed | Plugin writes deny-all `.htaccess` under `tpfw-*` when `AllowOverride` allows it. |
| nginx | **Ignores `.htaccess`.** Include [server-config/nginx-deny-tpfw-uploads.conf](server-config/nginx-deny-tpfw-uploads.conf) so `/wp-content/uploads/tpfw-*` is `deny all`. Do not deny all of `/wp-content/uploads/`. |
| IIS | **Ignores `.htaccess`.** Block static `/wp-content/uploads/tpfw-*` with URL Rewrite or equivalent. See [server-config/README.md](server-config/README.md). |

The random slug is extra obscurity on nginx/IIS until that rule is in place, not a substitute for it.

Subfolders are a fixed map (`TPFW_Functions::FILE_TYPE_FOLDERS`). The request never supplies a path — only a type key, an id and an extension.

## Request shape

`?tpfw_file={type}&id={name}&ext={ext}` plus optional `&exp={unix}&t={hmac}` and optional `&v={revision}`

- `type` must be a `FILE_TYPE_FOLDERS` key
- `id` must match `^[A-Za-z0-9_-]{1,64}$`
- `ext` must be in `SERVABLE_MIMES` (webp, png, jpg, jpeg, gif, pdf)
- `v` is a cache-buster taken from a content hash of the published file. It is **not** part of the HMAC and is ignored by `may_access_file()`. Changing `v` cannot grant access that `type` / `id` / `ext` / `exp` / `t` would have refused.

Anything else, any failed auth, and any path that `realpath()` would take outside the type folder, answers the **same bare 404**. Distinguishing “no such ticket” from “not yours” would be an existence oracle.

## Who may read what

| Type | Rule |
|---|---|
| `qr`, `guest` | HMAC only. These go in emails and onto guest phones; there is no session. |
| `preview` | `edit_products` or `manage_woocommerce`. Admin-only artefact. |
| `pdf`, `profile` | Logged-in owner (`user_id` on the row) **or** `manage_woocommerce` **or** a valid HMAC. HMAC is how an external scanner `<img>` works without a login cookie (the app authenticates to REST separately). |

`file_owner_id()` looks up `nano_id` in `tpfw_tickets`, `tpfw_timeslot_tickets`, `tpfw_pass`. A guest pass row already stores the parent holder’s `user_id`, so the holder reaches guest files without a special case.

Signatures: `verify_file_token()` / `sign_file_token()` using `tpfw_file_secret`. `hash_equals`. `exp = 0` never expires (email QR images). `get_file_url(..., $bSigned, $iTTL)` is the only builder.

## Caching

- `Cache-Control: private` + `Vary: Cookie` + `DONOTCACHEPAGE` so a page cache or CDN cannot hand one customer’s file to the next
- QR / guest / PDF / preview / profile: `no-cache` + ETag. QR and guest images are rewritten in place (same nano-id filename) when the product’s colours/logo or the renderer version change. PDFs are rebuilt from the **current** QR on every download. They must not be advertised as immutable.
- Generated links include `&v={revision}` (content hash of the published file, and for PDFs of the embedded QR too) so a browser that already stored an old immutable response fetches the new file. `v` is not signed.
- `X-Content-Type-Options: nosniff`, `X-Robots-Tag: noindex`
- PDF is `Content-Disposition: attachment`; images are `inline`

A new My Account view or PDF download shows the current QR. A dashboard **Resend** sends a mail with the current signed URL (including `v`). Already-sent emails keep the URL they were given; already-downloaded PDFs on the customer’s disk are not updated. The plugin does **not** automatically send a new mail after a rewrite.

The constructor calls `serve_file()` immediately. The plugin boots on `init` priority 20, so hooking `init` from this class would register too late and never run.

## Rewriting issued QR images

`TPFW_Qr_Rewrite` rewrites live codes in background batches (Action Scheduler when WooCommerce provides it, otherwise a one-shot WP-Cron event). A product save queues work only when the appearance fingerprint (colours, logo, label, `TPFW_Qr_Render::RENDER_VERSION`) differs from the last successful rewrite, or a previous job failed. Unchanged saves are a no-op. After an upgrade the first request schedules a repair sweep so existing shops get the new look without opening every product. Batches are 25 live rows (`deleted IS NULL`); cancelled/revoked rows are skipped and never reactivated. Job state lives in one option but is mutated under a MySQL named lock after a cache-busted reload, so an older generation cannot overwrite a newer cursor/status/target and concurrent product updates cannot drop each other. Each write uses a unique temp file on the destination’s directory; a rewrite replaces the live image only while that generation is still current. A failed write leaves the previous image in place. A database error is not treated as an empty page: the job stays resumable and an incomplete upgrade sweep is not recorded as handled. Action Scheduler must return a positive action ID; `0` is a scheduling failure and is not treated as queued. Lock or persist failures return an error, book a retry of the same generation when possible, and do not advance the cursor; they are not recorded as a finished page. A job that fails five times, or whose retry cannot be scheduled, stops as `failed` and surfaces an admin notice; saving the product (even with unchanged settings) or the next sweep resumes from the last successful id.
