# Server config for private files

Generated QR images, PDFs and pass photos live under:

```
wp-content/uploads/tpfw-{slug}/
```

Every customer link the plugin emits goes through `?tpfw_file=…` (`TPFW_File_Access`). That PHP route is unchanged by the rules below.

Direct HTTP to the physical files must still be blocked by the web server. `.htaccess` is not enough on every host.

## Apache / LiteSpeed

The plugin writes a deny-all `.htaccess` (and `index.php`) into the `tpfw-*` folder when it creates or repairs it. That works when `AllowOverride` lets the server honour `.htaccess`.

## nginx

nginx **ignores** `.htaccess`. Add the location in [nginx-deny-tpfw-uploads.conf](nginx-deny-tpfw-uploads.conf) to the WordPress `server` block:

```nginx
location ^~ /wp-content/uploads/tpfw- {
	deny all;
}
```

That matches `/wp-content/uploads/tpfw-*` only. Do not apply `deny all` to all of `/wp-content/uploads/`.

Reload nginx after editing. The plugin file route stays PHP and is not this location.

## IIS

IIS **ignores** `.htaccess`. The site must block static requests whose path starts with `/wp-content/uploads/tpfw-` (URL Rewrite, request filtering, or an equivalent). Example rewrite rule:

```xml
<rule name="TPFW deny static tpfw uploads" stopProcessing="true">
	<match url="^wp-content/uploads/tpfw-" />
	<action type="CustomResponse" statusCode="403" statusReason="Forbidden" statusDescription="Forbidden" />
</rule>
```

Leave `index.php?tpfw_file=…` to the PHP handler. Do not deny the whole media library.
