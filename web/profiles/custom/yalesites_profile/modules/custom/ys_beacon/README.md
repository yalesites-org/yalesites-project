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

- Content is indexed on cron (`index_directly` is off so entity saves never
  block on embedding calls).
- Re-index everything: the button on the Beacon administration form, or
  `drush sapi-r ys_beacon && drush sapi-i ys_beacon`.

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

## React widget

Source lives in `react/` (Vite + TypeScript fork of the ai_engine_chat
widget, with chat history, feedback, and Azure auth removed). The built
bundle in `react/static/` is committed; CI does not build module JavaScript.

After changing `react/src`:

```
cd react
nvm use            # repo root .nvmrc
npm ci
npm run build      # tsc && vite build -> react/static/assets
```

Commit the regenerated `react/static` output together with the source change.

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
