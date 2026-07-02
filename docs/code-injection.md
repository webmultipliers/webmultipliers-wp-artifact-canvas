# Code Injection

The **Custom Code & External Assets** metabox provides CodePen-style per-artifact injection — resources and raw markup added at render time, without touching the stored HTML. Injection runs in the `wmac_rendered_html` pipeline at priority 15 (after [asset mapping](asset-mapping.md), before [merge tags](merge-tags.md) — so `{{tags}}` inside injected code still resolve).

## Fields

| Field | Meta key | Injected as |
| --- | --- | --- |
| **External Stylesheets** | `_wmac_external_styles` | One URL per line → `<link rel="stylesheet">` tags before `</head>`, in order |
| **External Scripts** | `_wmac_external_scripts` | One URL per line → `<script src>` tags before `</body>`, in order |
| **Stuff for `<head>`** | `_wmac_head_html` | Raw markup before `</head>` — meta tags, inline styles, font loaders |
| **Stuff before `</body>`** | `_wmac_body_html` | Raw markup before `</body>` — inline scripts, widgets, embeds |

Injection order is designed for layering: in the head, stylesheet `<link>`s come first so inline `<style>` in your head markup can override them; in the body, external `<script>`s come first so your inline init code can rely on the libraries loaded above it.

Documents without `</head>` / `</body>` markers still receive injections (prepended / appended respectively).

## Unclosed tags are repaired

`<script>` and `<style>` are raw-text elements: one unclosed opener swallows *everything* after it — a single `<script src="…">` missing its `</script>` in a head injection turns the entire page into script text and renders it blank. HTML5 doesn't honor self-closing syntax for these elements either, so `<script src="…" />` is just as broken.

At injection time the plugin therefore balances every raw fragment: missing `</script>` / `</style>` closers are appended automatically. The same protection applies to [tracking snippets](governance.md#tracking--view-webhooks). You should still write closed tags — the repair closes the element at the end of *your fragment*, so anything you meant to come after an unclosed script inside the same field would end up inside it.

## Trust requirements

Head/body markup and external **script** URLs execute in the artifact page, so saving them requires the `unfiltered_html` capability — the same bar as raw artifact markup. External **stylesheet** URLs cannot execute script and only require edit access. PHP open tags are stripped from injected markup; URL lists are validated with `esc_url_raw` per line.
