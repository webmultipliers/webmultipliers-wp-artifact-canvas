# WP Artifact Canvas

> Host fully-rendered HTML artifacts on WordPress — paste a complete HTML document into a post and serve it 1:1 on the front end, with zero theme interference and full respect for WordPress access rules.

**Package slug:** `webmultipliers-wp-artifact-canvas`
**Status:** Active development
**Author:** Web Multipliers, LLC

---

## What it is

WP Artifact Canvas turns a WordPress install into a host for self-contained HTML artifacts — the kind of complete, single-file HTML documents produced by AI tools, design prototyping, or hand-built component demos. You paste a whole `<!doctype html>` document into a single post, publish it, and the public URL renders that document **exactly as written**: no theme header or footer, no injected wrappers, no global stylesheet, no script collisions.

It exists because WordPress is excellent at access control, URLs, revisions, and content management, but actively hostile to serving a raw HTML document. The active theme wraps everything, Gutenberg rewrites pasted markup into blocks, and `wp_head()` injects a stylesheet and a dozen meta tags. This plugin gets all of that out of the way for one specific post type, while keeping everything WordPress is good at.

## What it is *not*

- Not a page builder. There is no styling UI; the artifact's own CSS/JS is the entire presentation layer.
- Not a general untrusted-content host. Authoring is a trusted, capability-gated action (see [Security model](#security-model)).
- Not a theme or a block library. It ships exactly one block, used in exactly one place.

## Key features

- **True passthrough rendering.** A published canvas serves only the artifact's bytes. WordPress contributes no markup of its own — no `header.php`, no `footer.php`, no global styles, no `wp_head` cruft.
- **Optional admin management toolbar.** For logged-in users who can edit the artifact, an in-canvas toolbar can be injected to quickly jump to Edit, list, and new-artifact screens. Default mode is a lightweight custom bar, with an optional core admin-bar mode.
- **A purpose-built editing block.** A single locked block per canvas with a syntax-highlighted CodeMirror editor (no rich text, no paste-to-blocks transforms) so a wholesale paste stays raw. The raw HTML is stored as the block's source of truth, not as fragile inner markup.
- **Sandboxed editor preview.** The artifact previews inside an isolated `<iframe>` in the editor, so its CSS and JS never leak into wp-admin.
- **File upload and server-side attachment.** Load an artifact from a local `.html` file directly in the editor. Optionally attach the file to the server so large artifacts don't consume block-attribute storage.
- **Media Library asset mapping.** Artifacts that reference relative paths (`src="assets/logo.png"`) can have those paths mapped to Media Library URLs via a dedicated sidebar panel. Replacement happens at render time — the stored HTML is never modified.
- **Merge tag templating.** Embed `{{tag_name}}` placeholders in an artifact's HTML and configure each tag's value in the editor sidebar. Supports static string replacement or server-side dynamic resolution via a PHP filter hook — no code inside the HTML required.
- **Access rules honored.** Draft, private, password-protected, and scheduled canvases behave the way any WordPress post would. Password-protected canvases get a clean, theme-free entry screen.
- **Capability-aware sanitization.** Authors without the capability to post raw markup have their content run through `wp_kses_post` on save, the same as core.
- **oEmbed provider.** Paste any published artifact URL into another Gutenberg editor and it embeds as a live iframe.
- **Per-artifact SEO meta.** Opt-in injection of Open Graph and Twitter Card meta tags sourced from the post's native title, excerpt, and featured image.
- **Custom URL aliases.** Serve an artifact at any front-end path (e.g. `/pricing`) without changing the permalink structure.
- **Clean revision diffs.** The revisions screen shows a diff of the extracted HTML, not the raw block JSON.
- **Lifecycle management.** Built-in private artifact statuses (`In Review`, `Approved`, `Archived`) complement core draft/publish states.
- **Client and ownership metadata.** A hierarchical **Clients** taxonomy and per-artifact owner assignment support agency workflows and list-table visibility.
- **Link governance controls.** Optional expiry date and max-view limits can automatically retire a preview link and return a dedicated expired page.
- **Tracking and alert hooks.** Per-artifact analytics snippets can be injected into `<head>`, and optional outbound webhooks fire on public views.
- **Page URL usurpation.** A standard WordPress Page can be configured to serve a published artifact's bytes at the Page URL.
- **REST and automation surface.** Endpoints support artifact creation, HTML file attach/remove, PDF file attach/remove, and Git webhook-driven deployments.
- **PDF format mode.** Artifacts can be switched to PDF delivery with a branded PDF.js viewer shell and protected byte-stream endpoint.

## Requirements

- WordPress 6.4+
- PHP 8.1+

## Installation

1. Download a packaged release or use the repository as-is.
2. Upload the `webmultipliers-wp-artifact-canvas` folder to `/wp-content/plugins/`.
3. Activate **WP Artifact Canvas** from the Plugins screen. Activation registers the post type and flushes rewrite rules so canvas URLs resolve immediately.
4. A new **Artifacts** menu item appears in the admin sidebar.

## Usage

1. Create a new Artifact.
2. The editor opens with the Artifact Canvas block already in place (it is the only block allowed).
3. Paste your complete HTML document — including `<style>` and `<script>` — into the code field, or use **Upload HTML** / **Attach File** from the toolbar.
4. Use the in-editor preview to confirm it renders correctly in isolation.
5. Publish. Visit the public URL and you'll see your document rendered exactly as written, with no WordPress chrome.

Additional post-side panels let you configure:

- Artifact settings (alias, noindex override, SEO override, CSP, generation prompt)
- Asset mappings and merge tags
- Lifecycle status and owner assignment
- Link governance (expiry date, max views, view count)
- Tracking and alert webhooks

## Asset mapping

If the artifact references relative paths — `<img src="images/logo.png">`, `<link href="theme.css">` — the **Asset Mapping** panel in the Post sidebar will list every unresolved path it detects and let you map each one to a file in the WordPress Media Library.

Mapped URLs are substituted at render time via the `wmac_rendered_html` filter. The HTML stored in the database is never altered.

## Merge tags

Embed `{{placeholders}}` anywhere in an artifact's HTML. The **Merge Tags** panel in the Post sidebar lists every detected tag and offers two resolution modes:

| Mode | Behaviour |
| --- | --- |
| **Static string** | Admin enters a value in the sidebar. Saved to post meta and substituted at render time with `esc_html()`. |
| **Dynamic hook** | The tag is resolved at request time by a PHP filter. The admin marks the tag as dynamic; a developer hooks the filter. |

### Developer API — dynamic merge tags

```php
// Resolves {{current_user_email}} at render time.
add_filter( 'wmac_resolve_tag_current_user_email', function ( string $default, int $post_id ): string {
    return is_user_logged_in() ? esc_html( wp_get_current_user()->user_email ) : '';
}, 10, 2 );
```

The filter name is `wmac_resolve_tag_{tag_name}`. The callback receives the default empty string and the `$post_id`. The return value is echoed directly into the HTML — **the developer is responsible for escaping** for the target HTML context (`esc_html`, `esc_attr`, `esc_js`, etc.).

Tag names are restricted to `[a-zA-Z0-9_]+`.

## REST API

All custom routes are under `/wp-json/wmac/v1`.

| Endpoint | Method(s) | Purpose |
| --- | --- | --- |
| `/artifacts` | `POST` | Create a new draft artifact from HTML (`html`, optional `title`). |
| `/artifacts/{id}/file` | `GET`, `POST`, `DELETE` | Read/upload/remove server-stored HTML file attachment. |
| `/artifacts/{id}/pdf` | `GET` | Stream PDF bytes for artifacts in PDF mode (password gate respected). |
| `/artifacts/{id}/pdf-file` | `POST`, `DELETE` | Upload/remove PDF attachment and toggle PDF mode. |
| `/git-webhook/{id}` | `POST` | Validate webhook signature, fetch archive, extract `index.html`, deploy to artifact. |

Notes:

- Artifact creation and file management endpoints require an authenticated editor for that artifact type.
- Git webhook endpoint is authenticated by signature/token validation (inside the handler), not by cookie auth.

## Global settings

An admin settings screen is available at:

- `Artifacts -> Settings`

Global defaults currently include:

- default noindex
- default SEO meta injection
- default CSP header value
- admin toolbar mode (`none`, `custom`, `core`)

## How it works

1. **Post type.** Registers a custom post type (`wm_artifact`, labeled "Artifacts") with a locked editor template containing one Artifact Canvas block.
2. **Storage.** The pasted document is stored as an attribute on the block (or as a protected file on disk for the server-attachment mode), making it the canonical source of truth.
3. **Interception.** On `template_redirect`, a single-artifact request short-circuits the normal template hierarchy.
4. **Rendering pipeline.** The plugin reads the stored HTML, then passes it through the `wmac_rendered_html` filter. Built-in subscribers include AssetMapper (priority 10), SeoMeta (priority 20), MergeTags (priority 20), and ClientTracking (priority 30). Third-party code can hook at any priority.
5. **Output.** Correct content-type and security headers are sent, the HTML is echoed, and the process exits — a 1:1 render. Nothing from WordPress is appended.
6. **Protected views.** If the post requires a password, a minimal standalone HTML shell with the password form is served instead, still fully isolated from the theme.
7. **Author toolbar injection (optional).** On passthrough responses, the plugin can inject either a custom management bar or the core admin bar for authorized users without involving the theme template stack.
8. **Governance and observability.** Public serves fire `wmac_artifact_served`, enabling built-in view counting and optional outbound webhook notifications.
9. **Alternate routing options.** Artifacts may be served by their own permalink, by custom alias, or by usurping a standard Page URL.

## Filter reference

| Filter | Default | Description |
| --- | --- | --- |
| `wmac_rendered_html` | — | Passes the full HTML string and `WP_Post` before output. Core extension point for all render-time transformations. |
| `wmac_noindex` | `true` | Controls the `X-Robots-Tag: noindex` header globally. Override per-artifact via the sidebar. |
| `wmac_csp` | `''` | Sets a `Content-Security-Policy` header. Empty = no header. Override per-artifact via the sidebar. |
| `wmac_seo_enabled` | `false` | Enables OG / Twitter Card meta injection. Override per-artifact via the sidebar. |
| `wmac_resolve_tag_{name}` | `''` | Resolves a dynamic merge tag. Receives `($default, $post_id)`. |
| `wmac_artifact_admin_toolbar_mode` | `'custom'` | `'none'`, `'custom'`, or `'core'`. |
| `wmac_artifact_admin_toolbar_links` | — | Modify links in the custom admin toolbar. |
| `wmac_max_file_size` | `10485760` | Max bytes for server-side file attachment (default 10 MB). |
| `wmac_view_webhook_payload` | — | Modify outbound JSON payload posted on public artifact views. |
| `wmac_view_webhook_sslverify` | `true` | Control TLS verification for outbound view webhooks. |
| `wmac_git_webhook_token` | `''` | Provide bearer token for private Git archive downloads. |
| `wmac_git_webhook_allow_unsigned` | `false` | Allow unsigned Git webhook requests (development only). |
| `wmac_max_zip_size` | `52428800` | Max uncompressed ZIP size for Git ingestion package validation (50 MB). |
| `wmac_max_zip_depth` | `5` | Max directory nesting depth allowed in Git ingestion ZIPs. |
| `wmac_pdfjs_url` | CDN URL | Override PDF.js module URL used by PDF viewer mode. |
| `wmac_pdfjs_worker_url` | CDN URL | Override PDF.js worker URL used by PDF viewer mode. |
| `wmac_max_pdf_size` | `52428800` | Max PDF upload bytes (default 50 MB). |

## Security model

This is a **trusted-author tool**. Hosting arbitrary HTML, CSS, and JavaScript is the entire point, and arbitrary JavaScript served from your own domain is powerful by definition.

- Artifact editing and publishing is gated to trusted users with `unfiltered_html` for this post type (single-site admins; multisite super admins by default).
- If you customize capabilities to allow less-trusted users, save-time sanitization still applies to block HTML via `wp_kses_post`.
- Because a canvas is served same-origin, its JavaScript can reach same-origin cookies, storage, and endpoints. With a single trusted admin this is fine. If you ever open authoring to multiple or less-trusted users, treat isolated serving (a sandboxed iframe or a separate origin) as a hard requirement, not an option.
- Static merge tag values are sanitized server-side (`sanitize_text_field`) on save and escaped with `esc_html` at render time. Dynamic merge tag values are the developer's responsibility — filter callbacks must return properly escaped strings for their HTML context.
- Asset map URLs are sanitized with `esc_url_raw` on save and `esc_url` at render time.

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
| Asset map meta key | `_wmac_asset_map` |
| Tag map meta key | `_wmac_tag_map` |

## Known limitations

- **One artifact per canvas.** By design — the block is locked to a single instance.
- Sanitized (non-trusted) authors cannot host scripted or styled artifacts, by design.
- **ZIP ingestion is single-entry today.** Git package deploy currently extracts and serves `index.html`; multi-file package serving is not yet implemented.

## License

GPL-2.0-or-later, to match WordPress.
