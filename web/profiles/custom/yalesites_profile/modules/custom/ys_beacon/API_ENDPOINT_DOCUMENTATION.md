# Beacon Content Feed API

The Beacon content feed is a read-only JSON endpoint that publishes the site content
Beacon indexes for AI retrieval. It exists so an external system can *pull* that content
instead of relying on Beacon's push-based indexing pipeline.

This document is the full reference. For how the feed fits into the rest of the Beacon
architecture, see the module [README](README.md).

- [Overview](#overview)
- [Endpoint details](#endpoint-details)
- [Authorization: the feed is off until a site opts in](#authorization-the-feed-is-off-until-a-site-opts-in)
- [What the feed contains](#what-the-feed-contains)
- [Request parameters](#request-parameters)
- [Response format](#response-format)
- [Field reference](#field-reference)
- [Pagination](#pagination)
- [Error responses](#error-responses)
- [Rate limits, performance, and polling](#rate-limits-performance-and-polling)
- [Security model](#security-model)
- [Migrating from /api/ai/v1/content](#migrating-from-apiaiv1content)
- [Versioning](#versioning)
- [Implementation reference](#implementation-reference)

## Overview

| | |
|---|---|
| Path | `/api/ys-beacon/v1/content` |
| Method | `GET` |
| Response | `application/json` |
| Authentication | None |
| Authorization | Site must have Beacon authorized (see below), else `403` |
| Rate limit | 120 requests per hour per client IP, else `429` |
| Replaces | `ai_engine_feed`'s `/api/ai/v1/content` (not drop-in compatible) |

The feed applies the same indexability rules as Beacon's vector index, so for **nodes** a
pull consumer and the chatbot see the same corpus. **Media is not equivalent** — see
[What the feed contains](#what-the-feed-contains).

## Endpoint details

```
GET https://yoursite.yale.edu/api/ys-beacon/v1/content
```

**No authentication.** There are no tokens, keys, or headers to send. The route is open to
any caller, authenticated or anonymous, because of how items are built rather than who asks
for them — see [Security model](#security-model).

**`GET` only.** `POST`, `PUT`, `PATCH`, and `DELETE` return `405 Method Not Allowed` as an
HTML error page, not JSON. `HEAD` is the exception: Symfony normalizes it to `GET`, so it
succeeds and returns the headers without a body.

## Authorization: the feed is off until a site opts in

This is the difference most likely to surprise you, because the legacy endpoint had no
equivalent. **The feed returns `403` on every site where a platform administrator has not
authorized Beacon:**

```json
{ "error": "The content feed is not enabled." }
```

Beacon is authorized per site by a platform admin, from the **Platform Admin Settings**
page at `/admin/yalesites/platform-admin-settings`, under the Beacon section: "Allow site
admins to configure and use Beacon". Until that is checked, every Beacon surface on the
site stays inert, this endpoint included.

Two consequences worth planning for:

- **A `403` is not a permissions problem you can fix from the client side.** No credential
  will change it. It means Beacon is not turned on for that site, and it needs a platform
  admin, not a different request.
- **The setting is per-site and is not propagated by configuration deployments** (the flag
  is in the `config_ignored` list). A site being authorized in one environment tells you
  nothing about another environment of the same site.

Treat `403` as "this site does not participate," and skip it, rather than as a transient
error to retry.

## What the feed contains

The feed lists **published content that an anonymous visitor could already read**, and
nothing else. Every item is built while the request is switched to the anonymous user, and
each candidate must pass the same indexability check the vector index uses. An item appears
only if all of the following hold:

1. It is **published**.
2. It is **viewable by an anonymous visitor** — anything behind CAS or restricted by node
   access is excluded.
3. It has **not been opted out** of AI indexing via the `ai_disable_indexing` metatag
   ("Disable indexing for AI feeds" on the content edit form).

### Media is excluded by default

Nodes are included unless an editor opts them out. **Media works the opposite way: a media
item is excluded unless an editor explicitly opts it in** by unchecking "Disable indexing
for AI feeds" on that media item.

In practice this means `?type=media` returns an **empty feed on a typical site**, and it
does so while still reporting a large `total_records`:

```json
{
  "data": [],
  "pagination": {
    "type": "media", "page": 1, "page_size": 5,
    "total_records": 61, "total_pages": 13
  }
}
```

That is not a bug and not an error. 61 published media items exist; none has been opted in.

The legacy feed also returned no media by default, but for a different reason: it filtered
media by a **site-wide bundle allowlist** (`ai_engine_embedding.settings.included_media_types`),
which ships unset and therefore matched nothing. So the change is not "included" to
"excluded" — it is a site-wide, bundle-level allowlist becoming a **per-item** opt-in. If
that allowlist *was* configured, the legacy feed returned every published media item of
those bundles; the Beacon feed instead needs each item opted in individually.

### A media item points at its file; it does not carry the file's text

For a media item, `url` is the direct URL of the source file and `content` is always `""`.
The feed tells you *where* the document is, and a consumer that wants the document's text
fetches that URL and parses the file itself. **This matches the legacy endpoint**, which
also returned the file URL with an empty content field for media, so it is not a change to
migrate around.

One related thing to know if you are trying to mirror what the chatbot knows, rather than
just consume the feed: Beacon separately extracts document text into its own vector index
for retrieval, and the index also covers a narrower set of media than the feed does
(`search_api.index.ys_beacon` restricts the media datasource to the `document` bundle,
while the feed applies only `BeaconIndexability`). So the feed and the chatbot's index are
not interchangeable views of the same media.

## Request parameters

All parameters are optional query-string parameters.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `type` | string | `node` | Entity type to feed. Only `node` and `media` are supported; anything else returns `400`. Omit it entirely to get the default — sending it **empty** (`?type=`) is a `400`, not a fallback. |
| `page` | integer | `1` | Page number, **1-based**. Values below 1 are clamped to 1. |
| `page_size` | integer | `50` | Items per page. Clamped to the range 1-200; a value above 200 becomes 200, and 0 or negative becomes 1. |

**Out-of-range values are clamped, never rejected.** `?page_size=1000` succeeds and behaves
as `page_size=200`; `?page=0` behaves as `page=1`. The `pagination` object in the response
always reports the *effective* `page_size` after clamping, so read it back from there rather
than assuming your requested value was used.

The one exception is `type`: an unsupported entity type is a `400`, not a fallback to
`node`.

### Example requests

```bash
# Defaults: first 50 nodes
curl -s 'https://yoursite.yale.edu/api/ys-beacon/v1/content'

# An explicit page of nodes
curl -s 'https://yoursite.yale.edu/api/ys-beacon/v1/content?type=node&page=2&page_size=25'

# Media instead of nodes (expect an empty feed unless items are opted in)
curl -s 'https://yoursite.yale.edu/api/ys-beacon/v1/content?type=media'

# The largest page the endpoint will serve
curl -s 'https://yoursite.yale.edu/api/ys-beacon/v1/content?type=node&page_size=200'
```

## Response format

A successful response is a JSON object with exactly two top-level keys:

```json
{
  "data": [ /* zero or more feed items */ ],
  "pagination": { /* metadata about this page */ }
}
```

### Node item

```json
{
  "id": "node/1",
  "type": "node",
  "bundle": "page",
  "uuid": "c51bc573-da48-4f9d-84b2-73995f225ebf",
  "title": "Hello World",
  "url": "https://yoursite.yale.edu/hello-world",
  "langcode": "en",
  "created": "2023-04-17T16:46:18+00:00",
  "changed": "2026-07-04T03:43:00+00:00",
  "ai_description": "",
  "ai_tags": "",
  "content": "Hello World Grand Hero Hello all the peoples of the world. Spotlight On the Things This is my awesome spotlight. Yale University"
}
```

### Media item

Media items carry the same keys. Two differ in kind rather than value: `content` is always
an empty string, and `url` points at the **source file** rather than a page on the site.

```json
{
  "id": "media/46",
  "type": "media",
  "bundle": "document",
  "uuid": "bc79ea50-a9a5-41fc-8855-7a3619a330f5",
  "title": "Modern Report",
  "url": "https://yoursite.yale.edu/sites/default/files/2023-11/Modern%20Report.pdf",
  "langcode": "en",
  "created": "2023-11-16T14:51:25+00:00",
  "changed": "2026-09-03T20:44:32+00:00",
  "ai_description": "",
  "ai_tags": "",
  "content": ""
}
```

Note that the file URL is URL-encoded, so a filename containing spaces arrives with `%20`
rather than raw spaces.

### Pagination object

```json
{
  "type": "node",
  "page": 1,
  "page_size": 50,
  "total_records": 65,
  "total_pages": 2
}
```

| Key | Description |
|---|---|
| `type` | The entity type served, after defaulting. |
| `page` | The page requested, after clamping. Echoed even when past the end. |
| `page_size` | The **effective** page size, after clamping. |
| `total_records` | Count of **published candidates** — an upper bound, not the number of items the feed will serve. See below. |
| `total_pages` | `ceil(total_records / page_size)` — also an upper bound, for the same reason. |

**`total_records` and `total_pages` are upper bounds.** They come from a single count query
over published entities, taken *before* the per-item indexability filter runs. Producing an
exact total would require loading and access-checking every entity on the site on every
request, so the endpoint deliberately does not. Do not use `total_records` to verify you
received everything, and do not use `total_pages` as your only loop bound — see
[Pagination](#pagination).

### Pagination links

**There are none.** The response carries no `links` object and no `first` / `prev` / `next`
/ `last` fields; the legacy endpoint's equivalent has no counterpart here. Construct page
URLs yourself by incrementing the `page` parameter:

```
https://yoursite.yale.edu/api/ys-beacon/v1/content?type=node&page=2&page_size=50
```

Because there is no `next` link to follow, the end of the feed is signaled only by an
empty `data` array — see [Pagination](#pagination).

## Field reference

| Field | Type | Notes |
|---|---|---|
| `id` | string | `"<entity type>/<entity id>"`, e.g. `"node/1"`. Not a bare integer. |
| `type` | string | `"node"` or `"media"`. |
| `bundle` | string | The bundle machine name, e.g. `"page"`, `"post"`, `"event"`, `"document"`. |
| `uuid` | string | The entity UUID. Stable across environments; prefer it over `id` as a durable key. |
| `title` | string | The entity label. |
| `url` | string or `null` | Absolute URL. Canonical entity URL for nodes; the direct file URL for media. **`null`** when no URL can be generated — handle it. |
| `langcode` | string | Language code, e.g. `"en"`. Always the entity's **default** language — see the note below. |
| `created` | string or `null` | ISO-8601 in **UTC**, e.g. `"2023-04-17T16:46:18+00:00"`. `null` if the entity has no creation timestamp. |
| `changed` | string or `null` | ISO-8601 in **UTC**. `null` if the entity does not track a changed time. |
| `ai_description` | string | From the `ai_description` metatag on the entity itself, with tokens replaced and markup stripped. **`""` when unset** — never `null`. Bundle-level metatag defaults are **not** applied, so `""` can mean "unset on this entity" even where a bundle default exists. |
| `ai_tags` | string | From the `ai_tags` metatag, same treatment and same caveat about bundle defaults. |
| `content` | string | Plain-text rendering of the entity's default view. **Always `""` for media.** See below. |

### Translations are not served

The feed applies no language condition and returns default translations only, so
`langcode` always reports the entity's default language and **translated content never
appears in the feed**. There is no parameter to request another language.

### About `content`

For a node, `content` is the entity rendered in its `default` view mode as the anonymous
user, then reduced to plain text: markup is stripped and all runs of whitespace are
collapsed to single spaces. Practical consequences:

- **There is no document structure.** No paragraph breaks, headings, or list markers.
- **The title is usually the first words of `content`**, because it is part of the rendered
  output. Do not assume `content` begins with the body text.
- **Rendered chrome may be included** — a node's default view mode can contain site
  furniture, so trailing text like `"Yale University"` can appear.
- **HTML entities are not decoded.** An apostrophe typed as a curly quote arrives as
  `&#8217;`. Decode entities on your side if you need clean text.
- **A render failure yields `""`**, not an error, so a rare empty `content` on a node with
  visible body text means the render threw and was swallowed.

## Pagination

The feed pages over published entities **sorted by entity ID ascending**, then filters each
page's items for indexability. The filter runs *after* the page window is taken, which
produces the one behavior that breaks naive consumers:

> **A page can contain fewer than `page_size` items without being the last page.**

A page of 50 that returns 8 items does **not** mean the feed is exhausted — it means 42 of
those 50 published candidates were then dropped because an anonymous visitor cannot view
them or because they are opted out of AI indexing. If you stop on the first short page, you
will silently truncate your import.

**Page until `data` is empty.** That is the only reliable terminator. A page past the end
returns `200` with `"data": []` (not a `404`), and echoes the page number you asked for.

Two more constraints to design around:

- **Do not bound the loop by `total_pages` alone.** It is derived from the upper-bound
  count, so it can overstate the number of pages with content. It is safe as a *sanity
  guard* against an infinite loop, but not as the loop's terminator.
- **Content shifting mid-crawl can skew results.** There is no snapshot: each request
  re-runs the query and takes a fresh offset window over the entities published *at that
  moment*, ordered by ID. Content published or unpublished between two requests therefore
  shifts which entities fall in later windows, so an item can be missed or repeated. For a
  full corpus sync, key results by `uuid` and reconcile at the end rather than assuming a
  clean sequential read.

### How to page through the whole feed

1. Request `page=1` at your chosen `page_size`.
2. Read `pagination.page_size` back, in case your value was clamped.
3. Process `data`, however many items it holds.
4. If `data` was **empty**, stop. Otherwise request the next page and repeat from step 3.
5. Optionally guard the loop with `total_pages + 1` as a maximum, purely to avoid looping
   forever against a misbehaving site — never as the terminator.

Handle `403` as a stop-and-skip for that site rather than a retry, treat `429` as retryable
after a pause (see Rate limits), and treat `5xx` as retryable with backoff.

## Error responses

| Status | Body | Meaning | What to do |
|---|---|---|---|
| `200` | feed object | Success. `data` may legitimately be empty. | Continue. |
| `400` | `{"error": "Unsupported feed type \"...\"."}` | `type` was neither `node` nor `media`. | Fix the request; do not retry unchanged. |
| `403` | `{"error": "The content feed is not enabled."}` | Beacon is not authorized on this site. | Stop for this site. Not retryable, not a credential issue. |
| `429` | `{"error": "Too many requests. Please try again shortly."}` | You are past the per-IP request quota. | Back off and retry after the window; see Rate limits. |
| `405` | HTML error page | A method other than `GET`. | Use `GET`. |

Note that `400`, `403` and `429` return JSON, but `405` returns Drupal's standard HTML error page.
A client that assumes every response parses as JSON will throw on a `405` — decide by
status code before parsing.

**A `302` is possible on some sites.** CAS forced login is configured per site and its path
patterns have no `/api` exemption, so a site that has forced login enabled for a pattern
matching this path will redirect the request to the CAS server instead of serving the feed.
Do not follow that redirect expecting JSON; treat a `3xx` as "this site is not serving the
feed publicly" and raise it with the site's administrators.

### Empty results are not errors

An empty `data` array is a normal `200` in several distinct situations, and none of them is
a failure:

- You paged past the end of the feed.
- You requested `type=media` on a site where no media has been opted in.
- Every candidate on that page window was filtered out by the indexability rules.

## Rate limits, performance, and polling

### Rate limits

**A quota of 120 requests per hour, per client IP, is enforced.** Past it the endpoint
returns `429` with `{"error": "Too many requests. Please try again shortly."}` and does no
work. The window is a rolling hour.

The limit is sized for a bulk crawler rather than for interactive traffic: at the maximum
`page_size` of 200, 120 requests walk 24,000 entities in a single pass, which covers a full
crawl of both `node` and `media` on the largest sites with room left over for re-crawls
inside the same hour. If you are hitting it, you are almost certainly polling far more often
than this endpoint is meant to be polled - see the polling guidance below.

Two things worth knowing about how the quota interacts with caching:

- **Only requests that actually reach the application are counted.** A repeat of a request
  already in the cache is served by the cache and never touches the quota. The limit exists
  to bound expensive work, not to meter cache hits.
- **The `403` for an unauthorized site is checked before the quota**, so a site that does not
  participate always answers `403` and is never throttled into a `429`.

### Why request cost matters here

**Responses are cacheable.** The endpoint sends `Cache-Control: max-age=..., public` and
carries full cache metadata, so a repeated identical request is served from the site's page
cache (and any edge cache in front of it) instead of re-running the query and re-rendering
every node. Measured on a local development site, a warm repeat of a `page_size=10` request
returned in ~0.03-0.06s against ~3.5s cold.

A page stays fresh for at most an hour, and any change to the content it contains
invalidates it immediately through Drupal's cache tags - so a cached response is never a
stale view of edited content, and you do not need to add cache-busting query parameters.
Do not add them: an unrecognised query argument produces a distinct URL that misses the
cache and burns quota for nothing.

A **cold** request still does real work. It re-runs the entity query and fully re-renders
every node on the page to produce `content`, so cost scales roughly linearly with
`page_size`. As an order-of-magnitude illustration from a local development site with 65
nodes: `page_size=1` took ~0.45s, `page_size=50` ~0.94s, and `page_size=200` ~1.81s. Do not
read those as production numbers, but do expect a large cold page to be a multi-second
request that does real work on the web server.

### Polling guidance

- **Poll infrequently.** Daily is appropriate for a content corpus; hourly is aggressive.
  This is not an endpoint to poll on a short timer.
- **Use `page_size` in the 50-100 range** for bulk crawls. It amortizes request overhead
  without producing very long single requests. Reach for 200 only if you have measured it
  against the specific site.
- **Do not crawl in parallel.** Concurrent large pages multiply full-render work on one web
  server for no throughput gain worth having, and they burn the shared per-IP quota faster
  without finishing sooner.
- **Use `changed` to skip work on your side.** Store it per `uuid` and reprocess only items
  whose `changed` advanced. The endpoint has no `since` parameter, so you still fetch every
  page, but you can avoid re-embedding or re-parsing unchanged content.
- **Back off on `5xx`, pause on `429`, stop on `403`.** A `403` will not resolve on retry;
  a `429` will, once the hour window rolls.

## Security model

The route deliberately carries no permission requirement, and that is safe because of
*how* items are built rather than *who* requests them.

Every page is built inside a switch to the anonymous user session, and each candidate must
pass `BeaconIndexability`. The feed therefore cannot serve anything a logged-out visitor
could not already fetch by browsing the site:

- Unpublished content is excluded.
- Content an anonymous visitor cannot view — CAS-protected pages, node-access-restricted
  content — is excluded, because the access check runs as that anonymous session.
- Content opted out via `ai_disable_indexing` is excluded.
- Node bodies are rendered as the anonymous user, so field- and component-level access
  applies to `content` too. Adding a permission gate would not restrict the payload any
  further; it would only change who may read public information in bulk.

What the feed does add over browsing the site is **convenient bulk access** to public
content. If that is a concern for a given site, the lever is the authorization flag
described above, which turns the endpoint off entirely.

## Migrating from /api/ai/v1/content

The Beacon feed replaces `ai_engine_feed`'s `/api/ai/v1/content`. It is **not** a drop-in
replacement. Every difference below will break a consumer written against the old endpoint.

| | Legacy `/api/ai/v1/content` | Beacon `/api/ys-beacon/v1/content` |
|---|---|---|
| Response envelope | `{data, links, totals}` | **`{data, pagination}`** |
| Item field names | `documentTitle`, `documentUrl`, ... | **all renamed — see below** |
| Entity type parameter | `entityType=node\|media` | **`type=node\|media`** |
| Page size | fixed | **`page_size`**, default 50, max 200 |
| Single-entity lookup | `id=18` | **no equivalent** — page and filter client-side |
| Pagination links | `links` object with `first`/`prev`/`self`/`next`/`last` | **none** — build page URLs yourself |
| Totals | exact | **upper bound** (counted before indexability filtering) |
| Whose access decides the payload | the **calling user's** | **always anonymous**, whoever calls |
| Media selection | site-wide **bundle allowlist** (unset by default, so nothing) | **per-item opt-in** via metatag |
| Media payload | file URL, empty content | **unchanged** — file URL, empty content |
| Site-level gate | none | **`403` until a platform admin authorizes Beacon** |

### Every item field was renamed

This is the largest breaking change and the easiest to miss. **No item field name survived
the rewrite**, and the response envelope's metadata key changed from `totals` to
`pagination`.

| Legacy field | Beacon field | Note |
|---|---|---|
| `id` = `"yalesites-yale-edu-node-18"` | `id` = `"node/18"` | **Same key, different format.** See the warning below. |
| `documentId` = `18` | *(removed)* | Derive it from `id` by splitting on `/`. |
| `documentType` = `"node/page"` | `type` + `bundle` | Split into two fields. |
| `documentTitle` | `title` | |
| `documentUrl` | `url` | Can now be `null`. |
| `documentContent` | `content` | Was **rendered HTML**; is now plain text. |
| `metaTags` | `ai_tags` | |
| `metaDescription` | `ai_description` | |
| `dateCreated` | `created` | |
| `dateModified` | `changed` | |
| `dateProcessed` | *(removed)* | Was the time the response was generated, not a content timestamp. |
| `source` = `"drupal"` | *(removed)* | Was a constant. |
| `documentDescription` (media only) | *(removed)* | |
| — | `uuid` | **New.** The durable cross-environment key. |
| — | `langcode` | **New.** |

> **`id` is the trap.** It is the only key present in both versions, and its format changed
> completely. A consumer keyed on `id` will not fail loudly — it will treat every item as
> new and silently duplicate its entire corpus. Migrate such a consumer to `uuid`, and plan
> a one-time reconciliation of anything already stored under a legacy `id`.

A migration checklist:

1. **Remap every item field** using the table above, and read pagination metadata from
   `pagination` rather than `totals`. Re-key your storage on `uuid`, not `id`.
2. Rename `entityType` to `type`.
3. Replace any use of the `links` object with your own URL construction, and switch your
   loop terminator to "page until `data` is empty."
4. Stop trusting `total_records` as an exact count or `total_pages` as a loop bound.
5. Handle `403` as "this site does not participate," distinct from an error.
6. If you consumed media, note that the selection mechanism changed from a site-wide
   bundle allowlist to a per-item opt-in. A site that had the allowlist configured will
   serve less media until its editors opt items in individually.
7. Replace any `id=` lookups. If you need one entity, fetch pages and match on `uuid`.
8. If your consumer authenticated against the legacy feed, drop the credentials — they
   change nothing here, and the content set it sees will shrink to the anonymous view.

## Versioning

The version lives in the path: `v1`. Additive, backward-compatible changes — a new field on
an item, a new optional parameter — can ship within `v1`. A breaking change to the response
shape or to existing parameter semantics would ship as a new path segment.

Because new fields can appear within `v1`, parse defensively: read the keys you need and
ignore the rest rather than validating against an exact key set.

## Implementation reference

For maintainers. The endpoint is defined in `ys_beacon.routing.yml` as
`ys_beacon.content_feed`.

| Concern | Where it lives |
|---|---|
| Route, parameter parsing, `400`/`403`/`429` responses, quota, response cacheability | `src/Controller/ContentFeedController.php` |
| Entity query, paging, clamping, item shape, `content` rendering | `src/Service/ContentFeedBuilder.php` (`ys_beacon.content_feed_builder`) |
| The indexability rule, including media-excluded-by-default | `src/Service/BeaconIndexability.php` |
| `ai_description` / `ai_tags` derivation | `src/Service/AiMetadataManager.php` |
| `title` / `url` derivation, media file URLs | `src/Service/EntityCitationResolver.php` |
| The site authorization flag | `src/BeaconAuthorization.php`, reading `ys_beacon.settings:platform_authorized` |

`DEFAULT_PAGE_SIZE` (50) and `MAX_PAGE_SIZE` (200) are constants on `ContentFeedBuilder`.
If either changes, update the [Request parameters](#request-parameters) table here as well.
