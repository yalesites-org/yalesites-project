YS AI
=====

Yalesites specific use of the ai_engine.

Instructions
------------

These are custom settings for the ai engine to do things outside of the scope of ai_engine and more in scope of YaleSites.

Beacon search index provisioning
---------------------------------

The Beacon chatbot queries an Azure AI Search index. The Azure AI Search VDB
provider does not create that remote index automatically, so this module
provisions it on demand from a shipped schema (`data/azure_beacon_index.schema.json`).

The provisioning is idempotent ("create if it does not exist"): the index is
only created when it is missing, so it can be run any number of times safely.
The per-site Azure URL, API key, and index name are resolved from the Beacon
search server configuration (populated by `BeaconSearchConfigOverride` from
Pantheon Secrets and the environment); nothing needs to be entered by hand.

It can be triggered two ways, both backed by the same
`ys_ai.beacon_index_provisioner` service:

- Drush: `drush ys-ai:create-index` (alias `ys-ai-create-index`).
  - `--force` create-or-updates the index even when it already exists — use
    this to roll out additive schema changes (such as new filterable fields).
    Azure adds new fields in place; content must be re-indexed to populate them.
  - `--recreate` drops the index and recreates it from the schema. Azure cannot
    change a field's data type in place, so changes like switching a field
    between `Edm.Int64` and `Edm.DateTimeOffset` require a recreate. This
    discards all indexed documents, so re-index content afterwards
    (`drush search-api:index beacon_index`).
- Enabling the chat widget on the Yale Chat settings form; saving with chat
  enabled ensures the index exists and reports the outcome. (The form never
  forces, so it will not rewrite an existing index.)

Prerequisites: the Beacon search server must be configured and the Azure URL /
API-key Key entities (Pantheon Secrets) available. If they are missing, the
command and form report a clear error and make no changes.

Created/changed timestamps (DateTimeOffset)
-------------------------------------------

The Beacon index stores each node's `created` and `changed` dates as readable
ISO 8601 timestamps (Azure `Edm.DateTimeOffset`, for example
`2025-06-05T12:00:00Z`) so they are human-readable and sort and filter as real
dates rather than opaque Unix-timestamp integers.

Search API's built-in `date` data type stores values as integer Unix
timestamps, and Azure's `Edm.DateTimeOffset` rejects bare integers. A custom
Search API *data type* plugin cannot fix this: the `ai_search` backend reports
that it supports no data types (`SearchApiAiSearchBackend::supportsDataType()`
only returns TRUE for `embeddings`), so Search API downgrades every field to its
fallback type before a data type's `getValue()` ever runs.

Instead the module provides a custom embedding strategy,
`ys_beacon_contextual_chunks`
(`src/Plugin/EmbeddingStrategy/BeaconContextualEmbeddingStrategy.php`), selected
by the Beacon search server (`config/sync/search_api.server.beacon.yml`). It
extends the stock `contextual_chunks` strategy and post-processes the attribute
metadata: any field configured as a `date` on the index has its still-numeric
value reformatted to ISO 8601. Detection uses the index's *configured* field
type (which survives the backend's per-item downgrade), not the runtime field
type. The shipped Azure schema declares `created`/`changed` as
`Edm.DateTimeOffset`.

Because this changes the Azure field type, rolling it out to an existing index
requires `drush ys-ai:create-index --recreate` followed by a re-index.

Access control (anonymous-only indexing)
----------------------------------------

The Beacon index powers a public chatbot, so it must only ever contain content
an anonymous visitor is allowed to see. The stock Search API `content_access`
processor cannot enforce this on the Azure backend: it filters at query time on
a hidden `node_grants` field, but that field is not stored as a filterable Azure
attribute (the backend supports no data types except `embeddings`), so the
filter matches nothing and anonymous users receive no results.

Instead the module provides a Search API *processor*, `ys_beacon_access_filter`
(`src/Plugin/search_api/processor/BeaconAccessFilter.php`), enabled on the Beacon
index. It enforces access at *index time* via `alterIndexedItems()`: any node an
anonymous user cannot view (`$node->access('view', $anonymous)`) is removed
before it is sent to Azure. Access is delegated to the node access system, so
the YaleSites `ys_node_access` grants — which deny anonymous access to
unpublished and CAS-protected (`field_login_required`) nodes — remain the single
source of truth. Excluded content is never written to the index and therefore
can never be returned to any user.

The processor also enforces the per-node "Exclude from AI search" flag
(`field_ai_exclude`, see below): flagged nodes are removed from the index.

Because enforcement is at index time, toggling a node's published state, CAS
protection, or AI-exclude flag takes effect on that node's next re-index (Search
API marks the node for re-indexing on save).

AI metadata fields
------------------

Three native node fields control AI ingestion, replacing the legacy
`ai_engine` metatags (`ai_disable_indexing`, `ai_description`, `ai_tags`):

- `field_ai_exclude` (boolean) — exclude this content from the AI index.
- `field_ai_description` (long text) — extra content to ingest for this node.
- `field_ai_tags` (text) — extra tags to ingest for this node.

They appear in an "AI" group in the node form sidebar and are hidden when the
Beacon search index is disabled (`ys_ai_form_node_form_alter()`); hiding never
deletes stored values. The legacy `ai_engine` metatag group is removed from the metatag
widget (`ys_ai_field_widget_single_element_metatag_firehose_form_alter()`) so the
two surfaces do not compete while `ai_engine` is phased out. Existing metatag
values are migrated to the new fields by
`ys_ai_post_update_migrate_ai_metadata()`.
