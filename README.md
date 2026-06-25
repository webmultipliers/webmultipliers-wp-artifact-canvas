# WP Artifact Canvas

> Host fully-rendered HTML artifacts on WordPress — paste a complete HTML document into a post and serve it 1:1 on the front end, with zero theme interference and full respect for WordPress access rules.

**Package slug:** `webmultipliers-wp-artifact-canvas`
**Status:** Greenfield / in development
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
- **A purpose-built editing block.** A single locked block per canvas with a plain code editor (no rich text, no paste-to-blocks transforms) so a wholesale paste stays raw. The raw HTML is stored as the block's source of truth, not as fragile inner markup.
- **Sandboxed editor preview.** The artifact previews inside an isolated `<iframe>` in the editor, so its CSS and JS never leak into wp-admin.
- **Access rules honored.** Draft, private, password-protected, and scheduled canvases behave the way any WordPress post would. Password-protected canvases get a clean, theme-free entry screen.
- **Capability-aware sanitization.** Authors without the capability to post raw markup have their content run through `wp_kses_post` on save, the same as core. This is a trusted-author tool by design.

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
3. Paste your complete HTML document — including `<style>` and `<script>` — into the code field.
4. Use the in-editor preview to confirm it renders correctly in isolation.
5. Publish. Visit the public URL and you'll see your document rendered exactly as written, with no WordPress chrome.

## How it works

1. **Post type.** Registers a custom post type (`wm_artifact`, labeled "Artifacts") with a locked editor template containing one Artifact Canvas block.
2. **Storage.** The pasted document is stored as an attribute on the block, making it the canonical source of truth. The block is dynamic (it produces no static saved markup), so Gutenberg never flags a full document as "invalid."
3. **Interception.** On `template_redirect`, a single-artifact request short-circuits the normal template hierarchy.
4. **Output.** The plugin reads the stored HTML, sends correct content-type and security headers, echoes the document, and exits — a 1:1 render. Nothing from WordPress is appended.
5. **Protected views.** If the post requires a password, a minimal standalone HTML shell with the password form is served instead, still fully isolated from the theme.
6. **Author toolbar injection (optional).** On passthrough responses, the plugin can inject either a custom management bar or the core admin bar for authorized users without involving the theme template stack.

## Security model

This is a **trusted-author tool**. Hosting arbitrary HTML, CSS, and JavaScript is the entire point, and arbitrary JavaScript served from your own domain is powerful by definition.

- Authoring is gated to users who can post unfiltered markup. On a standard single site that is administrators; on multisite it is super admins. Authors without that capability have their content sanitized with `wp_kses_post` on save, which strips `<script>` and `<style>` — meaning a real artifact requires a trusted author.
- Because a canvas is served same-origin, its JavaScript can reach same-origin cookies, storage, and endpoints. With a single trusted admin this is fine. If you ever open authoring to multiple or less-trusted users, treat isolated serving (a sandboxed iframe or a separate origin) as a hard requirement, not an option. See `PLAN.md` for the planned escalation path.

## Technical configuration

| Setting | Value |
| --- | --- |
| Package / text domain | `webmultipliers-wp-artifact-canvas` |
| PHP namespace | `WebMultipliers\ArtifactCanvas` |
| Constant prefix | `WMAC_` |
| Post type key | `wm_artifact` |
| Public URL slug | `/artifact/` |
| Block name | `wmac/artifact` |

## Known limitations

- **Draft preview of unsaved edits** does not flow through the passthrough renderer, which reads saved content. The in-editor sandboxed preview is the intended way to preview before publishing.
- **One artifact per canvas.** By design — the block is locked to a single instance.
- Sanitized (non-trusted) authors cannot host scripted or styled artifacts, by design.

## License

GPL-2.0-or-later, to match WordPress.