# REST API

All custom routes live under `/wp-json/wmac/v1`.

| Endpoint | Method(s) | Purpose |
| --- | --- | --- |
| `/artifacts` | `POST` | Create a new artifact from HTML (`html`, optional `title` and `status`) |
| `/artifacts/{id}/file` | `GET`, `POST`, `DELETE` | Read / upload / remove the server-stored HTML file attachment |
| `/artifacts/{id}/preview` | `POST` | Render submitted HTML (or the stored content) through the full `wmac_rendered_html` pipeline; powers the editor's live preview |
| `/artifacts/{id}/pdf` | `GET` | Stream PDF bytes for artifacts in [PDF mode](pdf-mode.md) (password gate respected) |
| `/artifacts/{id}/pdf-file` | `POST`, `DELETE` | Upload / remove the PDF attachment and toggle PDF mode |
| `/git-webhook/{id}` | `POST` | Validate webhook signature, fetch archive, extract `index.html`, deploy to the artifact |

## Authentication

- Artifact creation, file, and preview endpoints require an authenticated user with edit rights on the artifact (see [Security Model](security.md#capabilities)).
- **In-browser** calls must send a REST nonce via the `X-WP-Nonce` header — this is what the editor does via `wp.apiFetch`.
- **Server-to-server** automation should use [Application Passwords](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) over HTTPS — all `/wmac/v1/*` routes work with Basic auth via an application password.
- The Git webhook authenticates via HMAC signature instead of cookie auth (below).

## Upload limits & sanitization

HTML file uploads accept `.html`/`.htm` up to 10 MB (`wmac_max_file_size`). Uploads from users **without** `unfiltered_html` pass through the [artifact KSES profile](security.md#the-artifact-kses-profile) and are capped at 2 MB (`wmac_max_kses_file_size`) so sanitization can't exhaust the request worker. Preview responses for such users are KSES-filtered the same way — the preview never shows markup the user couldn't persist.

## Git / CI webhook deployments

`POST /wmac/v1/git-webhook/{id}` turns an artifact into a deploy target:

1. Set a shared secret in the `wmac_git_webhook_secret` option.
2. Point a GitHub (`X-Hub-Signature-256` HMAC) or GitLab (`X-Gitlab-Token`) webhook at the endpoint.
3. On push, the plugin resolves the archive URL from the provider payload, downloads it, validates the ZIP (size, nesting depth, blocked extensions, `index.html` required), and stores the extracted `index.html` as the artifact's served content.

Custom CI tools can bypass the provider payloads entirely: `POST` a JSON body with `{ "wmac_archive_url": "https://…/build.zip" }` to the same endpoint. **The archive host must be allowlisted first** via the `wmac_git_archive_hosts` filter — as SSRF protection, only `api.github.com`, `codeload.github.com`, and `gitlab.com` are allowed by default.

Related filters: `wmac_git_webhook_token` (Bearer token for private archive downloads), `wmac_git_webhook_allow_unsigned` (development only), `wmac_max_zip_size`, `wmac_max_zip_depth`.

## Stability

The REST routes, action/filter names, and signatures documented here are stable as of 1.0 and follow semver — breaking changes only in a 2.0.
