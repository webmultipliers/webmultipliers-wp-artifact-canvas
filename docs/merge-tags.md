# Merge Tags

Embed `{{placeholders}}` anywhere in an artifact's HTML and they resolve at render time. The stored HTML is never modified — substitution happens in the `wmac_rendered_html` pipeline at priority 20.

Tag names are restricted to `[a-zA-Z0-9_]+`. Whitespace inside the braces is tolerated: `{{ tag }}` works.

## Resolution modes

The **Merge Tags** metabox below the editor lists every detected tag and offers two modes:

| Mode | Behaviour |
| --- | --- |
| **Static string** | You enter a value in the metabox. It is substituted at render time with context-aware escaping: `text` (`esc_html`, default), `attr` (`esc_attr`, for placeholders inside attribute values), or `url` (`esc_url`, which also rejects unsafe protocols like `javascript:`). |
| **Dynamic hook** | The tag is resolved at request time by a PHP filter — see the [developer API](#developer-api--dynamic-merge-tags). |

## Fallback values

Append `|| "fallback"` inside the braces to emit a fallback when the resolved value is empty:

```html
<h1>{{client_name || "Valued Client"}}</h1>
<div class="{{theme_class || 'theme-default'}}">
```

- The fallback fires when the tag's raw resolved value is empty or whitespace-only — for static tags with no value yet, dynamic tags whose filter returns `''`, and entirely unknown tags.
- Double or single quotes around the fallback are both accepted (escape a literal quote inside as `\"` / `\'`); unquoted text is used trimmed.
- Fallbacks are escaped for the same context as the tag they back (`text` / `attr` / `url`).

## Escaping for client-side template syntax

If your artifact uses Vue, Alpine, Mustache, or anything else with `{{ }}` syntax, a placeholder that collides with a configured tag name would be substituted server-side. Prefix it with a backslash to opt out:

```html
\{{count}}   →  rendered as the literal  {{count}}
```

The backslash is removed and no substitution happens. Unknown tags without a fallback are always left untouched, so most client-side templates need no escaping at all.

## Built-in tags

These resolve automatically, with no configuration:

| Tag | Resolves to |
| --- | --- |
| `{{wp_post_title}}` | Artifact post title |
| `{{wp_post_id}}` | Artifact post ID |
| `{{wp_post_url}}` | Artifact permalink |
| `{{wp_post_date}}` | Published date (site date format) |
| `{{wp_post_modified_date}}` | Last-modified date (site date format) |
| `{{wp_post_excerpt}}` | Post excerpt |
| `{{wp_post_author}}` | Author display name |
| `{{wp_post_slug}}` | Post slug |
| `{{wp_site_name}}` | Site name (`bloginfo('name')`) |
| `{{wp_site_url}}` | Site home URL |
| `{{wp_site_tagline}}` | Site tagline (`bloginfo('description')`) |
| `{{wp_current_year}}` | Current four-digit year |
| `{{wp_current_date}}` | Current date (site date format) |

Built-in resolvers fire via the same `wmac_resolve_tag_*` filter mechanism and can be overridden at any priority.

## Developer API — dynamic merge tags

```php
// Resolves {{current_user_email}} at render time.
add_filter( 'wmac_resolve_tag_current_user_email', function ( string $default, int $post_id ): string {
    return is_user_logged_in() ? esc_html( wp_get_current_user()->user_email ) : '';
}, 10, 2 );
```

The filter name is `wmac_resolve_tag_{tag_name}`. The callback receives the default empty string and the `$post_id`. The return value is echoed directly into the HTML — **the developer is responsible for escaping** for the target HTML context (`esc_html`, `esc_attr`, `esc_js`, …). Returning an empty string triggers the tag's `||` fallback, if one is written in the HTML.
