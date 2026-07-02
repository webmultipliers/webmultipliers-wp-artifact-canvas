# Getting Started

## Requirements

- WordPress 6.4+
- PHP 8.1+

## Installation

1. Download a packaged release or use the repository as-is.
2. Upload the `webmultipliers-wp-artifact-canvas` folder to `/wp-content/plugins/`.
3. Activate **WP Artifact Canvas** from the Plugins screen. Activation registers the post type and flushes rewrite rules so canvas URLs resolve immediately.
4. A new **Artifacts** menu item appears in the admin sidebar.

## Your first artifact

1. Go to **Artifacts → Add New**.
2. The editor opens with the Artifact Canvas block already in place — it is the only block allowed on this post type.
3. Paste a complete HTML document — including `<style>` and `<script>` — into the code field, or load one with **Upload HTML** / **Attach File** from the block toolbar.
4. Click the in-editor **Preview** button. The preview runs the same server-side render pipeline as the front end (merge tags, code injection, asset mapping), inside a sandboxed iframe isolated from wp-admin.
5. Publish. The public URL (`/artifact/your-slug/`) serves your document byte-for-byte — no theme header or footer, no global stylesheet, no `wp_head` output.

If the post has no title, one is extracted automatically from the document's `<title>` tag on save.

## What WordPress still does for you

Draft, private, scheduled, and password-protected artifacts behave exactly like any WordPress post. Password-protected artifacts get a clean, theme-free password screen. Revisions are kept — and the Revisions screen diffs the extracted HTML, not raw block JSON — so you can always roll back an artifact to a previous version.

## Where to go next

- [The Editor](editor.md) — every toolbar button, panel, and metabox explained
- [Merge Tags](merge-tags.md) — make artifacts dynamic with `{{placeholders}}`
- [Security Model](security.md) — who can publish raw HTML, and what happens when they can't
