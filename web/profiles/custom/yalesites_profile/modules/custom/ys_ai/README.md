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

- Drush: `drush ys-ai:create-index` (alias `ys-ai-create-index`). Pass
  `--force` to create-or-update the index even when it already exists — use this
  to roll out schema changes (such as new filterable fields) to an existing
  index. Azure adds new fields in place; content must be re-indexed to populate
  them.
- Enabling the chat widget on the Yale Chat settings form; saving with chat
  enabled ensures the index exists and reports the outcome. (The form never
  forces, so it will not rewrite an existing index.)

Prerequisites: the Beacon search server must be configured and the Azure URL /
API-key Key entities (Pantheon Secrets) available. If they are missing, the
command and form report a clear error and make no changes.
