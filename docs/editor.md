# The Editor

Each artifact is edited through a single, locked **Artifact Canvas** block — a syntax-highlighted CodeMirror editor with no rich text and no paste-to-blocks transforms, so a wholesale paste stays raw. The HTML is stored as the block's attribute (or as a protected server-side file in attached mode), making it the canonical source of truth.

## Block toolbar actions

| Button | Description |
| --- | --- |
| **Upload HTML** | Read a `.html` file client-side into the code editor |
| **Attach File** | Upload a `.html` file to the server; the block stores a reference instead of inline HTML (see [file attachment](#server-side-file-attachment)) |
| **Preview / Edit** | Toggle between the code editor and a sandboxed iframe preview |
| **☀ / 🌙** | Toggle the code editor between light and dark themes |
| **⚙ Settings** | Open the [configuration report](#configuration-report) |
| **Download** | Save the current artifact HTML to disk |
| **Open ↗** | Open the WordPress preview link in a new tab |

An **Inspect Configuration** button also appears in the block's floating toolbar when the block is selected — it opens the same configuration report.

## Live preview

The **Preview** button renders the artifact in an isolated `<iframe sandbox="allow-scripts">`, so its CSS and JS never leak into wp-admin. For saved posts, the preview is **server-processed**: the editor posts the current (even unsaved) HTML to `POST /wmac/v1/artifacts/{id}/preview`, which runs the full `wmac_rendered_html` pipeline — asset mapping, external styles/scripts, head/body injection, merge tags, tracking — so what you preview is what visitors receive. Unsaved posts and failed requests fall back to a raw-HTML preview with a notice.

Note that meta-driven steps (merge tag maps, code injection fields) reflect their **saved** values; the block HTML itself is taken live from the editor.

## Configuration report

The **⚙ Settings** button opens a read-only snapshot of the artifact's full configuration:

- **Configuration signals** — ranked callouts for security- or behavior-relevant settings (external scripts configured, raw HTML injection enabled, tracking present, no CSP override, indexing allowed, …), each with a jump-to-setting link.
- **Post & block snapshot** — ID, status, URLs, stored-file mode, HTML size, detected merge tags and relative asset paths.
- **Meta configuration** — every `_wmac_*` meta key with its current value, filterable to configured-only.

**Copy JSON** / **Copy Signals** export the snapshot for sharing or audits.

## Server-side file attachment

**Attach File** uploads the document to `{uploads}/wmac-artifacts/{id}-{token}.html` instead of storing it in the block, which keeps multi-hundred-KB artifacts out of post content. The renderer always checks for a stored file first, then falls back to the block attribute. Files use unguessable HMAC-tokenized names and the directory is blocked from direct HTTP access ([details](security.md#stored-file-protection)). New posts are auto-saved as drafts before the upload. **Replace File** / **Remove File** manage the attachment afterward.

## Sidebar panels

Quick-reference panels in the Post sidebar (Document tab):

- **Lifecycle Status** — draft/pending/publish/private or a custom status (In Review, Approved, Archived)
- **Ownership** — assign a responsible developer or project manager (WP user)

## Management metaboxes

Six full-width metaboxes appear below the code editor, one per concern:

| Metabox | Fields |
| --- | --- |
| **Artifact Settings** | Custom URL alias, noindex override, SEO meta injection toggle, Content Security Policy, generation prompt |
| **Custom Code & External Assets** | External stylesheet URLs, external script URLs, raw `<head>` markup, raw pre-`</body>` markup ([details](code-injection.md)) |
| **Link Governance** | Expiry date (ISO 8601), max public views, live view count ([details](governance.md)) |
| **Tracking & Webhooks** | Analytics snippet, view alert webhook URL ([details](governance.md#tracking--view-webhooks)) |
| **Merge Tags** | Detect and configure `{{tag}}` placeholders ([details](merge-tags.md)) |
| **Asset Mapping** | Detect unresolved relative paths and map each to a Media Library file ([details](asset-mapping.md)) |

**Autosave, not "click Update."** Every field in these metaboxes saves itself: text fields PATCH their value to the REST API shortly after you stop typing, checkboxes and selects save on change, and the Merge Tags / Asset Mapping tables save on every edit. A status line under each metabox shows *Saving…* / *Saved*. This is deliberate — WordPress renders these metaboxes outside the block editor instance, so the editor's "Update" button never reaches them; direct REST calls are the only reliable path. Detected `{{tags}}` and relative asset paths are parsed from the artifact's last-saved HTML, so newly pasted placeholders appear after you update the block content itself.
