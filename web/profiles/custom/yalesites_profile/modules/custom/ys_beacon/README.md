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

1. Pantheon secrets (per site or org-wide), surfaced as key entities with the
   same ids by the pantheon_secrets sync (`/admin/config/system/keys/pantheon`
   or `drush pantheon-secrets:sync`). Beacon never creates key entities
   itself - the sync owns them:
   - `portkey_llm_api_key` - Portkey API key for chat
   - `portkey_embedding_api_key` - Portkey API key for embeddings
   - `azure_ai_search_api_key` - Azure AI Search admin key
   - `azure_ai_search_url` - Azure AI Search endpoint URL
2. A platform administrator sets the per-site Azure index name at
   `/admin/config/yalesites/ys-beacon/admin`. Until this is set, the Beacon
   search index stays disabled at runtime and no Azure traffic occurs.
3. A site administrator enables the chat widget at
   `/admin/config/yalesites/ys-beacon` (also reachable from
   `/admin/integrations`).

Per-site values are never overwritten by config imports: all `ys_beacon*`
config and the four key entities are in `config_ignore`, and the index name
and endpoint URL are layered onto the synced `search_api.server.ys_beacon`
and `ai_vdb_provider_azure_ai_search.settings` config at runtime by
`YsBeaconConfigOverrides`.

### Borrowing another site's index (read-only)

A site can query another site's collection (for example a shared or parent
corpus) instead of maintaining its own. Point the index name at that collection
(`azure_index_name`) and turn on **Read-only** on the Beacon administration form
(`ys_beacon.settings:read_only`). The borrowing site then answers from the
shared collection but never writes to it: immediate indexing, cron indexing, the
"Re-index all content" / "Index now" controls, `clear`, and delete-time document
removal are all suppressed, because `YsBeaconConfigOverrides` sets the Search API
index `read_only` flag at runtime and Search API gates those write paths on
`IndexInterface::isReadOnly()`. The site-facing settings form hides the indexing
controls and shows a note in this state. Like the index name, the flag lives in
the config-ignored `ys_beacon.settings`, so it survives `drush deploy` /
`drush cim` without config-ignoring a new `search_api.index.*` key.

Because the flag is applied as a runtime config override rather than persisted on
the index entity, Search API's own index admin UI - which loads the index
override-free - does not reflect it, so its "Index now" / clear / reindex actions
would still write to the collection. That UI requires the `administer search_api`
permission, which no YaleSites role is granted (site administrators manage Beacon
through `manage ys beacon settings`), so it is reachable only by a platform
operator. If that path ever needs closing, persist `read_only` on the entity and
config-ignore the `search_api.index.*:read_only` key instead.

(A read-only index still tracks items locally in Search API, but tracking is
local bookkeeping and never reaches the borrowed collection, so the owning
site's data is untouched.)

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
- Re-index everything: the button on the Beacon administration form, or
  `drush sapi-r ys_beacon && drush sapi-i ys_beacon`.

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
   (`ys_beacon.settings:guardrail_supplement`) that sits _after_ the platform
   guardrail, so a site can only _add_ restrictions, never relax the baseline.
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

Pantheon-provider keys cannot resolve locally. Create config-provider keys at
`/admin/config/system/keys` with the ids listed above (or pick different keys
in the Beacon and Portkey forms), then:

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
