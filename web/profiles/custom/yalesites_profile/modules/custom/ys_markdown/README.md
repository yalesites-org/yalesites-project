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

## Leaving page chrome out

The HTML is cleaned before conversion: `script`, `style`, `noscript`,
`template`, `nav`, `button` and `form` are removed, and an `iframe` becomes a
line "Embedded content: <title>" linking to its source. Image alt text, figure
captions and tables are kept. Add the attribute `data-markdown-skip` to any
element in a template to leave it (and everything inside it) out of the Markdown.

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
- `/llms.txt` follows llmstxt.org: the site name, slogan, and an entry for
  every page that would be served as Markdown, as `- [Title](url.md): description`,
  grouped into one section per content type (ordered by label). The description
  is the page's resolved Metatag description (the teaser by default), stripped
  of markup and cut at 200 characters. Removals (unpublished, deleted or
  excluded pages) take effect at once, while new pages and edits show within
  an hour. It answers 404 when the "Markdown version"
  setting (`ai_readability.markdown_enabled`) is off. It is built by loading
  every published node on a cache miss.

Both read their setting through `AiReadabilitySettings::isEnabled()` (a missing
key means on). `/llms.txt` carries the `config:ys_core.site` cache tag, so
toggling takes effect without a cache clear.
