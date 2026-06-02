# Yale Chat (ys_contoso_chat)

A Yale-branded AI chat widget for YaleSites. It pairs a Drupal backend with a
React front end. The backend answers questions with the Drupal AI module
(`ai_assistant_api`) over a RAG index (`ai_search`), and the React widget renders
the conversation in a modal on the public site.

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

The React app has no JavaScript test runner configured (Vite is used only for
building). Front-end behavior is currently validated manually. Adding a runner
such as Vitest would be a separate piece of work if automated JS tests are wanted.

## Key files

- `ys_contoso_chat.routing.yml`: routes for message, clear, and settings.
- `ys_contoso_chat.permissions.yml`: the two permissions.
- `ys_contoso_chat.links.menu.yml`: settings link under the Integrations menu.
- `src/Controller/YsContosoChatController.php`: message and clear endpoints.
- `src/Form/YsContosoChatSettingsForm.php`: settings form.
- `src/Plugin/ys_integrations/ContosoChatIntegrationPlugin.php`: integration entry.
- `ys_contoso_chat.module`: library attachment, floating button, theme.
- `react/src`: React source. `react/static/assets`: committed build output.
- `templates/ys-contoso-chat-button.html.twig`: floating button markup.
