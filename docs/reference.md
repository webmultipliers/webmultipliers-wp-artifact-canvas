# Reference

## Global settings

**Artifacts → Settings** (`wmac_settings` option):

| Setting | Default | Purpose |
| --- | --- | --- |
| Default noindex | on | Send `X-Robots-Tag: noindex` for all artifacts (per-artifact override available) |
| Inject SEO meta by default | off | OG / Twitter Card injection default (per-artifact override available) |
| Default Content Security Policy | empty | CSP header applied to all artifacts; per-artifact CSP overrides |
| Sandbox host | empty | Isolated origin for published artifacts — see [Sandbox](sandbox.md) |
| Admin toolbar | `custom` | Toolbar shown to logged-in editors on served artifacts: `custom`, `core`, or `none` |

## Filters

| Filter | Default | Description |
| --- | --- | --- |
| `wmac_rendered_html` | — | The full HTML string and `WP_Post` before output. Core extension point for all render-time transformations ([pipeline order](rendering.md#steps)). |
| `wmac_noindex` | `true` | Controls the `X-Robots-Tag: noindex` header. |
| `wmac_csp` | `''` | Sets a `Content-Security-Policy` header. Empty = no header. |
| `wmac_seo_enabled` | `false` | Enables OG / Twitter Card meta injection. |
| `wmac_resolve_tag_{name}` | `''` | Resolves a dynamic merge tag. Receives `($default, $post_id)`. |
| `wmac_kses_allowed_html` | artifact profile | KSES element/attribute allowlist applied to artifact HTML from authors without `unfiltered_html`. |
| `wmac_artifact_admin_toolbar_mode` | `'custom'` | `'none'`, `'custom'`, or `'core'`. |
| `wmac_artifact_admin_toolbar_links` | — | Modify the link array for the custom admin toolbar. |
| `wmac_artifact_admin_toolbar_html` | — | Modify or replace the final toolbar HTML before injection. Receives `($html, $post, $mode)`. |
| `wmac_oembed_iframe_sandbox` | `allow-scripts allow-popups allow-forms allow-same-origin` | Sandbox attribute on oEmbed iframes. |
| `wmac_max_file_size` | `10485760` | Max bytes for server-side HTML file attachment (10 MB). |
| `wmac_max_kses_file_size` | `2097152` | Max bytes for HTML uploads that must pass sanitization (2 MB). |
| `wmac_max_prompt_length` | — | Max stored length of the generation-prompt meta. |
| `wmac_view_webhook_payload` | — | Modify the outbound JSON payload posted on public artifact views. |
| `wmac_view_webhook_ip_headers` | — | Which request headers are trusted for the visitor IP in webhook payloads. |
| `wmac_view_webhook_sslverify` | `true` | TLS verification for outbound view webhooks. |
| `wmac_git_webhook_token` | `''` | Bearer token for private Git archive downloads. |
| `wmac_git_webhook_allow_unsigned` | `false` | Allow unsigned Git webhook requests (development only). |
| `wmac_git_archive_hosts` | GitHub/GitLab hosts | Allowlist of hosts the server may download webhook archives from. |
| `wmac_max_zip_size` | `52428800` | Max uncompressed ZIP size for Git ingestion (50 MB). |
| `wmac_max_zip_depth` | `5` | Max directory nesting depth in Git ingestion ZIPs. |
| `wmac_sandbox_host` | `''` | Set/override the sandbox host in code (must run by `plugins_loaded`). |
| `wmac_usurpation_csp` | strict policy | CSP sent on usurped Page responses. Return `''` to suppress. |
| `wmac_pdfjs_url` | CDN URL | Override the PDF.js module URL. |
| `wmac_pdfjs_worker_url` | CDN URL | Override the PDF.js worker URL. |
| `wmac_max_pdf_size` | `52428800` | Max PDF upload bytes (50 MB). |

## Actions

| Action | Fires |
| --- | --- |
| `wmac_artifact_served` | After all gates pass, immediately before an artifact response is sent. Receives the `WP_Post`. View counting and webhooks attach here. |
| `wmac_artifact_content_updated` | After a file-level content change (attach/replace/remove) that never touches the post record. Hook CDN/edge purges here. |
| `wmac_password_view_head` / `wmac_password_view_footer` | Inside the standalone password screen's head/footer, for custom branding. |

## Meta keys

| Meta key | Holds |
| --- | --- |
| `_wmac_alias` | Custom front-end path ([routing](routing.md#custom-url-aliases)) |
| `_wmac_description` | Artifact description (used for SEO meta) |
| `_wmac_prompt` | Generation prompt (private) |
| `_wmac_noindex` / `_wmac_seo_enabled` / `_wmac_csp` | Per-artifact overrides of the global defaults |
| `_wmac_external_styles` / `_wmac_external_scripts` | URL lists, one per line ([code injection](code-injection.md)) |
| `_wmac_head_html` / `_wmac_body_html` | Raw injected markup ([code injection](code-injection.md)) |
| `_wmac_tag_map` | Merge tag configuration JSON ([merge tags](merge-tags.md)) |
| `_wmac_asset_map` | Relative-path → URL map JSON ([asset mapping](asset-mapping.md)) |
| `_wmac_expires_at` / `_wmac_max_views` / `_wmac_view_count` | [Link governance](governance.md#link-governance) |
| `_wmac_tracking_snippet` / `_wmac_view_webhook_url` | [Tracking & webhooks](governance.md#tracking--view-webhooks) |
| `_wmac_owner_id` | Owner user ID |
| `_wmac_file_token` | Persisted stored-file name token |
| `_wmac_format` | `pdf` when in [PDF mode](pdf-mode.md) |
| `_wmac_usurp_artifact_id` | On Pages: the artifact served at the Page URL |

## Technical configuration

| Setting | Value |
| --- | --- |
| Package / text domain | `webmultipliers-wp-artifact-canvas` |
| PHP namespace | `WebMultipliers\ArtifactCanvas` |
| Constant prefix | `WMAC_` |
| Post type key | `wm_artifact` |
| Public URL slug | `/artifact/` |
| Block name | `wmac/artifact` |
| Taxonomy key | `wm_client` |
| Capability type | `wm_artifact` / `wm_artifacts` (`map_meta_cap`) |
| Settings option key | `wmac_settings` |
| Git webhook secret option | `wmac_git_webhook_secret` |
