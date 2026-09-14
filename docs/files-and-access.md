# Files and access

Overview: [architecture.md](architecture.md). Folder keys: [data.md](data.md).

Generated QR images, PDFs and pass photos are not public upload URLs. `TPFW_File_Access` is loaded unconditionally so turning the scanner or API off cannot break a customer’s own ticket.

## Layout

Base directory: `wp-content/uploads/tpfw-{slug}/` where `{slug}` is a 10-character hex stored in `tpfw_upload_slug`. The slug is **not** the access control (nginx ignores `.htaccess`); it only stops a guessed nano id from mapping to a filesystem path. The option is self-healing if missing. Profile photos live under the same slug (`profile-images/`), not a custom web-reachable folder.

Subfolders are a fixed map (`TPFW_Functions::FILE_TYPE_FOLDERS`). The request never supplies a path — only a type key, an id and an extension.

## Request shape

`?tpfw_file={type}&id={name}&ext={ext}` plus optional `&exp={unix}&t={hmac}`

- `type` must be a `FILE_TYPE_FOLDERS` key
- `id` must match `^[A-Za-z0-9_-]{1,64}$`
- `ext` must be in `SERVABLE_MIMES` (webp, png, jpg, jpeg, gif, pdf)

Anything else, any failed auth, and any path that `realpath()` would take outside the type folder, answers the **same bare 404**. Distinguishing “no such ticket” from “not yours” would be an existence oracle.

## Who may read what

| Type | Rule |
|---|---|
| `qr`, `guest` | HMAC only. These go in emails and onto guest phones; there is no session. |
| `preview` | `edit_products` or `manage_woocommerce`. Admin-only artefact. |
| `pdf`, `profile` | Logged-in owner (`user_id` on the row) **or** `manage_woocommerce` **or** a valid HMAC. HMAC is how an external scanner shows a pass photo over Basic Auth. |

`file_owner_id()` looks up `nano_id` in `tpfw_tickets`, `tpfw_timeslot_tickets`, `tpfw_pass`. A guest pass row already stores the parent holder’s `user_id`, so the holder reaches guest files without a special case.

Signatures: `verify_file_token()` / `sign_file_token()` using `tpfw_file_secret`. `hash_equals`. `exp = 0` never expires (email QR images). `get_file_url(..., $bSigned, $iTTL)` is the only builder.

## Caching

- `Cache-Control: private` + `Vary: Cookie` + `DONOTCACHEPAGE` so a page cache or CDN cannot hand one customer’s file to the next
- QR / guest / PDF: `max-age=31536000, immutable` (named after nano id, written once)
- preview / profile: `no-cache` + ETag (same URL is rewritten when colours or the photo change)
- `X-Content-Type-Options: nosniff`, `X-Robots-Tag: noindex`
- PDF is `Content-Disposition: attachment`; images are `inline`

The constructor calls `serve_file()` immediately. The plugin boots on `init` priority 20, so hooking `init` from this class would register too late and never run.
