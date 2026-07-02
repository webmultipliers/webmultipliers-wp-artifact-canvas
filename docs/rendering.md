# Rendering Pipeline

How a published artifact goes from stored HTML to a 1:1 response.

## Steps

1. **Interception.** On `template_redirect`, a single-artifact request short-circuits the normal template hierarchy. oEmbed (`/embed/`) requests are left to WordPress.
2. **Gates.** Password protection is checked first (serving a theme-free password screen when required), then [link governance](governance.md#link-governance) (expiry date / max views → HTTP 410).
3. **Format dispatch.** Artifacts in [PDF mode](pdf-mode.md) branch to the PDF.js viewer shell; everything else continues as HTML passthrough.
4. **Content resolution.** The HTML comes from the server-attached file when one exists, otherwise from the block attribute. Previews by authorized editors use autosave content, so unsaved changes preview correctly. Documents that start at `<html>` with no doctype get `<!DOCTYPE html>` restored (KSES-sanitized saves cannot preserve one — see [Security Model](security.md#the-artifact-kses-profile)).
5. **Filter chain.** The HTML passes through `wmac_rendered_html` with these built-in subscribers:

   | Priority | Subscriber | Does |
   | --- | --- | --- |
   | 10 | AssetMapper | [Relative-path → Media Library URL substitution](asset-mapping.md) |
   | 15 | CodeInjection | [External styles/scripts + head/body markup](code-injection.md) |
   | 20 | SeoMeta | Opt-in OG / Twitter Card tags (registers before MergeTags, so it runs first at this priority) |
   | 20 | MergeTags | [`{{tag}}` substitution](merge-tags.md) |
   | 30 | ClientTracking | [Analytics snippet injection](governance.md#tracking--view-webhooks) |

   Third-party code can hook at any priority. The in-editor preview endpoint runs this same chain.
6. **Observability.** `wmac_artifact_served` fires before output — view counting and [webhooks](governance.md#tracking--view-webhooks) attach here.
7. **Output.** Content-type and security headers are sent, the HTML is echoed, and the process exits. Nothing from WordPress is appended.

## Headers

- `Content-Type: text/html` with the site charset, plus `X-Content-Type-Options: nosniff`.
- **Caching:** published, non-password artifacts are edge-cacheable (`public, max-age=3600, s-maxage=86400`) — unless link-governed (expiry/max views), which forces `no-store` so shared caches can't outlive the limits. Everything else is `no-store`.
- **Indexing:** `X-Robots-Tag: noindex, nofollow` by default; controlled globally in settings, per artifact in its metabox, or via the `wmac_noindex` filter.
- **CSP:** a `Content-Security-Policy` header is sent when configured (global setting, per-artifact field, or `wmac_csp` filter).

## Admin toolbar

For logged-in users who can edit the artifact, a management toolbar can be injected into the served page — quick links to Edit, the artifact list, and New Artifact. Modes: `custom` (default, lightweight bar), `core` (the WordPress admin bar), or `none`; set globally in **Artifacts → Settings** or via the `wmac_artifact_admin_toolbar_mode` filter. If the artifact's CSP forbids inline styles, injection is skipped rather than rendering a broken overlay.

## oEmbed

Published public artifacts are oEmbed providers: pasting an artifact URL into any Gutenberg editor embeds it as a live sandboxed iframe. A discovery `<link>` is injected into the served `<head>`. The iframe's sandbox attribute is filterable via `wmac_oembed_iframe_sandbox`.
