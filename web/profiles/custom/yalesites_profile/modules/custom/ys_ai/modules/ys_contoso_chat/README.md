# Yale Chat (ys_contoso_chat)

A Yale-branded AI chat widget for YaleSites. It pairs a Drupal backend with a
React front end. The backend answers questions with the Drupal AI module
(`ai_assistant_api`) over a RAG index (`ai_search`), and the React widget renders
the conversation in a modal on the public site. Replies include numbered source
citations linking back to the indexed pages the answer was drawn from (see
[Source citations](#source-citations)).

This module is a submodule of `ys_ai`.

## Requirements

Declared in `ys_contoso_chat.info.yml`:

- `ai:ai_assistant_api` (the assistant runner that produces replies)
- `ai:ai_search` (RAG search backend)
- `drupal:filter` (output filtering for the disclaimer and footer)
- `drupal:system`
- `ys_integrations` (exposes the widget on the Integrations admin page)

A configured AI Assistant entity is required at runtime. Assistants are managed
at `/admin/config/ai/assistants`.

## Architecture

### Backend (Drupal)

- `src/Controller/YsContosoChatController.php`
  - `POST /yale-chat/message`: accepts `{ messages, conversation_id }`, runs the
    configured assistant, and streams the reply as newline-delimited JSON in an
    Azure OpenAI-compatible envelope so the React stream reader needs no changes.
  - `POST /yale-chat/clear`: resets the server-side conversation thread for a
    given `conversation_id`.
  - Both routes enforce CSRF via the core header-token mechanism and the message
    route is rate limited (see Security).
- `src/Form/YsContosoChatSettingsForm.php`: the settings form at
  `/admin/config/yale-chat/settings`.
- `src/Plugin/ys_integrations/ContosoChatIntegrationPlugin.php`: the integration
  plugin that surfaces the widget under Settings, Integrations.
- `ys_contoso_chat.module`:
  - `hook_page_attachments_alter()` attaches the widget library and passes config
    to the front end via `drupalSettings` (initial questions, and the disclaimer
    and footer run through `check_markup()` with the `restricted_html` format).
  - `hook_page_bottom()` renders the floating launch button on the front-end
    theme when enabled.
  - `hook_theme()` defines the floating button template.

### Frontend (React)

The widget lives in `react/` and is built with Vite. Source is in `react/src`,
and the committed build output is in `react/static/assets/` (`index.js`,
`index.css`). The compiled assets are what the site loads, so they must be
rebuilt and committed whenever the React source changes.

- `js/init.js` creates the `#yale-chat-widget` mount element and copies config
  onto it as `data-initial-questions`, `data-disclaimer`, and `data-footer`.
- `js/events.js` wires `href="#launch-chat"` links to open the modal.
- `react/src/index.tsx` mounts the React app into `#yale-chat-widget`.
- `react/src/api/api.ts` calls the backend. It fetches a fresh CSRF token from
  Drupal's `/session/token` endpoint at runtime (see Security).
- `ys_contoso_chat.libraries.yml` defines the `chat_widget` and `chat_button`
  libraries.

### Request flow

1. A visitor opens the widget (floating button or a `#launch-chat` link).
2. The React app fetches a CSRF token from `/session/token`.
3. Each message is POSTed to `/yale-chat/message` with the token and a
   `conversation_id`. A new conversation generates a new id; subsequent turns in
   the same session reuse it, which preserves multi-turn context.
4. The controller validates CSRF and the rate limit, loads the configured
   assistant, and streams the reply.
5. "New chat" discards the client conversation and starts a fresh
   `conversation_id`, so the next message begins a new server-side session.

## Source citations

Replies cite the indexed pages they draw from. The answer text gets inline
superscript markers, a "References:" row of buttons renders above the answer, and
each button opens a modal showing the source title (linked), its URL, and the
matched content. This is implemented across the agent, a custom RAG tool, a
request-scoped service, the controller, and the React app.

### How the Beacon assistant runs RAG

The configured assistant (`beacon`) is set with `ai_agent: beacon` and
`use_function_calling: false`, so it does not call tools directly — it delegates
to the `ai_agents` "beacon" agent (`config/sync/ai_agents.ai_agent.beacon.yml`).
That agent performs retrieval by invoking a function-call tool. We point it at a
custom tool instead of the contrib `ai_search:rag_search` so we can capture the
citation metadata that contrib discards.

### Components

- `src/Plugin/AiFunctionCall/BeaconRagTool.php` — a custom `AiFunctionCall`
  plugin, id `ys_contoso_chat:beacon_rag_search`, that extends contrib
  `RagTool`. It runs the same vector search against `beacon_index`, but for each
  retrieved chunk it:
  - labels the chunk with a 1-based `[docN]` marker in the text handed to the LLM,
    so the model can cite it;
  - reads the source metadata from the search result's extra data
    (`title_1`, `url_1`, `type`, `content`, `drupal_long_id`);
  - records an ordered citation row and pushes it into the citation store.
  The marker order, the stored citation order, and the frontend's `citations[N-1]`
  lookup must all agree, so a single loop index drives all three.
- `src/Service/CitationStore.php` (service `ys_contoso_chat.citation_store`) — a
  small request-scoped value holder. The tool runs deep inside the assistant
  runner; the store carries its citations back out to the controller within the
  same request. The controller calls `reset()` before each run so citations never
  leak between turns.
- `config/sync/ai_agents.ai_agent.beacon.yml` — the agent config:
  `tools: { 'ys_contoso_chat:beacon_rag_search': true }`, `tool_usage_limits`
  forcing `index=beacon_index`, `amount=10`, `min_score=0.5`, and a `## Citations`
  block in `system_prompt` instructing the model to cite with `[docN]` markers and
  to invent none. (The agent's `system_prompt` is the authoritative prompt at
  runtime; the assistant entity delegates to it.)
- `src/Controller/YsContosoChatController.php` — after `process()`, it reads
  `CitationStore::getCitations()` and, in `buildEnvelope()`, prepends a `tool`-role
  message carrying `{ citations, intent }` (JSON) ahead of the `assistant` message
  in the response envelope. Both the streamed and non-streamed paths forward the
  citations; in practice the agent returns a non-streamed `ChatMessage`, so the
  JSON envelope path is the one used.

### Frontend rendering

- `react/src/components/Answer/AnswerParser.tsx` — `parseAnswer` finds `[docN]`
  markers, replaces each with a superscript, de-dupes, and maps `[docN]` to
  `citations[N - 1]`. A marker is only rendered if its citation exists.
- `react/src/components/Answer/Answer.tsx` — renders the "References:" buttons row
  above the answer, but only when there are citations.
- `react/src/pages/chat/Chat.tsx` — `parseCitationFromMessage` reads the `tool`
  message into the citation list, and the citation modal (opened from a References
  button) shows the title (linked), the Source URL, and the Document Content.

  Important: after a response completes, the chat must persist the `tool` message
  ahead of the assistant message in `conversation.messages`
  (`push(toolMessage, assistantMessage)`). The render reads citations from
  `messages[index - 1]` relative to each assistant message, so dropping the tool
  message leaves citations empty and markers show as raw `[docN]` text.

### End-to-end flow

1. The agent calls `ys_contoso_chat:beacon_rag_search`. The tool searches
   `beacon_index`, labels results `[doc1]`, `[doc2]`, …, and stores a citation row
   per result.
2. The LLM writes its answer and cites the sources it used inline as `[docN]`.
3. The controller emits a `tool` message containing the citations ahead of the
   assistant text.
4. The React app parses both: markers become superscripts, the References row
   renders, and each button opens a modal for that source.

### Behavior and limits

- Citations render only for the `[docN]` markers the model actually emits. The
  model decides both whether to search and whether to cite, so vague questions can
  legitimately produce a reply with no citations. This is expected, not a bug.
- The modal's title and link come from the index attributes `title_1` and
  `url_1`. Indexes created before those fields existed will not populate them, so
  the source must be (re)indexed with the current schema.
- Unlike the prior Azure OpenAI "On Your Data" integration, which injected
  citations server-side, this agent-based stack relies on prompting the model to
  emit `[docN]`. It is therefore less deterministic; the system prompt is tuned to
  encourage consistent citing.

## Configuration

Settings form: `/admin/config/yale-chat/settings` (permission
`administer ys contoso chat`).

- Enable chat widget
- AI Assistant (the assistant entity that answers questions)
- Show floating launch button, floating button label, floating button icon
- Initial Prompt Suggestions (up to four)
- Disclaimer and Footer (CKEditor, `restricted_html` format, so links are
  allowed)

Default values ship in `config/install/ys_contoso_chat.settings.yml`. The schema
is in `config/schema/ys_contoso_chat.schema.yml`.

These settings are intentionally excluded from configuration import. The pattern
`ys_contoso_chat*` is listed in `config_ignore.settings.yml`, so per-site values
(enable state, prompts, disclaimer, footer, floating button) are never
overwritten by a deployment.

## Integration with Settings, Integrations

`ContosoChatIntegrationPlugin` registers the widget as a YaleSites integration.
It appears as a checkbox at `/admin/yalesites/integration-settings` and, once
enabled there, as a card with a Configure link at `/admin/yalesites/integrations`.
The settings menu link is parented to the Integrations menu so it sits alongside
the other integrations.

## Permissions

- `use ys contoso chat`: use the front-end chat widget. Granted to anonymous and
  authenticated users so site visitors can use it.
- `administer ys contoso chat`: configure the widget. Granted to platform_admin
  and site_admin.

## Security

### CSRF

The message and clear routes carry the `_csrf_request_header_token` requirement,
so Drupal core validates the `X-CSRF-Token` header. The token is fetched at
runtime from `/session/token`, which is never cached. This is enforced for
authenticated, session-bearing requests and is correctly skipped for anonymous
visitors, who have no session to forge. Tokens are not embedded in the page
markup, which avoids stale-token failures on cached pages.

### Rate limiting

`/yale-chat/message` is publicly callable and drives a paid AI backend, so it is
rate limited with Drupal's core flood service. The default is 30 requests per 60
seconds per client IP. The limit is per IP, not site-wide, so one IP reaching the
cap does not affect other visitors. Over the limit the endpoint returns HTTP 429
with a `Retry-After` header. The limits are constants in the controller
(`RATE_LIMIT`, `RATE_WINDOW`).

### Configuration management

See Configuration above. The widget settings are config-ignored so site-specific
content and toggles persist across deployments.

## Building the React app

```bash
cd react
npm install
npm run build
```

This regenerates `react/static/assets/index.js` and `index.css`. Commit those
build artifacts together with the source changes.

After building, rebuild the Drupal cache so the new library is served:

```bash
lando drush cr
```

When testing locally, also do a hard refresh in the browser (or use a private
window). Browsers cache `index.js` and `index.css`, so a stale bundle can hide a
fresh build. On a real deployment the asset query string changes on cache rebuild,
so end users receive the new assets automatically.

## Running tests

PHP unit tests live in `tests/src/Unit`. Run them with PHPUnit inside the Lando
container:

```bash
# All unit tests for this module
lando ssh -c 'export SIMPLETEST_DB="mysql://pantheon:pantheon@database/pantheon" && export SIMPLETEST_BASE_URL="http://appserver" && vendor/bin/phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_ai/modules/ys_contoso_chat/tests/src/Unit/'

# A single test file
lando ssh -c 'export SIMPLETEST_DB="mysql://pantheon:pantheon@database/pantheon" && export SIMPLETEST_BASE_URL="http://appserver" && vendor/bin/phpunit web/profiles/custom/yalesites_profile/modules/custom/ys_ai/modules/ys_contoso_chat/tests/src/Unit/ContosoChatIntegrationPluginTest.php'
```

Current coverage:

- `ContosoChatIntegrationPluginTest`: verifies the integration plugin reports the
  correct on/off state from config, points at the settings route, returns the
  expected build array (Configure link, plus a "not enabled" notice when off),
  and that its sync and save operations are no-ops.
- `CitationStoreTest`: set/get/reset round-trip and key reindexing for the
  citation store service.
- `BeaconRagToolTest`: with mocked result items, asserts the tool labels results
  `[doc1]`, `[doc2]`, … in order, stores an aligned citation row per kept result,
  honors `min_score` filtering with sequential numbering, and leaves the store
  empty when there are no results.
- `YsContosoChatControllerEnvelopeTest`: asserts `buildEnvelope` prepends a
  `tool`-role message (valid `{ citations, intent }` JSON) before the assistant
  message when citations are present, and emits only the assistant message when
  there are none.

The React app has no JavaScript test runner configured (Vite is used only for
building). Front-end behavior is currently validated manually. Adding a runner
such as Vitest would be a separate piece of work if automated JS tests are wanted.

## Key files

- `ys_contoso_chat.routing.yml`: routes for message, clear, and settings.
- `ys_contoso_chat.permissions.yml`: the two permissions.
- `ys_contoso_chat.links.menu.yml`: settings link under the Integrations menu.
- `src/Controller/YsContosoChatController.php`: message and clear endpoints;
  emits the citation `tool` message in the response envelope.
- `src/Form/YsContosoChatSettingsForm.php`: settings form.
- `src/Plugin/ys_integrations/ContosoChatIntegrationPlugin.php`: integration entry.
- `src/Plugin/AiFunctionCall/BeaconRagTool.php`: custom RAG tool that adds `[docN]`
  markers and collects citation metadata.
- `src/Service/CitationStore.php`: request-scoped store carrying citations from the
  tool to the controller (`ys_contoso_chat.services.yml` registers it).
- `config/sync/ai_agents.ai_agent.beacon.yml` (in the profile config sync, not this
  module): the Beacon agent config that points at the custom tool and carries the
  `[docN]` citation prompt.
- `ys_contoso_chat.module`: library attachment, floating button, theme.
- `react/src`: React source. `react/static/assets`: committed build output.
  Citation rendering lives in `components/Answer/AnswerParser.tsx`,
  `components/Answer/Answer.tsx`, and `pages/chat/Chat.tsx`.
- `templates/ys-contoso-chat-button.html.twig`: floating button markup.
