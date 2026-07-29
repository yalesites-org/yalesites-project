# YS AI Tester legacy assistant

**This module is temporary.** It exists to support and defend the migration from
the legacy `ai_engine` chat to Beacon, by letting the AI Tester run one question
list against both assistants and read the answers side by side. It is expected to
be removed in a later release — see [Removing this module](#removing-this-module).

## What it adds

The AI Tester's assistant is normally Beacon. This module registers a second
answer backend, so the tester form offers a choice: run the question list against
Beacon, against the legacy assistant, or against both at once. Running both
records two runs over an identical list and lands on the tester's existing
comparison view, with each side labelled by the assistant that answered it.

Because `RunComparator` pairs results by question text rather than row order, a
Beacon run and a legacy run over the same list already align — the comparator
needed no changes.

## How it talks to the legacy assistant

`ai_engine` has no server-side answer path: answering lives entirely in the
external Azure app that the React chat widget calls. `LegacyConversationClient`
is the smallest possible PHP mirror of that call — it POSTs a one-message
transcript to `{azure_base_url}/conversation` and hands the newline-delimited JSON
reply to `LegacyStreamParser`, which concatenates the assistant deltas and reads
the citation list out of the `role: "tool"` message. Nothing inside `ai_engine`
is modified.

Both timeouts are explicit (`REQUEST_TIMEOUT`, `CONNECT_TIMEOUT` on
`LegacyConversationClient`) so a single hung legacy request cannot stall a whole
batch. A failed or timed-out question is logged, recorded against that row, and
the rest of the batch continues.

The parser accumulates text until it parses rather than assuming one complete
JSON object per line. Note what that does and does not defend against: the client
buffers the whole response body, so a mid-object network chunk boundary — the case
the browser widget genuinely faces over a streamed `fetch` — cannot reach this
parser. It tolerates a producer that spreads an envelope over several lines, and
keeps the parser correct if the client is ever switched to a real stream.

Legacy citations need no bespoke normalization: they already carry the same keys
as Beacon's (both descend from the Azure "on your data" citation shape), and
legacy answers reference sources with the same `[docN]` markers, so the tester
batch's shared `ys_beacon.citation_formatter` derives an equivalent `cited` flag.
Citation overlap and cited-source counts are therefore meaningful on both sides.

Formatting happens in `AiTesterBatch`, not in this module: a backend returns the
assistant's answer and its raw retrieved sources, and the tester core owns the
stored citation shape. That is why this module has no dependency on the citation
formatter, and why a backend cannot accidentally store a shape `RunComparator`
would silently miscount. If a future assistant cited sources with markers other
than `[docN]`, that assumption would need revisiting — it holds for these two.

## When the option appears

The legacy option is offered only when **both** are true:

1. `ai_engine_chat` is installed, and
2. `ai_engine_chat.settings:azure_base_url` is non-empty.

Otherwise the tester presents no selector and no compare-both option — it behaves
exactly as a Beacon-only tester, with no warning and no disabled control.

Availability is deliberately **not** keyed on `ai_engine_chat.settings:enable`,
`LegacyAiEngine::isActive()` or `LegacyAiEngine::chatActive()`. Cutover sets those
to FALSE while leaving `azure_base_url` in place, and `'ai_engine*'` is
config-ignored so `drush deploy` does not revert it. A cut-over site that still
has a base URL is the primary case for this comparison, not an edge case.

This module does **not** declare a dependency on `ai_engine_chat`, on purpose: a
hard dependency would make Drupal refuse to uninstall `ai_engine_chat` during
cutover cleanup. The check is made at runtime instead.

## Removing this module

Removal is designed to be a clean, one-way door. Beacon-only testing keeps
working at every step.

Uninstalling removes the feature outright: the backend is registered through the
`ys_ai_tester.answer_backend` service tag, so the option disappears with the
service, and the tester core never references this module.

Runs already recorded against the legacy assistant stay viewable and comparable —
the run history and comparison view fall back to showing the stored backend id
when no service can be asked for a label. Only the creation of new legacy runs
stops, and re-running a stored legacy run is refused with an explanatory message
rather than erroring mid-batch.

**Do the removal across two releases, and do not delete the code and the
`core.extension.yml` line in the same one.** The platform removes modules through
`drush deploy`, not by running `drush pm:uninstall` on each site by hand.
`config:import` has to *uninstall* this module, which needs its code present to
resolve `getPath()` — with the directory already gone it throws
`UnknownExtensionException`, and installing any other module in that same import
rebuilds the module list from active `core.extension` and trips the same error.
This is not hypothetical: it is what happened to `ys_servicenow` (YaleSites-Internal
issue #1372, PRs #1360 then #1364). The tell at `updatedb` bootstrap is the
requirements warning "marked as installed in the core.extension configuration, but
it is missing".

1. **Release N — drop the config, keep the code.** Delete the
   `ys_ai_tester_legacy: 0` line from
   `web/profiles/custom/yalesites_profile/config/sync/core.extension.yml` and
   leave this directory in place. On deploy, `config:import` uninstalls the module
   cleanly — the code is present and there is no `hook_uninstall` or installed
   config to clean up. Afterwards, confirm the module is gone from each site's
   **active** `core.extension`; a green deploy on its own is not proof, because an
   import with no module-install step will not trip the crash while the stale
   entry survives.

2. **Release N+1 — delete the code.** Delete this module directory and this
   README. No other file changes; `ys_ai_tester` and `ys_beacon` should stay
   green.

   If waiting a release is unacceptable, the single-release alternative is the
   `ys_servicenow` pattern: add a `hook_update_N` to `ys_ai_tester` that strips
   the module from active `core.extension` and cleans its `system.schema`
   key_value entry, then delete the directory in the same commit. `updatedb` runs
   before `config:import` in `drush deploy`, so it clears in time. Note a
   `hook_update_N` runs once — a site whose schema has already advanced past it
   needs the removal re-issued under a fresh, higher number.

3. **Optionally drop the `backend` column** from `ys_ai_tester_run` with one
   update hook in `ys_ai_tester`, once nobody needs to tell historical legacy runs
   apart:

   ```php
   \Drupal::database()->schema()->dropField('ys_ai_tester_run', 'backend');
   ```

   Leave the column in place if the comparison evidence is still being cited —
   without it, legacy runs become indistinguishable from Beacon runs.

4. **Optionally collapse the backend seam** in `ys_ai_tester`: with only Beacon
   left, `AnswerBackendInterface`, `AnswerBackendRegistry` and
   `Backend\BeaconAnswerBackend` can fold back into `AiTesterBatch`, and
   `AiTesterForm::backendChoices()`/`resolveRunBackends()` can go, along with
   `AiTesterController::crossAssistantCaveat()` and the `.ys-compare-caveat`
   rule in `css/compare.css` (which can no longer fire once every run is
   Beacon). This is optional cleanup — the seam is harmless with a single
   backend. Keep the `error` column: any assistant can fail, so it is not
   legacy-specific.

The feature also retires itself: once `ai_engine_chat` is uninstalled
platform-wide, the legacy option stops appearing even if this module is still
installed.
