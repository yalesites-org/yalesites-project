# YaleSites Beacon

Beacon is the YaleSites AI assistant built on the Drupal AI ecosystem. It is
the migration target for the legacy `ai_engine` module: content indexing runs
through Search API into an Azure AI Search vector database, chat requests are
answered by a Drupal endpoint that performs retrieval-augmented generation
(RAG), and all model traffic (chat and embeddings) is routed through the
Portkey AI gateway.

The legacy `ai_engine` module remains installed and untouched. Beacon reuses
its `ai_engine_metadata` submodule, so the AI metadata tags editors already
maintain (`ai_description`, `ai_tags`, `ai_disable_indexing`) keep working and
flow into the vector index.

## Naming

The chat widget originates from Microsoft's "Contoso Chat" sample (by way of
the `ai_engine_chat` widget it was forked from), and earlier development
iterations carried that placeholder name as the module `ys_contoso_chat`. That
placeholder is gone: this release ships the module as `ys_beacon` from the
outset, and no `contoso` identifier remains anywhere in the custom code or
configuration (machine name, PHP namespace, service ids, permissions, routes,
config object, or React bundle).

No rename migration hook is required. `ys_contoso_chat` was never released on
`develop` or to production, so there is no installed base whose
`ys_contoso_chat.settings` config or permission grants need migrating. Sites
install `ys_beacon` directly. The rename tracked by issue #1291 is therefore
satisfied by the consolidation rather than by an update hook.

## Architecture

```
Entity save -> Search API tracking -> ys_beacon index (ai_search backend)
                 - rendered HTML as main content (default view mode, anonymous)
                 - title, AI description, AI tags as contextual content
                 - ai_disable_indexing excludes items (ExcludeAiDisabled)
                 - embeddings generated through Portkey
                 - vectors stored in the per-site Azure AI Search index

Visitor -> React widget -> POST /api/ys-beacon/v1/conversation
                 - RagRetriever: vector query, chunked results -> citations
                 - SystemPromptBuilder: site system instructions + [docN] sources
                 - Portkey chat completion, streamed as NDJSON
```

Submodule `ys_beacon_portkey` provides the `portkey` AI provider plugin
(OpenAI-compatible, with `x-portkey-*` headers) used for both operations.

## Per-site configuration

The module is installed on every site and is off by default.

1. Pantheon secrets (per site or org-wide). The four key entities ship as
   `key.key.*` config and are created automatically by `config:import` (the
   same pattern as the recaptcha/mailchimp keys), so they pre-exist on every
   site without a manual sync. Each entity is only a pointer
   (`key_provider: pantheon`); the value resolves live from Pantheon at read
   time and is never stored in Drupal config. Beacon never creates key entities
   programmatically, and `drush pantheon-secrets:sync` still works for ad-hoc
   secrets but is no longer required for these four:
   - `portkey_llm_api_key` - Portkey API key for chat
   - `portkey_embedding_api_key` - Portkey API key for embeddings
   - `azure_ai_search_api_key` - Azure AI Search admin key
   - `azure_ai_search_url` - Azure AI Search endpoint URL
2. User 1 (the platform superadmin) sets the per-site Azure index name at
   `/admin/config/yalesites/ys-beacon/admin`. This administration form is
   restricted to user 1 only — no other role, however privileged, can reach it
   (`\Drupal\ys_beacon\Access\BeaconAdminAccessCheck`). Until the index name is
   set, the Beacon search index stays disabled at runtime and no Azure traffic
   occurs.
3. A site administrator enables the chat widget at
   `/admin/config/yalesites/ys-beacon` (also reachable from
   `/admin/integrations`).

Platform operators can also drive Beacon for a site from the **Beacon (AI Chat)**
section of the Platform Admin Settings page (`/admin/yalesites/platform-admin-settings`,
contributed by the `BeaconPlatformAdminSetting` plugin) without opening the
per-site forms. Besides the "Allow site admins to configure and use Beacon"
authorization flag, that section exposes an **Enable chat widget** toggle and
**Re-index all content** / **Index now** buttons. These reuse the site settings
form's handlers verbatim (via the class resolver) and the same
`ys_beacon.settings:enable_chat` flag, so toggling or indexing from either place
stays consistent. The indexing buttons follow the same guards as the site form:
they are hidden with an explanatory note when the site borrows a read-only index,
and "Index now" is disabled when nothing is queued.

### Cutting a site over from the legacy `ai_engine` chatbot

A site that was running the legacy `ai_engine` chatbot is cut over by a platform
admin, from the **Beacon (AI Chat)** section of the Platform Admin Settings page.
The deploy does **not** do it automatically, and that is deliberate — see
"Why the cutover is not automatic" below. The steps, all on that one page:

Beacon is brought up **first** and the legacy chatbot is retired **last**. That
order matters: Beacon stands down for as long as the legacy chat widget is live,
so bringing it up costs the site nothing, whereas retiring first would leave the
site with no assistant at all if provisioning then failed.

1. **Allow site admins to configure and use Beacon** (`platform_authorized`).
   Until this is on, every Beacon surface is hidden and the search index is
   forced off at runtime.
2. **Enable chat widget**, then save. This is the step that brings Beacon up: it
   provisions the site's Azure index (or adopts an existing one), pins the
   resolved endpoint, enables the Search API index, and queues the site's
   content. Visitors still see the legacy chatbot throughout. If the index
   cannot be created — an Azure outage, or the service at its index cap — the
   specific reason is reported and the widget is left off, so the site keeps the
   chatbot it had.
3. **Index now** to run the indexing batch immediately instead of waiting for
   cron to drain the queue. Confirm the "X of Y items indexed" count is moving
   before switching visitors over.
4. **Turn off legacy AI Engine.** This button appears only once Beacon is
   authorized, enabled, and pointed at an index — that is, only once it can
   actually take over. It clears all four legacy flags in one click
   (`ai_engine_chat.settings:enable` and `:floating_button`,
   `ai_engine_embedding.settings:enable`, `ai_engine_metadata.settings:enable`),
   replacing the manual walk through three separate `ai_engine` forms. Visitors
   switch to Beacon at this point.

Until Beacon is ready, that section shows an explanatory note instead of the
button. To roll back after step 4, re-enable "Enable chat widget" on the
`ai_engine` Chat Admin form; the Beacon widget stands down again automatically.

#### Why the cutover is not automatic

Tracked by yalesites-org/YaleSites-Internal#1459. Two independent reasons:

- **Beacon cannot be brought up during a deploy.** `ys_beacon` is enabled by the
  `core.extension` diff in the config import's *extension* step, which core runs
  strictly before the step that creates configuration
  (`ConfigImporter::processExtensions()` then `::processConfigurations()`). The
  `key.key.azure_ai_search_*` entities and the Beacon Search API server and
  index all arrive in that later step, so at `hook_install()` time there are no
  credentials to authenticate with and no index entity to enable. Update hooks
  are even earlier (the `updatedb` phase, before `config:import` runs at all).
  Note also that a site receiving Beacon for the first time runs **no** update
  hook: installing a module sets its schema version straight to the newest
  update, so `hook_install()` is the only entry point.
- **Deferring it to cron is worse, not better.** An unattended job would swap a
  live site's visitor-facing assistant at an unpredictable moment, before anyone
  had confirmed Beacon could answer from a populated index.

Secret values are never stored in Drupal config: the four key entities are
Pantheon pointers (created by `config:import` as `key.key.*` config) whose
values resolve live at read time. Per-site `ys_beacon*` config stays in
`config_ignore` so index/chat settings are not overwritten by config imports.
The per-site index name and read-only flag are persisted onto the real Search
API config (`search_api.server.ys_beacon`'s Azure database name and
`search_api.index.ys_beacon`'s `read_only` flag) by
`BeaconIndexManager::propagateConnection()`, with those two keys config-ignored
so the import keeps them. The Azure endpoint URL is still layered onto
`ai_vdb_provider_azure_ai_search.settings` at runtime from a key entity by
`YsBeaconConfigOverrides`, which also forces the index status off while chat is
disabled or the site is unauthorized.

### Borrowing another site's index (read-only)

A site can query another site's collection (for example a shared or parent
corpus) instead of maintaining its own. Point the index name at that collection
(`azure_index_name`) and turn on **Read-only** on the Beacon administration form.
The borrowing site then answers from the shared collection but never writes to
it: immediate indexing, cron indexing, the "Re-index all content" / "Index now"
controls, `clear`, and delete-time document removal are all suppressed, because
the form persists the Search API index `read_only` flag onto the index entity
(`BeaconIndexManager::propagateConnection()`) and Search API gates those write
paths on `IndexInterface::isReadOnly()`. Because the flag is persisted on the
entity rather than only applied as a runtime override, Search API's own index
admin UI reflects it too, so its "Index now" / clear / reindex actions stand down
as well. Both the site settings form (which mirrors "Index now", and "Re-index
all content" in the initial state) and the Beacon administration form (which
hosts "Re-index all content" and "Index now") hide their indexing controls and
show a note in this state.

The read-only flag and the borrowed index name live in the config-ignored
`ys_beacon.settings` as the site preference, and are written onto the synced
`search_api.index.ys_beacon` (`read_only`) and `search_api.server.ys_beacon`
(Azure database name) config. Those two keys are themselves config-ignored
(`search_api.index.ys_beacon:read_only` and
`search_api.server.ys_beacon:backend_config.database_settings.database_name`), so
a per-site value survives `drush deploy` / `drush cim`. These `config_ignore`
entries are keyed to the default `ys_beacon` index/server machine names; a site
that overrides `search_index_id` / `search_server_id` (the multi-index
capability) must add matching `config_ignore` keys for its names, or config
import will reset the persisted database name and read-only flag.

(A read-only index still tracks items locally in Search API, but tracking is
local bookkeeping and never reaches the borrowed collection, so the owning
site's data is untouched. Retrieving and citing content that belongs to a
*different* site's collection additionally requires the cross-site citation work
tracked in the shared multi-tenant index epic; a same-content borrow - for
example across environments of one site - works today.)

## Azure AI Search index provisioning

Indexes are provisioned automatically by `BeaconIndexManager`:

- When a site administrator first enables the chat widget (and no index name
  is configured), an index named `{PANTHEON_SITE_NAME}-{PANTHEON_ENVIRONMENT}`
  is created and stored in `ys_beacon.settings:azure_index_name`.
- When a platform administrator saves an index name on the Beacon
  administration form, the index is created if it does not exist.
- Creation is strictly conditional: existence is checked first and the create
  call uses `POST /indexes`, which Azure rejects for existing indexes - an
  existing index is adopted as-is and never modified.
- The configured `azure_ai_search_api_key` already performs document writes,
  which require an Azure admin key, so the same key authorizes index
  creation. No separate key is needed.

The generated schema matches the Azure VDB provider template (`id` key,
`drupal_entity_id`, `drupal_long_id`, `index_id`, `server_id`, `content`)
plus the `vector` field the template omits: `Collection(Edm.Single)`,
dimensions from the search server config (default `1536`, matching
`text-embedding-3-small`), with an HNSW vector search profile.

If the embedding model changes, the vector dimensions must change with it,
a new index must be provisioned, and all content re-indexed.

## Indexing operations

- Content is indexed immediately on save (`index_directly` is on). Search API
  runs the embedding calls after the response is sent (in `kernel.terminate`),
  so the editor's save returns without waiting on them, and a freshly published
  or edited page is answerable by the chat without a cron run or a manual
  "Index now". Items are still tracked, so cron remains the backstop for
  anything the request did not finish.
- Deletes remove the item from the vector database synchronously during the
  delete request, so removed content stops being cited promptly.
- Bulk changes are indexed at the end of the request in `cron_limit`-sized
  chunks; anything that does not finish before the request ends stays tracked
  and is drained by the next cron run. Two cases produce a large end-of-request
  batch: a single request that saves many nodes (a migration, Feeds/JSON:API
  import, or a `drush` loop), and — because `track_changes_in_references` is on
  — editing one entity that many indexed items reference (a taxonomy term,
  menu, or shared media), which re-embeds every referencing item. Both run
  after the response is sent, so the editor is not blocked, but they can occupy
  a worker and drive embedding-API cost for the batch's duration. Batch-API
  bulk operations already spread their saves across requests; a genuinely large
  programmatic import should wrap its save loop in
  `Index::startBatchTracking()`/`stopBatchTracking()` to defer indexing to
  cron. (Immediate indexing is gated on the index being enabled, so nothing
  runs while chat is off; see "Per-site configuration" above.)
- Re-index everything: the button on the Beacon administration form, the same
  "Re-index all content" / "Index now" buttons in the Platform Admin Settings
  Beacon (AI Chat) section, or `drush sapi-r ys_beacon && drush sapi-i ys_beacon`.
  The site settings form (`/admin/config/yalesites/ys-beacon`) also surfaces
  "Re-index all content" in the initial state only — the index enabled but with
  nothing tracked yet (the "0 of 0" state right after the chat widget is first
  turned on) — so a site administrator can seed indexing without leaving that
  form; it disappears once any content is tracked.

> The `drush` commands below use `ys_beacon`, the default Search API index id.
> If a site overrides `ys_beacon.settings:search_index_id`, substitute that id.

## PDF text extraction

PDFs carry their content inside the file, which Search API cannot read, so the
chatbot could otherwise only see a PDF's filename and metadata. Beacon extracts
the PDF text layer into a media field that Search API can index:

- **Field:** `field_ai_pdf_text` (string_long) on the `document` media bundle,
  shipped in the profile config sync. It is machine-populated, not edited by
  hand. Add it to the Beacon index's `field_settings` to include PDF text in AI
  search.
- **Asynchronous:** on media insert, and on update when the uploaded file
  changes, `ys_beacon` queues a `ys_beacon_pdf_text_extraction` job that runs on
  cron, so uploading a large PDF never slows the editorial save.
- **Opt-out and access respected:** extraction is skipped when
  `ai_disable_indexing` is set, and only runs on sites where Beacon indexing is
  configured.
- **Image-only PDFs:** scanned PDFs with no text layer extract to an empty
  string (logged, no error). There is no OCR.
- **Size limit:** files larger than `ys_beacon.settings:pdf_extraction_max_bytes`
  (default 20 MB) are skipped and logged, to bound memory and time.

### Extraction library

`smalot/pdfparser` (pinned in the profile `composer.json`) is used because it is
pure PHP and needs no system binary. The common alternative,
`spatie/pdf-to-text`, shells out to the `pdftotext` binary, which is not
available on the managed Pantheon platform; `smalot/pdfparser` works there
unchanged. The parser is isolated behind `PdfTextExtractorInterface` so it can
be swapped without touching the extraction orchestration.

## Content feed API

The push-based pipeline indexes content into Azure for the chatbot, but an
external consumer that needs to _pull_ content can read the JSON content feed,
the equivalent of the legacy `/api/ai/v1/content` endpoint:

```
GET /api/ys-beacon/v1/content?type=node&page=1&page_size=50
```

- **Open to all users.** The route is accessible to any role, authenticated or
  anonymous. There is no permission gate, because the feed only ever exposes
  content a logged-out visitor could already read (see below).
- **Same indexability rules as the index.** Items are filtered through
  `BeaconIndexability` while account-switched to the anonymous user, so the feed
  exposes exactly what the chatbot indexes regardless of who calls it: published,
  anonymously viewable (not CAS-protected), and not opted out via
  `ai_disable_indexing`.
- **Parameters:** `type` (`node` or `media`, default `node`), `page` (1-based,
  default 1), `page_size` (default 50, max 200). Because the per-item
  indexability filter runs after the page window, a page may contain fewer than
  `page_size` items; page until `data` is empty.

Response shape:

```json
{
  "data": [
    {
      "id": "node/123",
      "type": "node",
      "bundle": "page",
      "uuid": "…",
      "title": "…",
      "url": "https://…",
      "langcode": "en",
      "created": "2026-01-01T00:00:00+00:00",
      "changed": "2026-02-01T00:00:00+00:00",
      "ai_description": "…",
      "ai_tags": "…",
      "content": "plain-text rendering of the default view (nodes only)"
    }
  ],
  "pagination": {
    "type": "node",
    "page": 1,
    "page_size": 50,
    "total_records": 1234,
    "total_pages": 25
  }
}
```

Node bodies are rendered as the anonymous user, so the feed never exposes
content a logged-out visitor could not see.

## Citations

`CitationFormatter` is the single, server-side home for citation handling. Given
the model's answer and the sources `RagRetriever` returned (in `[docN]` order),
it de-duplicates sources by URL, flags which ones the model actually cited
(`[docN]` present in the answer), and renumbers them for display. Both the chat
and the AI tester build on `RagRetriever` for retrieval and on this formatter
for the cited/de-duplicated list, so the two cannot drift.

## AI tester

The `ys_ai_tester` submodule batch-runs a YAML list of questions through the
Beacon assistant for QA. It uses `BeaconAnswerService` (the non-streamed
counterpart of the chat endpoint: same retrieval and system prompt, whole
answer at once) and `CitationFormatter`, so each result shows **every** retrieved
source as a linked title plus URL, flagged cited or "retrieved, not cited" —
letting a tester evaluate citation quality, not just bare URLs. Citations are
derived per question, so re-running never leaks citations across questions, and
the JSON export carries the same structured citation fields shown on screen.
Reach it from the integrations dashboard or
`/admin/config/yalesites/ys-beacon/tester` (permission: _Use YaleSites AI
Tester_).

## Guardrail telemetry

`GuardrailTelemetry` keeps **aggregate counters only**, bucketed per UTC day,
readable at `/admin/config/yalesites/ys-beacon/telemetry` (permission: _View
Beacon guardrail telemetry_, plus platform authorization). The same "what is and
is not recorded" summary below is rendered on that page, so what the platform
does and does not keep can be checked from the interface rather than taken on
trust.

There is **one** store that holds conversation text, and it is deliberately not
this one: a turn whose question matches a known injection pattern is kept in full
by `SuspectTurnLog` (see [Flagged turns](#flagged-turns-suspected-injection-attempts)
below). Ordinary turns are counted and nothing else.

The report page is gated on its **own** permission, `view ys beacon guardrail
telemetry`, rather than the broader `manage ys beacon settings` that site admins
also hold. It is granted to `platform_admin` only in
`config/sync/user.role.platform_admin.yml`; user 1 reaches it because user 1
bypasses permission checks. It carries `restrict access: true`. Re-scoping who
can read the telemetry later is therefore a change to the grants on this single
permission and nothing else.

**Recorded** — a count, per day, for each event type, plus dimensioned breakdown
rows:

| Event | Counted when | Breakdowns |
| --- | --- | --- |
| `turns` | a turn reached the model — the denominator for the rest | — |
| `refusal` | the answer's opening reads as a declined request | — |
| `guardrail_stop` | a guardrail returned a stop (per stopping guardrail, so one turn can contribute more than one) | `mode.<pre\|during\|post>`, `plugin.<label>`, `set.<id>` |
| `zero_citations` | retrieval returned no citations | — |
| `injection_pattern` | the question matched a known injection pattern | `pattern.<name>` |

`turns` exists so the others can be read as rates: without a denominator, a rise
in refusals cannot be told apart from a rise in traffic.

The recording API is **closed on purpose**. Every public method takes either no
argument or a bounded identifier — `recordTurn()`, `recordRefusal()`,
`recordZeroCitations()`, `recordInjectionPattern($pattern_name)`,
`recordStreamingStop($plugin_label)`, `recordGuardrailResults($results, $set_ids)`
— and the key-assembling `record()` is protected. A caller therefore cannot pass
conversation text in even by accident, which matters because the most likely
future caller is a streaming guardrail that has the offending text in hand.

**Not recorded in the counters** — questions, answers, user names, IP addresses,
session ids, or any sample of conversation text, hashed or redacted or otherwise.
The `ys_beacon_telemetry` table has three columns (`bucket_date`, `event_key`,
`event_count`); there is no column a transcript could be written into. (Question
and answer text of a *flagged* turn is kept in the separate
`ys_beacon_suspect_turn` table — see below. No user name, session id or IP address
is recorded there either.) The
refusal check keeps only the first 400 characters of an answer while classifying
it, and stores nothing — though that bound is a false-positive control for
Beacon's own copy, not a privacy boundary in itself, since the AI module's
streamed iterator already accumulates the whole answer in memory for the
duration of the request. `GuardrailSignalDetector` is a separate,
dependency-free class precisely so the component that reads question and answer
text has no way to persist any of it.

Most counters are written **after the answer has been streamed**, so recording
cannot delay the visible answer. The exception is `injection_pattern`, recorded as
soon as the question is parsed so an attempt still counts on a turn that is
refused later — one bounded `preg_match` ahead of a retrieval that makes a remote
call. Every read and write degrades quietly: if the table is missing or
unavailable the turn continues and a warning goes to the `ys_beacon` channel,
through a guard that tolerates the logger failing too (logging writes to the same
database the counters do, so one outage takes both).

Storage is a keyed table rather than State because State's read-modify-write is
not atomic — concurrent turns on this public, unauthenticated endpoint would
silently lose increments — and because every state write invalidates the whole
state cache. Each increment tries an `UPDATE ... SET event_count = event_count + 1`
first, so the ordinary case is one atomic statement; if no row exists yet it
inserts, and the composite primary key turns a racing insert into a constraint
violation that is folded back into an increment (MySQL/MariaDB — on PostgreSQL a
failed statement aborts the surrounding transaction, which this platform does not
use). Buckets older than 90 days are pruned when a new day's first row is
inserted, so no cron hook is needed.

Known limits. The first three are properties of the contrib `ai` module rather
than choices here:

- **Streaming-phase guardrail stops are only counted if the plugin reports
  them.** A guardrail implementing `StreamableGuardrailInterface` is evaluated
  inside `StreamedChatMessageIterator::processStreamingGuardrails()`, which never
  calls `addGuardrailResult()`, so the stop is invisible to callers.
  `AiGuardrailModeEnum::DuringGenerate` is declared in contrib and otherwise
  never used. **Beacon ships `streaming: true`, so this is the normal path, not
  an edge case:** a streaming guardrail must call
  `GuardrailTelemetry::recordStreamingStop($this->label())` from inside its own
  `processStreamedBuffer()`, or its stops will read as zero.
- **`guardrail_stop` reads zero until a guardrail set is configured for Beacon.**
  As of this change `ai.settings.global_guardrails` is empty and there is no
  `ai.ai_guardrail*` config in `config/sync`, so no guardrail runs on a chat turn
  at all. The counter is in place for when one is configured; the other three
  event types are live immediately.
- **"By plugin" means by plugin label.** `GuardrailResultInterface` exposes no
  plugin id, so two `ai_guardrail` entities sharing a plugin are
  indistinguishable.
- **"By set" is attributed only when one set is active.** Contrib discards the
  result-to-set association, so with several sets active the set is recorded as
  `ambiguous` rather than credited to each.
- **Refusal detection is a heuristic** over the answer's opening; the pattern
  list lives in one constant in `GuardrailSignalDetector`.
- **`injection_pattern` undercounts a sustained campaign.** Requests the flood
  limiter rejects (30 per 5 minutes per IP) are not counted, because the question
  has not been parsed at that point and parsing it before rate limiting would
  invert the protection. A scripted burst therefore shows a smaller uptick than
  the true number of attempts. Detection is also a fixed pattern list, and
  `preg_match()` returning `FALSE` at PHP's `pcre.backtrack_limit` is treated as
  "no match", so a sufficiently padded question can pass unflagged.
- **`zero_citations` currently means "the index matched nothing."** With
  `score_threshold` shipping at `0.0`, `RagRetriever` applies no score filter at
  all, so raising that threshold later would change what this metric counts.

### Flagged turns (suspected injection attempts)

The counters answer "how often", not "what happened". Five `ignore_instructions`
hits say nothing about what was actually attempted or how the model replied, so
`SuspectTurnLog` keeps the **whole turn** when a question matches a
`GuardrailSignalDetector` injection pattern: the time, the pattern name, the
question and the answer, in `ys_beacon_suspect_turn`.

This is the only place Beacon persists conversation text, so it is bounded and
each bound is stated on the report page rather than left to the schema:

| Bound | Value | Why |
| --- | --- | --- |
| Retention | 90 days (`SuspectTurnLog::RETENTION_DAYS`) | Pruned on write, like the counters — no cron hook needed. Reads also filter on the window, so an expired row is never shown even before a write prunes it. |
| Text clamp | 2000 characters each (`MAX_TEXT_LENGTH`) | Enough to review an attempt; stops one turn writing an unbounded row. |
| Rows per pattern per UTC day | 60 (`MAX_ROWS_PER_PATTERN_PER_DAY`) | The chat endpoint is public and unauthenticated. Per **pattern**, not per day overall: a single day-wide quota is steerable by the attacker it exists to record — 200 throwaway `ignore_instructions` hits would fill it and silently drop every later flagged turn, including a novel attack under another pattern. At quota the pattern's oldest row for the day is evicted, so what survives is the most recent attempts rather than whichever the attacker submitted first. |

The per-day quota means a sustained campaign is **sampled** here. The aggregate
`injection_pattern` counters are not capped, so the campaign is still fully
visible in the counts — the page says so where it lists the flagged turns.

Expect the counts to exceed the rows for a second reason too: `injection_pattern`
is counted as soon as the question is parsed, but the row is written after the
answer has streamed, so a flagged turn that is refused earlier (no chat provider
configured, conversation too long) is counted without being logged. The text
clamp is enforced on write by `SuspectTurnLog`, not by the controller's buffer,
which may overshoot by one streamed chunk.

The report lists the 50 most recent flagged turns, with question and answer
**shortened to 300 characters each for display** (`TelemetryController::EXCERPT_LENGTH`)
and an ellipsis when shortened. Storage clamps at 2000, which is right for
reviewing an attempt but wrong for a table cell — a question padded to the clamp
is one row tall enough to push every other flagged turn off the screen, which
defeats the point of listing them. The note under the table states both limits,
and the JSON export carries the full stored text.

Two deliberate design points:

- **It is a separate class, not a method on `GuardrailTelemetry`.** That service's
  guarantee — no caller can hand it conversation text even by accident — is worth
  keeping intact, so the capability that breaks the rule lives behind its own
  name, table and permission instead of widening the counters' API.
- **An ordinary turn accumulates nothing extra.** `ChatApiController` sizes its
  answer buffer from the injection check: a flagged turn buffers up to
  `MAX_TEXT_LENGTH`, every other turn buffers exactly the 400-character refusal
  sample it always did, and the question is only carried into the streamed
  closure when the turn is flagged.

**Only injection-pattern hits are logged.** A guardrail stop is not, for two
reasons that are properties of the current system rather than preferences: no
guardrail set is configured for Beacon yet (so `guardrail_stop` reads zero), and
streaming-phase stops are invisible to callers at all (see the known limits
above). Extending the trigger later is one added call at the same site in
`ChatApiController::conversation()`'s `finally` block.

### Chat-turn distribution chart

The report renders turns per UTC day across the full 90-day retention window
(`GuardrailTelemetry::getDailySeries()`), so a spike or a quiet spell is visible
without reading the by-day table. The series is zero-filled: a day with no turns
is a data point, and dropping it would make the x-axis lie about the window.

Bars are scaled to the busiest day rather than an absolute, so a quiet period is
still readable, and any non-zero day is floored at 1% so it never renders as an
empty column. Each bar carries its own date and count as real text in a
`visually-hidden` span, which is what makes the chart readable with a screen
reader — there is no `aria-label` summary standing in for the data. Markup lives
in `templates/ys-beacon-telemetry-chart.html.twig`; the bar height is passed as a
CSS custom property so `css/telemetry.css` keeps control of presentation.

### Removing the JSON export

The export button is **provisional** — it was added at the reviewer's request with
the explicit expectation that it may be dropped. It is self-contained. To remove
it, delete these three things and nothing else:

1. The `ys_beacon.telemetry_export` route in `ys_beacon.routing.yml`.
2. `TelemetryController::export()`.
3. The `$build['export']` link in `TelemetryController::report()`.

Nothing else references it: no library, no config, no schema, no test fixture
outside `TelemetryControllerTest`. The export reports its own limits in the
payload (`flagged_turns.truncated`, `max_rows_per_export`) rather than silently
truncating, and is capped at `SuspectTurnLog::MAX_EXPORT_ROWS` rows so a response
cannot grow unbounded. Because it carries flagged-turn text it is gated on the
same platform-admin-only permission as the page and sent `Cache-Control:
no-store`.

## System instruction layers

`SystemPromptBuilder::build()` assembles the chat system prompt from three
layers, always in this order, and it is invoked on every Beacon chat request
(`ChatApiController`), so the ordering is the actual injection point that
reaches the model through Portkey:

1. **Platform guardrail** - the Yale-wide baseline. It is defined as an
   immutable constant in code (`SystemPromptBuilder::PLATFORM_GUARDRAIL`),
   prepended first on every request, and declares precedence over all later
   instructions and over source and user content. It is invisible to site
   administrators and cannot be edited, blanked, or reordered per site.
2. **Site guardrail supplement** - an optional per-site value
   (`ys_beacon.settings:guardrail_supplement`), edited on the Beacon
   administration form, that sits _after_ the platform guardrail, so a site can
   only _add_ restrictions, never relax the baseline.
3. **Site system instructions** - the per-site assistant behavior, managed with
   versioning (or the `fallback_system_prompt` when no version is saved).

This is a deliberate design decision for issue #1143. The ticket envisioned the
platform instruction as platform-admin-editable config; it is instead defined
in code so it is identical on every site and cannot be weakened by any config
edit, import, or compromised site administrator. Because it is always present,
there is no "empty/unset platform instruction" state - the baseline always
applies. The guardrail text contains no secrets, keys, or internal URLs, since
prompt secrecy is not treated as a security boundary.

## Maintaining the index fields

What gets stored in the Beacon vector index is defined in several places that
must stay in lockstep. To add or change an indexed field, edit all of the
relevant sources below, then recreate the Azure index and re-index:

1. **`config/sync/search_api.index.ys_beacon.yml`** - the Search API index
   entity: which Drupal properties are indexed (`title`, `rendered_item`,
   `ai_description`, `ai_tags`, `media_name`) and the processors applied.
2. **`config/sync/search_api.server.ys_beacon.yml`** - the AI Search backend
   config, including `backend_config.embeddings_engine_configuration.dimensions`
   (the vector size; must match the embedding model).
3. **`config/sync/ai_search.index.ys_beacon.yml`** - the ai_search metadata for
   the index.
4. **`config/sync/ai_vdb_provider_azure_ai_search.settings.yml`** - the Azure
   connection, including the pinned Azure management `api-version` (default
   `2023-11-01`; read by `BeaconIndexManager::request()`).
5. **`src/Service/BeaconIndexManager.php`** - generates the Azure-side index
   schema (field definitions, the `vector` field, the HNSW profile) from the
   server's configured dimensions. The AI-metadata properties themselves come
   from the `AiMetadataProperties` Search API processor; `ExcludeAiDisabled`
   keeps opted-out content out of the index.

Then apply the change to a site:

```
drush sapi-r ys_beacon   # mark everything for re-indexing
drush sapi-i ys_beacon   # re-embed and push
```

If the Azure index schema itself changed (a new stored field, or new vector
dimensions), the Azure index must be recreated, not just re-indexed: Beacon
only ever adopts an existing Azure index and never alters it (see "Azure AI
Search index provisioning" above). Delete the index in the Azure AI Search
portal, then re-save the index name on the Beacon administration form
(`/admin/config/yalesites/ys-beacon/admin`) so `BeaconIndexManager`
re-provisions it to the new schema, and run the re-index above.

Changing the embedding model is a special case of this: the vector
`dimensions` must change with it, the Azure index must be recreated, and all
content re-indexed.

## Upgrading the AI contrib stack

Beacon extends and depends on the internal behavior of several fast-moving
contributed modules. When bumping `ai`, `ai_search`, or
`ai_vdb_provider_azure_ai_search`, re-verify these extension points (a change
in any of them can break Beacon silently, so run `lando phpunit --group
ys_beacon` after the bump):

- **`PortkeyProvider extends OpenAiBasedProviderClientBase`**
  (`modules/ys_beacon_portkey`) - reuses the OpenAI-based client and overrides
  `handleApiException()` to map HTTP status codes to AI exceptions. Re-verify
  if the base class's error handling or the `openai-php` `ErrorException` shape
  changes.
- **`RagRetriever`** depends on ai_search's chunked-result query mode: the
  `search_api_ai_get_chunks_result` query option and the per-result extra data
  (`content`, `drupal_entity_id`). Re-verify if ai_search changes its result
  item shape.
- **`BeaconIndexManager`** depends on the Azure VDB provider's schema template
  and management API (`api-version`) and on the search server backend config
  shape (`embeddings_engine_configuration.dimensions`).

### Citation `[docN]` contract

`SystemPromptBuilder::build()` is the single source of truth for the citation
marker format: it numbers the retrieved sources `[doc1]`, `[doc2]`, ... in the
exact order `RagRetriever` returns them, and instructs the model to cite with
those markers. `ChatApiController` ships the same ordered citation list in the
response envelope, and the React widget renders marker `[docN]` as
`citations[N-1]`. The order of the markers and the order of the citation list
must stay aligned. `SystemPromptBuilderTest::testBuildLocksDocMarkerToCitationOrder()`
is a regression test that fails if that alignment breaks, so a contrib change
that reorders or reshapes retrieved results is caught in CI rather than
shipping broken citations.

## React widget

Source lives in `react/` (Vite + TypeScript fork of the ai_engine_chat
widget, with chat history, feedback, and Azure auth removed). The built
bundle in `react/static/` is committed because the Pantheon deploy platform
has no build step.

After changing `react/src`:

```
cd react
nvm use            # repo root .nvmrc
npm ci
npm run build      # tsc && vite build -> react/static/assets
```

Commit the regenerated `react/static` output together with the source change.

The `.github/workflows/verify_beacon_bundle.yml` CI check rebuilds the bundle
from source on every pull request that touches `react/` and fails if the
result differs from the committed `react/static` output, so a source change
that was not rebuilt and re-committed cannot reach the live site. The build is
deterministic with the pinned Node (`.nvmrc`) and locked dependencies, so a
clean rebuild reproduces the committed bundle exactly.

### Source map decision

The production source map (`react/static/assets/index.js.map`) is committed and
shipped deliberately. The widget's source is already public in this repository,
so the map exposes nothing secret; it builds deterministically (no parity risk);
browsers fetch it only when developer tools are open, so it adds no cost for
normal visitors; and it makes production debugging of the one widget that uses
it far easier. To stop shipping it, set `build.sourcemap` to `false` in
`react/vite.config.ts`, delete the committed `.map`, and rebuild.

The conversation endpoint contract: NDJSON lines, each a complete
`{id, model, created, object, choices: [{messages: [...]}]}` envelope. The
first line carries a `role: "tool"` message whose `content` is a JSON-encoded
`{citations, intent}` payload; assistant content follows as incremental
deltas. `[docN]` markers in the answer map to `citations[N-1]`.

## Local development

The four key ids above are created by `config:import` as pantheon-provider
entities, so do not create keys with those ids yourself - they already exist.
Where the local environment can reach the Pantheon secrets they resolve as-is;
where it cannot, create a config-provider key under a *different* id at
`/admin/config/system/keys`, put your dev value in it, and select it in the
Beacon and Portkey forms (`ys_beacon_portkey.settings` is in `config_ignore`, so
a local Portkey selection persists across imports). Then:

```
lando drush en ys_beacon -y
lando drush cset ys_beacon.settings azure_index_name <dev-index> -y
lando drush cset ys_beacon.settings enable_chat 1 -y
lando drush sapi-rt ys_beacon   # rebuild tracking after setting the index name via CLI
lando drush sapi-i ys_beacon
curl -sN -X POST https://yalesites-fable.lndo.site/api/ys-beacon/v1/conversation \
  -H 'Content-Type: application/json' \
  -d '{"messages":[{"id":"1","role":"user","content":"What is this site about?","date":"2026-01-01T00:00:00Z"}]}'
```

For frontend work, `npm run dev` serves the widget with `/api/ys-beacon`
proxied to the Lando site (see `react/vite.config.ts`).
