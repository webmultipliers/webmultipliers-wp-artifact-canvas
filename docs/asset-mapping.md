# Asset Mapping

Artifacts often reference relative paths — `<img src="images/logo.png">`, `<link href="theme.css">` — that don't exist on the WordPress host. The **Asset Mapping** metabox below the editor lists every unresolved relative path it detects in the saved HTML and lets you map each one to a file in the Media Library, using the standard media picker.

## What gets detected

Relative `src`, `href`, `poster`, and `srcset` attribute values plus CSS `url(…)` references. Absolute URLs, protocol-relative URLs, root-relative paths (`/…`), `data:` URIs, anchors, and paths containing `{{merge tags}}` are skipped.

## How replacement works

Mapped URLs are substituted at render time via the `wmac_rendered_html` filter at priority 10 — first in the pipeline, so later steps (code injection, merge tags) see the resolved URLs. The HTML stored in the database is never altered; removing a mapping restores the original path on the next request.

The map is stored as JSON in the `_wmac_asset_map` post meta. Mapped URLs are sanitized with `esc_url_raw` on save and escaped with `esc_url` at render time.
