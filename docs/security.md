# Security Model

This is a **trusted-author tool**. Hosting arbitrary HTML, CSS, and JavaScript is the entire point, and arbitrary JavaScript served from your own domain is powerful by definition. The model below controls *who* can do that and contains the blast radius when they can't be fully trusted.

## Capabilities

Artifact editing and publishing is gated by a dedicated capability set (`edit_wm_artifacts`, `publish_wm_artifacts`, …) generated via `capability_type = wm_artifact` with `map_meta_cap`. Administrators receive the full set on activation; grant individual capabilities to other roles with any role editor or WP-CLI to delegate authoring.

**Capability ≠ trust for raw HTML.** Holding artifact capabilities does not imply `unfiltered_html`. Note that on multisite, even site administrators lack `unfiltered_html` by default — only super admins have it.

## The artifact KSES profile

Authors **without** `unfiltered_html` have their artifact HTML sanitized on save (and on file upload) through a KSES profile tailored to standalone documents:

- The **document skeleton is preserved** — `<html>`, `<head>`, `<title>`, `<body>`, `<meta>`, `<link>`, `<style>` are allowlisted on top of the standard post allowlist, so a sanitized artifact stays a working, styled document rather than a stripped fragment. The plugin extends core's own save-chain KSES pass with the same profile, so both sanitizers agree.
- **Scripts are removed entirely** — `<script>` elements are stripped *including their bodies* (KSES alone would leave the source code behind as visible page text), along with `on*` event handlers and unsafe URL protocols.
- KSES cannot represent a doctype, so `<!DOCTYPE html>` is restored at render time for documents starting at `<html>`.
- The allowlist is filterable via `wmac_kses_allowed_html`.

The same trust bar applies everywhere content enters: block saves, file uploads (capped at 2 MB for sanitized users — `wmac_max_kses_file_size`), and the editor preview endpoint.

## Field-level trust

- External script URLs, raw `<head>`/`<body>` markup, tracking snippets, and Page usurpation all require `unfiltered_html` to save. External stylesheet URLs only require edit access.
- Static merge tag values are escaped context-aware at render time (`esc_html` / `esc_attr` / `esc_url`); dynamic merge tag callbacks are the developer's escaping responsibility.
- Asset map URLs are sanitized with `esc_url_raw` on save and `esc_url` at render time.
- Injected raw fragments have unclosed `<script>`/`<style>` tags auto-closed so a malformed fragment cannot swallow the document ([details](code-injection.md#unclosed-tags-are-repaired)).

## Stored-file protection

Server-attached HTML/PDF files use randomized names (`{id}-{hmac}.html`, HMAC-keyed with the site salt and persisted in post meta so salt rotations don't orphan files) inside `uploads/wmac-artifacts/`, which ships an Apache `.htaccess` denying direct access. On Nginx, add:

```nginx
location ^~ /wp-content/uploads/wmac-artifacts/ { deny all; }
```

An admin health check probes the directory over HTTP every 12 hours and shows an error notice (with the rule above) if it is publicly reachable.

## Origin isolation

A canvas served same-origin can reach same-origin cookies, storage, and authenticated endpoints when a logged-in user views it. For a single trusted author that can be acceptable; for anything broader, configure the [sandbox host](sandbox.md).

## Outbound request safety

The Git webhook only downloads archives over HTTPS from `api.github.com`, `codeload.github.com`, or `gitlab.com` unless you extend `wmac_git_archive_hosts` — the custom `wmac_archive_url` branch is inert until you allowlist your CI's host.
