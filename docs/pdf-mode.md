# PDF Mode

Artifacts can deliver a PDF instead of HTML — useful for proposals, reports, and other client deliverables that should live at a managed WordPress URL with the same access rules.

## How it works

Upload a PDF via `POST /wmac/v1/artifacts/{id}/pdf-file` (or the editor integration). This stores the binary in the protected `wmac-artifacts` directory, sets `_wmac_format: pdf`, and switches the artifact's render path: the public URL now serves a branded **PDF.js viewer shell** instead of HTML passthrough. The PDF bytes themselves stream from `GET /wmac/v1/artifacts/{id}/pdf`, which respects the same password gate as the page.

All the usual gates still apply first — password protection, [link governance](governance.md#link-governance) — and `wmac_artifact_served` fires for view counting and webhooks.

Uploading an HTML file to an artifact in PDF mode retires the PDF profile cleanly (the orphaned binary and format flag are removed) and returns it to HTML passthrough.

## Configuration

| Filter | Default | Purpose |
| --- | --- | --- |
| `wmac_pdfjs_url` | Mozilla CDN | Override the PDF.js module URL (e.g. self-host) |
| `wmac_pdfjs_worker_url` | Mozilla CDN | Override the PDF.js worker URL |
| `wmac_max_pdf_size` | 50 MB | Max PDF upload size in bytes |

When a [sandbox host](sandbox.md) is active, the PDF byte stream is sandbox-origin only for public requests; editors keep main-origin access for previews.
