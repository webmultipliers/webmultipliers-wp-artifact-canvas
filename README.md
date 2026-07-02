# WP Artifact Canvas

> Host fully-rendered HTML artifacts on WordPress — paste a complete HTML document into a post and serve it 1:1 on the front end, with zero theme interference and full respect for WordPress access rules.

**Package slug:** `webmultipliers-wp-artifact-canvas` · **Status:** Stable — 1.0 · **Author:** Web Multipliers · **License:** GPL-2.0-or-later

---

WP Artifact Canvas turns a WordPress install into a host for self-contained HTML artifacts — the complete, single-file documents produced by AI tools, design prototyping, or hand-built demos. Paste a whole `<!doctype html>` document into a post, publish it, and the public URL renders that document **exactly as written**: no theme header or footer, no injected wrappers, no global stylesheet, no script collisions.

It exists because WordPress is excellent at access control, URLs, revisions, and content management — but actively hostile to serving a raw HTML document. This plugin gets the theme, Gutenberg's markup rewriting, and `wp_head` out of the way for one dedicated post type, while keeping everything WordPress is good at.

## Why teams use it

- **True passthrough rendering** — a published canvas serves only the artifact's bytes, through a filterable render pipeline (asset mapping, code injection, merge tags, SEO meta, tracking).
- **A purpose-built editor** — one locked block with a syntax-highlighted code editor, server-processed live preview, file upload/attachment, and a full configuration report.
- **Dynamic without code** — `{{merge_tags}}` with built-in WP data, static values, fallbacks, and a one-filter developer API.
- **WordPress-native access control** — drafts, scheduling, passwords, revisions, and a capability model that sanitizes untrusted authors instead of trusting them.
- **Client-ready governance** — lifecycle statuses, a Clients taxonomy, ownership, link expiry, view limits, analytics snippets, and view webhooks.
- **Automatable** — a REST surface for creation, file attachment, and preview, plus Git/CI webhook deployments straight from a repository.
- **Isolatable** — serve artifacts from a dedicated sandbox origin so their JavaScript can never reach your main site's cookies or REST API.
- **Beyond HTML** — PDF mode serves documents through a PDF.js viewer with the same gates and URLs.

## Quick start

```text
Requirements: WordPress 6.4+, PHP 8.1+
```

1. Upload the plugin folder to `/wp-content/plugins/` and activate **WP Artifact Canvas**.
2. **Artifacts → Add New**, paste a complete HTML document into the block, **Publish**.
3. Visit `/artifact/your-slug/` — your document, byte for byte.

Full walkthrough: [Getting Started](docs/getting-started.md).

## Documentation

All documentation lives in [`/docs`](docs/README.md):

| | |
| --- | --- |
| **Use it** | [Getting Started](docs/getting-started.md) · [The Editor](docs/editor.md) · [Merge Tags](docs/merge-tags.md) · [Asset Mapping](docs/asset-mapping.md) · [Code Injection](docs/code-injection.md) · [Governance & Clients](docs/governance.md) · [PDF Mode](docs/pdf-mode.md) |
| **Understand it** | [Rendering Pipeline](docs/rendering.md) · [Routing](docs/routing.md) · [Security Model](docs/security.md) · [Sandbox Isolation](docs/sandbox.md) |
| **Extend it** | [REST API](docs/rest-api.md) · [Filter & Meta Reference](docs/reference.md) · [Development](docs/development.md) |

## What it is *not*

- Not a page builder — the artifact's own CSS/JS is the entire presentation layer.
- Not a general untrusted-content host — authoring is a trusted, capability-gated action ([security model](docs/security.md)).
- Not a theme or a block library — it ships exactly one block, used in exactly one place.

## Known limitations

- One artifact per canvas, by design.
- Sanitized (non-trusted) authors cannot host scripted artifacts, by design — see [the artifact KSES profile](docs/security.md#the-artifact-kses-profile).
- Git package deploys currently extract and serve `index.html` only; multi-file package serving is not yet implemented.

## Contributing

```bash
composer install && composer test   # unit suite — no WordPress install required
npx @wordpress/env start            # disposable WordPress at localhost:8888
```

See [Development](docs/development.md) for the full workflow, CI, and release process.
