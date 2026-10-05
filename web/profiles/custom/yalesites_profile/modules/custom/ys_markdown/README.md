# YaleSites Markdown (ys_markdown)

Serves a public Markdown version of a node at its URL plus `.md`:

- `/about.md` for a node aliased to `/about` (nested aliases such as `/a/b/c.md` work too)
- `/node/5.md`, and the underlying route `/node/5/md`

The response is `text/markdown; charset=utf-8`. It starts with `# <title>`, a
`Source:` line with the absolute canonical URL, a `Last updated:` date, then the
body converted from the node's `default` display, rendered as an anonymous
visitor. Eligible pages also advertise it with
`<link rel="alternate" type="text/markdown" href="...md">` in the page head.

## When a page is served

All of these must hold, otherwise the response is a 404:

1. The "Markdown version" setting is on (`ai_readability.markdown_enabled` in
   `ys_core.site`, on by default).
2. The node is the default revision.
3. `ys_beacon.indexability` accepts it: published, viewable by anonymous, and not
   opted out with the "Exclude from AI feeds and markdown" metatag.
4. The node has no filled `field_external_source` (those pages redirect away),
   unless it is a `resource` node, which does not redirect.
5. CAS forced login does not cover it. When `cas.settings:forced_login.enabled`
   is on, the node's alias, its `/node/N` path and the path requested are checked
   against the forced-login page list (case-insensitive, honoring "negate").

The 404 carries the cache tags of the setting, CAS config and the node, so
changing any of them takes effect without a cache clear.

## Conventions for component authors

The HTML is cleaned before conversion, so the Markdown is only as good as
the markup a template renders. When you build or change a component:

- Use semantic markup: real headings (`h2` to `h6`), real lists, `figure`
  and `figcaption` for captioned images, and `table` for tabular data. Give
  informative images an `alt` text and decorative images an empty `alt=""`.
- Add the attribute `data-markdown-skip` to any element that is page
  chrome. It is left out of the Markdown with everything inside it.
- Content marked `aria-hidden="true"` is dropped. Content hidden from screen
  readers is decorative, so AI readers skip it too.
- `script`, `style`, `noscript`, `template`, `nav` and `button` are
  removed. A `form` is replaced by one line, "Interactive form: available
  on the web page.", so a form never vanishes without a trace. The
  exception is a Views exposed filter form (class `views-exposed-form`),
  which is chrome and is removed silently. Links to `data:` URLs (such as
  calendar downloads) are removed with their text.
- An `iframe` becomes a line "Embedded content: <title>" linking to its
  source. The `title` attribute is the link text, so always set it. A
  Drupal media `/media/oembed` proxy URL is replaced by the video URL
  behind it.
- A listing (a Views block wrapped in an element with the class `ys-view`)
  keeps its first 50 items. When items were cut, or the listing has a
  pager, the line "More items are listed on the web page." follows it.
- A list that opens a list item, even inside wrapper elements (such as a
  card's category list), is written as one comma-separated line.
- Do not convey meaning by colour or position alone. Markdown has neither.

## Component audit

| Category | Blocks | Result |
|---|---|---|
| Text and callouts | text, callout, quote_callout, pull_quote, wrapped_text_callout, inline_message, facts | Clean |
| Banners | grand_hero, cta_banner, image_banner, video_banner | Clean; the video banner links the video |
| Cards and grids | reference_card, custom_cards, tiles, content_spotlight, content_spotlight_portrait, link_grid, quick_links, button_link | Clean |
| Media | image, wrapped_image, gallery, media_grid, video | Clean; alt text kept; video links the source |
| Embeds | embed | Social posts kept as quotes; iframes become links |
| Interactive | accordion, tabs | Headings and panels kept in order |
| Forms | webform, event_calendar | Fallback line |
| Listings | view, post_list, event_list, resource_view, directory | First page, capped at 50, "more" line, category lists flattened |
| Navigation and chrome | breadcrumbs, menus, pagers, buttons, divider | Dropped |

## Query strings

The Markdown never varies by query string. The page is rendered with an empty
query, so an embedded listing shows its first page, and the `url.query_args`
cache contexts are dropped. `/about.md?page=2` is the same document as
`/about.md`.

## Caching

The built document is cacheable for at most an hour even if an embedded
listing reports max-age 0, matching ys_beacon's content feed; content changes
still invalidate it at once through cache tags.
