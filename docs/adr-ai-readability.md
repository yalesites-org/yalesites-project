# ADR: AI readability, markdown output and crawl controls

Status: proposed, for review
Spike: yalesites-org/YaleSites-Internal#1711
Epic: yalesites-org/YaleSites-Internal#1717
Date: 2026-10-01

Paths below are relative to `web/profiles/custom/yalesites_profile/` unless they start with `web/`, `composer.json`, or `patches/`. Anything marked **unverified** was not confirmed against code or a primary source during this spike.

## Recommended approach (TL;DR)

1. Build one new small module. It renders each node's existing `default` view as the anonymous user (the way Beacon already does), filters the HTML, and converts it to markdown with `league/html-to-markdown`, which is already installed. No new Composer dependency for conversion.
2. Do not add an "ai_markdown" view mode per component. Layout Builder overrides and inline blocks store their own view mode, so a new view mode would not reach existing pages.
3. Serve markdown at `<page path>.md`, advertise it with `<link rel="alternate" type="text/markdown">`, and skip Accept-header negotiation for now.
4. Store the toggle as `ai_readability.enabled` (default on) in `ys_core.site`, in the "Search and analytics" group of Manage Settings. It gates the `.md` route, the alternate link, `/llms.txt`, and an AI block in `/robots.txt`.
5. Use robots.txt (RFC 9309) for allow/deny and `/llms.txt` (a proposal) for discovery. Do not build agents.txt. Serve robots.txt dynamically via the `robotstxt` contrib module (45,596 sites), which needs one human approval.

## Decisions

| # | Question | Decision |
|---|---|---|
| 1 | Reuse from Beacon | Reuse `BeaconIndexability` rules and the `ContentFeedBuilder` anonymous-render and caching pattern. Do not reuse `MarkdownConverter`. Write a new public filter instead of reusing `IndexableHtmlFilter` as is. |
| 2 | Rendering | Render the node `default` view as anonymous, filter HTML, convert to markdown. No per-component view modes. |
| 3 | URL | `<alias>.md` and `/node/N.md`, plus the `rel="alternate"` link. No Accept negotiation or `?_format` in v1. |
| 4 | Setting | `ys_core.site` key `ai_readability.enabled`, default TRUE, in "Search and analytics". |
| 5 | Directives | robots.txt for rules, `/llms.txt` for discovery. Skip agents.txt, ai.txt, and Content-Usage for now. |
| 6 | Dependencies | `league/html-to-markdown` 5.1.2 already installed. Add `robotstxt` contrib only. |

## 1. Audit: what is reusable

**Decision: reuse the indexability rule and the anonymous-render pattern. Write a new public filter and a new markdown service. Leave `MarkdownConverter` and `ai_search` alone.**

### What each piece does

- `ys_beacon` `MarkdownConverter` (`modules/custom/ys_beacon/src/Service/MarkdownConverter.php`, class at line 25) is not a page converter. It converts Beacon system instructions between Markdown and the `restricted_html` editor format. `ALLOWED_TAGS` (line 34) limits it to `a br p strong em sub sup`. `downgradeHeadings()` (line 163) and `flattenLists()` (line 185) deliberately turn headings into bold paragraphs and list items into paragraphs. That destroys structure we need. Its `toMarkdown()` (line 97) runs `Xss::filter` with those tags first. **Not reusable.** The epic text (yalesites-org/YaleSites-Internal#1717) assumes it is; that assumption is wrong.
- `ys_beacon` `IndexableHtmlFilter` (`modules/custom/ys_beacon/src/Service/IndexableHtmlFilter.php`, class line 141, `filter()` line 242) takes rendered HTML and deletes non-content. It is registered as `ys_beacon.indexable_html_filter` in `modules/custom/ys_beacon/ys_beacon.services.yml` and used by the Search API processor `IndexableHtml` (`modules/custom/ys_beacon/src/Plugin/search_api/processor/IndexableHtml.php`, `process()` line 132). It outputs HTML, not markdown. Its tuning is for a vector index, not a public reader:
  - `REMOVED_TAGS` (line 155) deletes `img`, `figure`, `iframe`, `embed`, `video`, `audio`, `svg`, `nav`, `button`, `form`. Image alt text, captions, and embeds are lost. Yalesites-org/YaleSites-Internal#1712 requires embeds to get a text fallback, so this list is wrong for public output.
  - `removeSelfTitleHeadings()` (line 369) drops the page's own title heading. A public markdown file needs the title as `# H1`.
  - `linkAnchoredHeadings()` (line 465) rewrites headings into deep links for chat citations. Not needed.
  - Reusable ideas: the structural passes (`removeTextlessElements()` line 567, `unwrapUnsafeLinks()` line 611, `unwrapDisclosureHeadingButtons()` line 288 for accordion headings, the `BLANK_TEXT_PATTERN` constant line 222). Reusable only by copy or by refactoring `REMOVED_TAGS` into a parameter. Refactoring a service that feeds the live Beacon index is a regression risk, so the recommendation is a new service in the new module that copies the passes it needs. Revisit sharing once both exist.
- `ai_search` (`web/modules/contrib/ai/modules/ai_search/`): `EmbeddingStrategyPluginBase.php` lines 106-108 set `strip_tags` and `strip_placeholder_links` on a `League\HTMLToMarkdown\HtmlConverter` and add `TableConverter`; `EmbeddingBase::getValue()` (`Plugin/EmbeddingStrategy/EmbeddingBase.php` line 403) calls `$this->converter->convert()` on each Search API field value. The conversion is bound to Search API field values and chunking. **Not callable for public output.** What is reusable is the configuration recipe (the same library, `TableConverter`). Note `strip_tags: TRUE` there would drop any HTML that has no markdown form, which is acceptable for us once the filter has run.
- Search API config for Beacon (`config/sync/search_api.index.ys_beacon.yml`): the `rendered_item` field renders with `roles: anonymous` (lines 50-71) and the `default` view mode for all node bundles. Processors `ys_beacon_exclude_ai_disabled` and `ys_beacon_indexable_html` are enabled (lines 106-112).

### Reusable for public output

- `BeaconIndexability::isIndexable()` (`modules/custom/ys_beacon/src/Service/BeaconIndexability.php` line 39): published, `access('view', new AnonymousUserSession())`, and not opted out by the `ai_disable_indexing` metatag (plugin at `modules/custom/ys_beacon/src/Plugin/metatag/Tag/AiDisableIndexing.php`). This is the right gate for public output too. The access check covers pages restricted by node grants. `field_login_required` is enforced that way: `ys_node_access_node_access_records()` gives login-required published nodes the private grant, everyone else the public grant (`modules/custom/ys_node_access/ys_node_access.module` lines 92-106), so the anonymous check returns FALSE for them. It does NOT cover CAS forced login by path; see trust boundary gap 5.
- `ContentFeedBuilder` (`modules/custom/ys_beacon/src/Service/ContentFeedBuilder.php`): switches to `AnonymousUserSession` with `AccountSwitcher` (line 140), renders with `renderInIsolation()` (`renderContent()` line 254), collects cache tags and contexts, and strips `user` and `session` contexts with `collectableCacheContexts()`. Copy the pattern; do not call it. `renderContent()` currently ends in `strip_tags` and whitespace collapse, so it yields plain text, not markdown.
- Libraries: see section 6.

### Must be new

- A public markdown service: anon render of `default`, a public-output filter, HtmlConverter, and a page-level wrapper (title H1, canonical URL, last-changed date).
- The route, controller, `.md` path processor, alternate link, and `llms.txt` and `robots.txt` output.
- The setting and its gate.

### Trust boundary

- Beacon's index is private, but the Beacon pipeline already renders as anonymous (`search_api.index.ys_beacon.yml` `roles: anonymous`; `ContentFeedBuilder` line 140) and filters through `BeaconIndexability`. Nothing in the pipeline I read relies on an admin render context. Good.
- Gaps for public output:
  1. The Beacon feed is gated by the Beacon authorization flag (`BeaconAuthorization::isAuthorized()`, `modules/custom/ys_beacon/src/BeaconAuthorization.php` line 50; `platform_authorized: false` in `modules/custom/ys_beacon/config/install/ys_beacon.settings.yml`). Public output must not depend on it, so the new module must not require `ys_beacon` to be authorized. It may still reuse the indexability class; see open question 2.
  2. The Beacon feed rate-limits per IP (`ContentFeedController::FLOOD_LIMIT` = 120, `modules/custom/ys_beacon/src/Controller/ContentFeedController.php` line 57) because it renders up to 200 nodes per request. A per-node `.md` request renders one page, so cache is the main control. Flood limiting is probably not needed; **unverified** against real load.
  3. Revisions. The route must serve only the default (published) revision, never a latest draft revision under content moderation. A node loaded by route parameter is the default revision; confirm in a test. **Unverified.**
  4. Views embedded in layouts (Content List blocks) vary by query string. `ContentFeedBuilder` documents this at `collectableCacheContexts()`; the `.md` response must not vary by caller query args, so render with a clean request or add `url.query_args` and accept cache fragmentation. Decide in yalesites-org/YaleSites-Internal#1713.
  5. CAS forced login by path. Forced login is configured per site as page patterns (`cas.settings:forced_login.enabled`, `forced_login.paths.negate` and `forced_login.paths.pages` are listed in `ignored_config_entities` in `config/sync/config_ignore.settings.yml` lines 6-8, so they differ per site and are not in code). `CasForcedAuthSubscriber` (`web/modules/contrib/cas/src/Subscriber/CasForcedAuthSubscriber.php` line 89) builds core's `request_path` condition, whose `evaluate()` (`web/core/modules/system/src/Plugin/Condition/RequestPath.php` lines 155-170) compares the pattern against the current internal path (`CurrentPathStack`) and that path's alias. If an inbound processor rewrites `/private/page.md` to an internal markdown route such as `/node/5/md`, a pattern like `/private/*` matches neither the internal path nor its alias, so the subscriber never forces login. An anonymous visitor could then read a forced-login page as markdown, and `/llms.txt` could list it. `BeaconIndexability` does not catch this because the node itself is not grant-restricted. **Required for yalesites-org/YaleSites-Internal#1713:** evaluate the site's forced-login patterns against the original aliased request path (the one before the `.md` rewrite, for example by running the same `request_path` condition on a request path with `.md` removed, or by reading `forced_login.paths` directly), return 403 or a CAS redirect for matches, and exclude matches from `/llms.txt`. Add a test with a forced-login pattern and a page it matches that is not marked `field_login_required`.
  6. External-source nodes. `ExternalSourceRedirectSubscriber::onKernelView()` only acts when the route is `entity.node.canonical` (`modules/custom/ys_core/src/EventSubscriber/ExternalSourceRedirectSubscriber.php` line 55; it skips the `resource` bundle). A `.md` route is a different route, so it would serve the stub node instead of redirecting. **Required:** the `.md` route and `/llms.txt` must skip nodes with a populated `field_external_source` (404) or redirect to the external URL, matching the HTML behavior; the `resource` bundle keeps its own page, so it is served. Login-required behavior is verified via node grants (see section 1, reusable list).

## 2. Rendering mechanism

**Decision: render the node `default` view as anonymous, filter, convert to markdown. Do not create per-component AI view modes.**

### How the site renders content (verified)

- Node pages use Layout Builder with per-node overrides: `config/sync/core.entity_view_display.node.page.default.yml` lines 27-29 (`enabled: true`, `allow_custom: true`); the same file appears for event, post, profile, resource.
- Page components are inline blocks (`inline_block:cta_banner`, `inline_block:embed`, and so on, same file line 118 onward), backed by `block_content` bundles. Paragraphs appear as children of blocks (for example `paragraph.accordion_item`, `paragraph.tab`; displays listed in `config/sync/`).
- Core `InlineBlock` stores a `view_mode` in each placed block's configuration and defaults it to `full` (`web/core/modules/layout_builder/src/Plugin/Block/InlineBlock.php` lines 117 and 219). That value is saved inside each node's layout. Changing view mode config later does not rewrite saved layouts.
- Overrides apply to a view mode only if that mode's display is overridable. `OverridesSectionStorage::isApplicable()` requires `isOverridable() && isOverridden()` (`web/core/modules/layout_builder/src/Plugin/SectionStorage/OverridesSectionStorage.php` lines 396-405). `isOverridable()` requires Layout Builder enabled and `allow_custom` (`web/core/modules/layout_builder/src/Entity/LayoutBuilderEntityViewDisplay.php` lines 54-56). The display form only offers `allow_custom` on the canonical `full` mode (`web/core/modules/layout_builder/src/Form/LayoutBuilderEntityViewDisplayForm.php` lines 85-87 and 135-140, `isCanonicalMode()` returns TRUE for `full`; the `default` display also carries it in this repo's config). So a new `ai_markdown` view mode would need Layout Builder enabled and `allow_custom` set by hand in config, and would still render each placed inline block at its stored `view_mode: full`. Without that, it renders only plain fields and misses the whole body. `getSectionList()` (same OverridesSectionStorage file, lines 134-136) reads the entity's own field, so the stored layout itself is not view-mode specific; applicability is.

### Options

| Option | Pros | Cons |
|---|---|---|
| A. Render `default` as anonymous, filter HTML, convert (chosen) | Works for every existing and future layout today. Same render Beacon indexes (`search_api.index.ys_beacon.yml`), so output matches what is on the page. One code path. | Output quality depends on how semantic each component's HTML is. Chrome must be filtered by rule, not by design. |
| B. New `ai_markdown` view mode for each node type, block_content bundle, and paragraph type | Clean per-component control in theory. | Dozens of display configs to create and keep in sync (see `core.entity_view_display.block_content.*` in `config/sync/`). Placed inline blocks keep `view_mode: full` in saved layouts, so they would not switch unless we add an alter hook or rewrite every layout (hook behavior **unverified**). Each new node display needs Layout Builder enabled and `allow_custom` set by hand, or the stored layouts are ignored. High maintenance. |
| C. Render the full themed page and strip site chrome | Simple idea. | Pulls in header, footer, search, banners, cookie notices. Strictly more noise than A. |

Trade-off accepted: A trades per-component authorship for coverage. The component contract in yalesites-org/YaleSites-Internal#1712 becomes a set of HTML conventions (semantic markup, alt text kept, a skip marker for chrome, text fallback for embeds) enforced by the filter, not a set of new Twig templates.

Known risk: the `default` render of some bundles may contain pager or listing markup from Views blocks. Those are content-bearing but unbounded; define a cap or a "listing" fallback in yalesites-org/YaleSites-Internal#1712.

## 3. URL and route convention

**Decision: `<page path>.md` (alias or `/node/N`), with `<link rel="alternate" type="text/markdown" href="...md">` in each HTML page head. No Accept negotiation or `?_format=markdown` in v1.**

| Option | Pros | Cons |
|---|---|---|
| `.md` suffix (chosen) | Matches the llms.txt proposal ("`.md` appended", https://llmstxt.org/). A distinct URL gets its own page-cache and edge-cache entry, with no `Vary` problem. Easy for agents to guess. | Needs an inbound path processor that rewrites the path to a dedicated internal markdown route. The front page alias `/` has no natural `.md` form. |
| `?_format=markdown` | Core format negotiation exists. | Needs `_format` route requirements on entity routes; the query string fragments caches; hard to discover. |
| `/node/N/markdown` | Simple route. | Ignores aliases; agents cannot guess it from the page URL; exposes node IDs. |
| `Accept: text/markdown` | Same URL as the page. | Requires `Vary: Accept` on every HTML page, which touches Drupal page cache and the Pantheon edge for the whole site. Edge behavior with `Vary: Accept` is **unverified**. Few crawlers send it. Defer. |

Details:

- Path aliases come from pathauto patterns in `config/sync/pathauto.pattern.*.yml`; page alias is `/[node:menu-link:parent:url:relative]/[node:title]` (`pathauto.pattern.page.yml`), so aliases can be nested. The inbound processor must rewrite `<alias>.md` to a dedicated internal route such as `/node/{nid}/md`, not merely strip `.md`: stripping lands on `entity.node.canonical` and serves HTML. It resolves the stripped path through the alias manager, confirms the target is a node, and must run with a priority that lets it see the alias before or in coordination with core's alias processor (core's `path_alias.path_processor` runs inbound at its own priority; exact ordering and the chosen number are **unverified** and belong in yalesites-org/YaleSites-Internal#1713, with a test that nested aliases resolve). The rewrite also hides the original path from path-based rules such as CAS forced login (see trust boundary gap 5). An alias that genuinely ends in `.md` is rare; verify none exists in the pilot.
- Front page: serve at `/index.md`. See open question 4.
- Caching: return a cacheable response with the node's cache tags, `config:ys_core.site` (so the toggle takes effect without a cache clear), and a `Content-Type: text/markdown; charset=utf-8` header. The profile already includes `pantheon_advanced_page_cache` (`composer.json` line 90 of the profile) for edge tags. Site page max-age is 86400 (`config/sync/system.performance.yml` line 5). Whether Pantheon's edge caches a `.md` extension like a page, or treats it as a static file, is **unverified**; test on the pilot site.
- Discovery: add the alternate link via `hook_page_attachments` or the existing metatag module (`drupal/metatag` 2.2.0, profile `composer.json` line 80); the link must be omitted when the setting is off or the node is not eligible. Which mechanism is simpler is left to yalesites-org/YaleSites-Internal#1713.

## 4. Settings data model

**Decision: add `ai_readability.enabled` (boolean, default TRUE) to the existing `ys_core.site` config object. Surface it as a checkbox in the "Search and analytics" group on `/admin/yalesites/settings`.**

Where settings live today:

- `SiteSettingsForm` (`modules/custom/ys_core/src/Form/SiteSettingsForm.php`) edits `ys_core.site` and `system.site` (`getEditableConfigNames()` line 148). Route `ys_core.admin_site_settings` at `/admin/yalesites/settings` needs the `yalesites manage settings` permission (`modules/custom/ys_core/ys_core.routing.yml` lines 10-16).
- The form uses grouped vertical tabs. The "Search and analytics" details group (line 376) is described as "How search engines verify the site and how visits are measured" and already holds `google_site_verification`. Submit writes keys one by one with `->set('seo.google_site_verification', ...)` (lines 564-573).
- Defaults: `modules/custom/ys_core/config/install/ys_core.site.yml`. The exported `config/sync/ys_core.site.yml` has a different key set from the install file (sync lacks `custom_favicon` and `environment_indicator` and has a `search` key that install lacks), so code already cannot assume a key exists.
- `ys_core.site` has no entry in `modules/custom/ys_core/config/schema/ys_core.schema.yml` (the file declares only `ys_core.dashboard_settings`). I found no schema for it elsewhere. **Unverified** beyond that search. Adding a schema for only the new key would be incomplete; adding the full schema is a separate decision (open question not needed; note for yalesites-org/YaleSites-Internal#1715).

Proposed key:

```yaml
# ys_core.site
ai_readability:
  enabled: true
```

- Code reads `$config->get('ai_readability.enabled') ?? TRUE`, so sites that never saved the form are on by default, with no deploy hook or config import needed.
- Add the key to `modules/custom/ys_core/config/install/ys_core.site.yml` for new installs only. Do not rely on `config/sync/ys_core.site.yml`: `ys_core*` is in `ignored_config_entities` (`config/sync/config_ignore.settings.yml` line 22), so config import does not change `ys_core.site` on existing sites. The `?? TRUE` read is what makes the default work there.
- Gates (all off when FALSE): the `.md` route returns 404; the alternate `<link>` is omitted; `/llms.txt` returns 404; `/robots.txt` gets the AI-agent block (see section 5 and open question 1). The 404s must carry the `config:ys_core.site` tag too (throw `CacheableNotFoundHttpException` with that cacheability), or a cached 404 survives turning the setting back on. Test note for yalesites-org/YaleSites-Internal#1713: fetch `.md` with the setting off, turn it on, fetch again without a cache clear, expect 200; and the reverse.
- Real-time effect: each `.md` and `/llms.txt` response (including the 404s) carries cache tag `config:ys_core.site`, so saving the form invalidates them, with no cache clear or deploy. The same is NOT yet true for `/robots.txt`: see section 5 and the acceptance criterion for yalesites-org/YaleSites-Internal#1714.
- Alternatives considered: a new `ys_ai_readability.settings` config object (cleaner ownership, but a second place for admins to look, and a new route and form); a platform-admin-only setting via `PlatformAdminSettingInterface` (`modules/custom/ys_core/src/PlatformAdminSettingInterface.php`). Rejected because yalesites-org/YaleSites-Internal#1715 wants site owners to control it.
- UI copy: label like "Let AI tools read this site"; help text in plain words ("When on, AI assistants can fetch a simple text version of each public page. Turn off to ask them not to."). Final wording belongs to yalesites-org/YaleSites-Internal#1715.

## 5. Format for sitewide AI-crawler directives

**Decision: use robots.txt for rules and `/llms.txt` for discovery. Do not build agents.txt, ai.txt, or Content-Usage yet.**

### What exists (researched 2026-10-01)

| Convention | Status | Adoption evidence |
|---|---|---|
| robots.txt | Real standard: RFC 9309, IETF Standards Track, September 2022 (https://www.rfc-editor.org/rfc/rfc9309.html). | Honored by named AI crawlers. Anthropic states ClaudeBot, Claude-User and Claude-SearchBot honor it (https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler). OpenAI states GPTBot and OAI-SearchBot honor it but ChatGPT-User, a user-initiated fetcher, may not (https://developers.openai.com/api/docs/bots). |
| llms.txt | Community proposal by Jeremy Howard, 2024 (https://llmstxt.org/). Not a standards body document. It also recommends `.md` page versions and `rel="alternate" type="text/markdown"`, matching section 3. | Published by some AI vendors and documentation platforms. Consumption is weak: third-party reports say Google does not use it and that most files get zero requests (an Ahrefs study of 137,210 domains in May 2026, as summarized by blogs such as https://geojacker.com/llms-txt and https://almcorp.com/news/google-llms-txt-no-effect-rankings/; I did not read the Ahrefs study itself, so treat the figure as secondary). |
| IETF aipref (Content-Usage) | Active IETF working group, Internet-Draft `draft-ietf-aipref-vocab-08`, not an RFC, and the draft says it does not reflect working group consensus (https://datatracker.ietf.org/doc/html/draft-ietf-aipref-vocab-08). Syntax example `train-ai=y, search=n`. | No crawler support confirmed in this spike. |
| agents.txt | Individual proposals. One Internet-Draft, `draft-car-agents-txt-wellknown-00`, reportedly expired in April 2026 with no working group (https://dev.to/globalchatapp/the-agentstxt-ietf-draft-just-expired-here-is-what-happens-next-1kgp, https://github.com/kaylacar/agents-txt). Several unrelated repos use the same name. | No crawler support found. |
| ai.txt | Individual draft `draft-car-ai-txt-wellknown-00` (https://www.ietf.org/archive/id/draft-car-ai-txt-wellknown-00.html). | No crawler support found. |

Reasoning:

- Only robots.txt is a real standard that AI crawlers say they honor, so any "opt out" must be expressed there. When the setting is off, add `User-agent:` blocks with `Disallow: /` for named AI tokens (GPTBot, OAI-SearchBot, ClaudeBot, Claude-SearchBot, and others confirmed in 1714). Robots.txt is a request, not enforcement, and user-initiated fetchers such as ChatGPT-User may ignore it. The docs ticket yalesites-org/YaleSites-Internal#1716 must say so.
- llms.txt has little proven effect but costs little once `.md` endpoints exist, and it satisfies the acceptance criterion in yalesites-org/YaleSites-Internal#1714 to follow an external convention. Ship a minimal one: H1 site name, a one-line summary, and a list of links to key `.md` pages. Do not promise any ranking or citation benefit.
- agents.txt and ai.txt have no standards body and no observed consumers. Content-Usage is the likely future standard; revisit when it reaches RFC or a crawler announces support. Adding a one-line `Content-Usage` rule later is cheap.

### How robots.txt is served here (verified)

- `web/robots.txt` is generated, not tracked: `web/.gitignore` line 11 ignores it. Root `composer.json` lines 82-83 append `patches/robots.txt` (a single `Disallow: /*?page=`) to the core scaffold file.
- The `robotstxt` module is not installed (`web/modules/contrib/` has no such directory; not in the profile `composer.json`).
- A static `web/robots.txt` is served by the web server before Drupal runs, so a toggle cannot change it without a regeneration step. That fails the acceptance criterion in yalesites-org/YaleSites-Internal#1714 ("no manual regeneration").

### Options for serving a dynamic robots.txt

| Option | Pros | Cons |
|---|---|---|
| `drupal/robotstxt` contrib (recommended) | 45,596 reporting sites, supports Drupal 10 and 11, stable release 8.x-1.6 (August 2024), covered by the security advisory policy, six maintainers (https://www.drupal.org/project/robotstxt). Meets the "widely used, maintained" bar. | New dependency. The static file must be removed from the scaffold (set `"[web-root]/robots.txt": false` in root `composer.json` `file-mapping`) and the current content moved into config. Its hook for appending dynamic lines (`hook_robotstxt`) is from memory and **unverified**; confirm in yalesites-org/YaleSites-Internal#1714. It may not attach `config:ys_core.site` to its response (**unverified**, module not installed), in which case the toggle could stay stale for up to the page max-age of 86400 seconds plus edge cache. If it cannot be made to, the custom route wins. |
| Own route `/robots.txt` in the new module | No new dependency. | Same scaffold removal; we must carry core's default rules ourselves. |
| Keep static file, add only static AI rules | Zero work. | Cannot reflect the toggle. Fails the acceptance criteria. |

`/llms.txt` needs no module: a route in the new module, built from the main menu or `simple_sitemap` (4.2.3, profile `composer.json` line 106; whether its API can list entries cheaply is **unverified**).

## 6. Dependencies

**Decision: no new conversion dependency. Add only `drupal/robotstxt` if approved.**

- `league/html-to-markdown` `^5.1` and `league/commonmark` `^2.0` are required in root `composer.json` lines 52-53. Locked versions are 5.1.2 and 2.10.3 (`composer.lock` lines 11075 and 10804; `vendor/league/html-to-markdown` and `vendor/league/commonmark` are present). `drupal/ai` also requires `league/html-to-markdown` (`web/modules/contrib/ai/composer.json` line 7) and suggests commonmark (line 23).
- HTML to markdown needs only `html-to-markdown`; `commonmark` goes the other way (markdown to HTML) and is not needed.
- Note: the profile `composer.json` does not list either library, so a profile module relies on them coming from the root. Pin them in the profile `composer.json` when the module lands, per the project rule that the profile owns its dependencies. Flag for yalesites-org/YaleSites-Internal#1713.
- `drupal/robotstxt`: see section 5 for usage evidence.

## Impact on child tickets

- **yalesites-org/YaleSites-Internal#1712 (component contract).** Scope change, size likely drops from XL. Under decision 2 the contract is an HTML convention plus the public filter's rules, not new Twig per component: semantic markup, alt text and captions kept, a skip marker for chrome (nav, carousel and modal controls), a text fallback for embeds, and a list of components that need handling. New risk: the audit (which components convert cleanly) is still real work and is the main unknown. It is no longer a hard blocker for yalesites-org/YaleSites-Internal#1713, because the first release can ship with default conversion and improve per component.
- **yalesites-org/YaleSites-Internal#1713 (per-node markdown).** Builds the new module, service, route, `.md` path processor, alternate link, and cache metadata. Acceptance criteria stay valid. Add: pin `league/*` in the profile `composer.json`, serve only the default revision, decide the front page URL, decide query-string behavior for embedded views, and test login-required and external-source nodes. Required criteria: exclude CAS forced-login path matches (evaluated against the original aliased path) and `field_external_source` nodes from `.md` and `/llms.txt` (trust boundary gaps 5 and 6), and give the 404s the `config:ys_core.site` cache tag. The epic's order (1712 before 1713) can be relaxed.
- **yalesites-org/YaleSites-Internal#1714 (directives).** Scope change: replace "agents.txt / robots.txt" with "robots.txt plus `/llms.txt`". Needs `robotstxt` module approval and a root `composer.json` scaffold change. **Acceptance criterion to add:** the `/robots.txt` response is invalidated by `config:ys_core.site`, proven by toggling the setting and refetching with no cache clear. If `robotstxt` cannot do that, use a custom route in the new module. New risk: removing the scaffold robots.txt changes how every site serves it; test on the pilot. The ticket title mentions agents.txt, which this ADR rejects.
- **yalesites-org/YaleSites-Internal#1715 (setting).** Key and location are now defined (section 4). Add: the AI block in robots.txt as a second gate, and the schema question. The "no cache clear" criterion is met for `.md` and `/llms.txt` by the `config:ys_core.site` cache tag. For `/robots.txt` it depends on the criterion added to yalesites-org/YaleSites-Internal#1714. Existing sites get the default through `?? TRUE`, not through config import (`ys_core*` is config-ignored).
- **yalesites-org/YaleSites-Internal#1716 (docs).** Must state that the setting is a request to AI tools, not a lock, and that tools that fetch on a user's behalf may ignore robots.txt. Wording still needs a final setting name from yalesites-org/YaleSites-Internal#1715.

## Open questions for review

1. When the setting is off, should `/robots.txt` also disallow the named AI crawlers (training and search), or only stop serving markdown and `llms.txt`? Recommended: also disallow, because "turn off AI crawling" in yalesites-org/YaleSites-Internal#1715 implies it. This is a policy call about how strongly Yale speaks for the site owner.
2. Should the per-page `ai_disable_indexing` metatag (already used by editors for Beacon) also hide a page from public markdown? Recommended: yes, since it is free and matches editor expectations. The epic lists per-page overrides as follow-up work, so this widens scope slightly. If yes, the new module depends on `ys_beacon` for the metatag plugin, or the plugin moves to a shared module.
3. Is adding `drupal/robotstxt` and removing the scaffolded `web/robots.txt` acceptable, versus a custom `/robots.txt` route with no new dependency?
4. What URL should the front page markdown use (`/index.md` recommended)?
5. Default on means every existing site starts serving `.md` pages and `/llms.txt` on the next deploy with no owner action (the `?? TRUE` read, and config import does not touch `ys_core.site`). Opt-out (default on) or opt-in (default off, owners switch it on)? Recommended: opt-out, as yalesites-org/YaleSites-Internal#1715 specifies, because the content is already public HTML, the output is limited to what an anonymous visitor can see, and an opt-in default would leave the feature unused on almost every site. The cost if wrong: owners who did not want AI tools reading their site find out after the fact. Mitigation: a dashboard or release-note notice before the deploy, and the 1716 docs page published first.
