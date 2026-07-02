# Sandbox Origin Isolation

Because an artifact is arbitrary HTML+JS, serving it from your main origin means its scripts run with same-origin access to your site — cookies scoped to that host, authenticated REST when a logged-in admin views it. For a single trusted author that can be acceptable; for anything broader, serve artifacts from an isolated origin.

## Setup

Point a second hostname at the same WordPress install (same DocumentRoot / same server block), then set it under **Artifacts → Settings → Sandbox host** (or via the `wmac_sandbox_host` filter from a plugin — it runs at `plugins_loaded`, so a theme is too late).

| Topology | Isolation | Notes |
| --- | --- | --- |
| Separate registrable domain (`example-artifacts.com`) | Full cookie isolation, always | Strongest; recommended for multi-author or client-facing installs |
| Subdomain (`artifacts.example.com`) | Isolated **only** with host-only cookies | Safe with the WP default (`COOKIE_DOMAIN` unset). Never combine with a wildcard `COOKIE_DOMAIN` like `.example.com` |

Leave the setting blank to keep same-origin serving.

## What changes when enabled

**On the main origin:**

- Published artifact permalinks (and oEmbed iframes) point at the sandbox host.
- A public artifact request on the main origin 301s to the sandbox URL. Editor previews stay on the main origin, where the editor is authenticated.
- The PDF byte stream refuses public main-origin requests (editors keep access).

**On the sandbox host — defense in depth on every request:**

- Authentication is ignored entirely (`determine_current_user` forced to `0`), so a stray cookie is inert server-side.
- Non-artifact front-end requests return a minimal 404 — the theme never renders on this origin.
- REST is allowlisted to the artifact PDF stream and oEmbed routes; everything else 404s.
- `wp-login.php` serves only the post-password action, and the password form's action is rewritten to the sandbox host so the postpass cookie is set where the visitor actually is — password-protected artifacts keep working.
- Baseline isolation headers (`Referrer-Policy: no-referrer`) are added; per-artifact CSP applies on top.

## Exception

[Page usurpation](routing.md#page-usurpation) intentionally bypasses the sandbox — it serves at a main-site Page URL by design, with its own compensating restrictions (author trust requirements and a strict default CSP).
