# WP Artifact Canvas — Documentation

Full documentation for [WP Artifact Canvas](../README.md). Start with [Getting Started](getting-started.md) if you're new.

## Using the plugin

| Guide | Covers |
| --- | --- |
| [Getting Started](getting-started.md) | Requirements, installation, publishing your first artifact |
| [The Editor](editor.md) | The Artifact Canvas block, toolbar actions, live preview, configuration report, sidebar panels, management metaboxes |
| [Merge Tags](merge-tags.md) | `{{tag}}` templating, built-in tags, fallback values, escaping, the dynamic-tag developer API |
| [Asset Mapping](asset-mapping.md) | Mapping relative asset paths to Media Library files |
| [Code Injection](code-injection.md) | External stylesheets/scripts, raw `<head>`/`<body>` markup, tracking snippets |
| [Governance & Client Workflows](governance.md) | Lifecycle statuses, Clients taxonomy, ownership, link expiry, view limits, view webhooks |
| [PDF Mode](pdf-mode.md) | Serving PDF artifacts through the PDF.js viewer shell |

## How it works

| Guide | Covers |
| --- | --- |
| [Rendering Pipeline](rendering.md) | Passthrough interception, the `wmac_rendered_html` filter chain, headers, the admin toolbar |
| [Routing](routing.md) | Artifact permalinks, custom URL aliases, Page usurpation |
| [Security Model](security.md) | Capabilities, the artifact KSES profile, stored-file protection, trust boundaries |
| [Sandbox Origin Isolation](sandbox.md) | Serving artifacts from an isolated host |

## Integrating & contributing

| Guide | Covers |
| --- | --- |
| [REST API](rest-api.md) | All `/wmac/v1` endpoints, authentication, Git/CI webhook deployments |
| [Reference](reference.md) | Every filter and action, meta keys, global settings, technical configuration |
| [Development](development.md) | Local environment, test suite, static analysis, build & release |
