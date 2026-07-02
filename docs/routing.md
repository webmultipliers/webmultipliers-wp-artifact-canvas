# Routing

Three ways to put an artifact on a URL.

## Artifact permalinks

Every artifact gets a standard permalink under `/artifact/{slug}/`. This is plain WordPress routing — slugs, drafts, scheduling, and password protection all behave normally. When a [sandbox host](sandbox.md) is configured, published permalinks point at the sandbox origin instead.

## Custom URL aliases

The **Custom URL alias** field (Artifact Settings metabox, `_wmac_alias` meta) serves an artifact at any front-end path — e.g. `/pricing` — without changing the permalink structure. Aliases resolve via `do_parse_request` (priority 1), before normal query parsing. First path segments that would shadow core routes (admin, REST, feeds, …) are reserved and can't be aliased.

## Page usurpation

Any standard WordPress Page can serve a published artifact's bytes at the Page's URL — use WP's native page tree and slug management for the URL, powered by an artifact.

**To configure:** open the Page in the editor; select a published artifact in the **Artifact Usurpation** metabox and save. The artifact's full render pipeline (password gate, governance, `wmac_rendered_html` filters) applies at the usurped URL, intercepted at `template_redirect` priority 5.

A usurped page serves artifact JavaScript at a **main-site URL by design**, so [sandbox origin isolation](sandbox.md) cannot apply to it. Compensating restrictions:

- Configuring usurpation requires the `unfiltered_html` capability (both the metabox and the REST meta write).
- At serve time, the artifact's author must also hold `unfiltered_html` — artifacts authored by untrusted users are never usurped.
- A strict `Content-Security-Policy` is sent by default (`connect-src 'none'` blocks all fetch/XHR from the page, including authenticated same-origin REST calls). Relax or remove it with the `wmac_usurpation_csp` filter.
