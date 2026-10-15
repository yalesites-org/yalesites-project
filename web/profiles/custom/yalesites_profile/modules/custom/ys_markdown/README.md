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
  readers is decorative, so AI readers skip it too. The exception is an
  `img` with a non-empty `alt` inside it (such as a card's image link, which
  repeats the title link): the image is kept and the link around it is not.
- `script`, `style`, `noscript`, `template`, `nav` and `button` are
  removed. A `form` is replaced by one line, "Interactive form: available
  on the web page.", so a form never vanishes without a trace. The
  exception is a Views exposed filter form (class `views-exposed-form`),
  which is chrome and is removed silently. An events calendar form also gets
  the line, followed by a list of its events (`li.calendar-event`), each
  with its title link, its day and its time. Links to `data:` URLs (such as
  calendar downloads) are removed with their text.
- An `iframe` becomes a line "Embedded content: <title>" linking to its
  source. The `title` attribute is the link text, so always set it. A
  Drupal media `/media/oembed` proxy URL is replaced by the video URL
  behind it. An `iframe` with `data-embed-type="form"` and no http(s)
  source (such as a Microsoft Form) becomes the form line instead.
- A listing is an element with the class `ys-view` (Views blocks),
  `ys-resource-view` (resource views) or `card-collection` (the base class of
  reference-card, post, event, resource, profile and Directory collections),
  outside any other listing. It keeps its first 50 items. When
  items were cut, or the listing has a pager, the line "More items are
  listed on the web page." follows it.
- A list that opens a list item, even inside wrapper elements (such as a
  card's category list), is written as one comma-separated line. In a list
  item, the first heading moves ahead of any text before it, so a card's
  title comes before its category line (unless an image comes first).
- Do not convey meaning by colour or position alone. Markdown has neither.

## Component audit

| Category | Blocks | Result |
|---|---|---|
| Text and callouts | text, callout, quote_callout, pull_quote, wrapped_text_callout, inline_message, facts | Clean |
| Banners | grand_hero, cta_banner, image_banner, video_banner | Clean; the video banner links the video |
| Cards and grids | reference_card, custom_cards, tiles, content_spotlight, content_spotlight_portrait, link_grid, quick_links, button_link | Clean; a card's title comes first, then its category or department line |
| Media | image, wrapped_image, gallery, media_grid, video | Clean; alt text kept (also in aria-hidden card image links); video links the source |
| Embeds | embed | Social posts kept as quotes; iframes become links; Microsoft Forms become the form line |
| Interactive | accordion, tabs | Each panel starts with its tab label as a heading (one level below the nearest heading before the tabs, h3 if none); the `#tab-` link list is dropped |
| Forms | webform, event_calendar | Fallback line; the events calendar also lists its events |
| Listings | view, post_list, event_list, resource_view, directory | Views blocks, resource views, all card-collection listings: first page, capped at 50, "more" line, category lists flattened |
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

## robots.txt and llms.txt

`/llms.txt` is served by Drupal (`AiDirectivesController`). `/robots.txt` is
served by the contrib `robotstxt` module (a dependency of this module); the
scaffold no longer writes `web/robots.txt`. A local checkout that already has
that file must delete it, or the web server serves the file instead of Drupal.

- `/robots.txt` is the content of the textarea at `/admin/config/search/robotstxt`,
  seeded from core's scaffold `robots.txt` (`robotstxt.settings` in the profile
  config; on existing sites the deploy hook `ys_markdown_deploy_10001()`
  does it, unless the textarea was already edited). That setting is in `config_ignore.settings.yml`, so each site's edits
  survive `drush deploy`. `ys_markdown_robotstxt()` (`hook_robotstxt()`) appends
  the site rule `Disallow: /*?page=` and, when "Block AI crawlers"
  (`ai_readability.block_ai_crawlers`) is on, one group that disallows AI
  training crawlers (`AiDirectivesController::AI_TRAINING_CRAWLERS`): GPTBot,
  ClaudeBot, CCBot, Google-Extended, Applebot-Extended and Meta-ExternalAgent.
  AI search bots (OAI-SearchBot, Claude-SearchBot, PerplexityBot) are not blocked,
  because they fetch pages in order to cite them, which sites want. Saving a
  change to that setting invalidates the `robotstxt` cache tag
  (`RobotsTxtInvalidator`), so toggling takes effect without a cache clear.
- `/llms.txt` follows llmstxt.org: the site name, slogan, and a link to the `.md`
  version of every page that would be served as Markdown, grouped into one
  section per content type (ordered by label). The 200 is fresh for at most an
  hour at the edge, as ys_beacon's feed is. It answers 404 when
  the "Markdown version" setting (`ai_readability.markdown_enabled`) is off.
  It is built by loading every published node on a cache miss.

Both read their setting through `AiReadabilitySettings::isEnabled()` (a missing
key means on). `/llms.txt` carries the `config:ys_core.site` cache tag, so
toggling takes effect without a cache clear.
