# Governance & Client Workflows

Features for agencies and teams managing artifacts for clients.

## Lifecycle statuses

Three custom private statuses complement core draft/publish states: **In Review**, **Approved**, and **Archived**. Set them from the Lifecycle Status sidebar panel (or the status control on the classic list table). They behave like private statuses — not publicly viewable — and make list-table filtering by pipeline stage possible.

## Clients taxonomy & ownership

- **Clients** (`wm_client`) — a hierarchical, non-public taxonomy for grouping artifacts by client/engagement, visible in the list table.
- **Ownership** (`_wmac_owner_id`) — assign a responsible WP user per artifact from the sidebar panel; shown as a sortable admin column.

## Link governance

Retire a shared link automatically, from the **Link Governance** metabox:

| Field | Behaviour |
| --- | --- |
| **Expire after date** | ISO 8601 timestamp (UTC). Public requests after it return a dedicated expired page with HTTP 410. |
| **Max public views** | Link expires after this many public views. Blank or 0 = unlimited. |
| **Public views** | Live read-only counter. |

Editors with edit rights always pass the gate — expiry applies to public visitors only. Link-governed artifacts are never edge-cached (the response is forced `no-store`), so the counter keeps incrementing and expiry takes effect immediately.

## Tracking & view webhooks

From the **Tracking & Webhooks** metabox:

- **Analytics snippet** (`_wmac_tracking_snippet`) — raw markup injected before `</head>` on every serve (priority 30 in the [render pipeline](rendering.md)). Compatible with Plausible, Fathom, GA, or any tag-based snippet. Saving requires `unfiltered_html`. Unclosed `<script>`/`<style>` tags are closed automatically at injection time so a truncated snippet can't blank the page.
- **View webhook URL** (`_wmac_view_webhook_url`) — a non-blocking JSON `POST` fires on every public serve (`wmac_artifact_served`), carrying post ID, URL, timestamp, and request metadata. Customize the payload with `wmac_view_webhook_payload`, IP-header trust with `wmac_view_webhook_ip_headers`, and TLS verification with `wmac_view_webhook_sslverify`.
