# Adding an LLM tool ("function call") to ys_beacon

Tracks issue #1146, which added the first one: `GetCurrentDateTime`
(`src/Plugin/AiFunctionCall/GetCurrentDateTime.php`). This is the reference
implementation for adding another.

## The pieces

1. **A `Drupal\ai\Plugin\AiFunctionCall` plugin** under
   `src/Plugin/AiFunctionCall/`, using the `ai` module's `#[FunctionCall]`
   attribute, `FunctionCallBase`, and `ExecutableFunctionCallInterface`
   (`GetCurrentDateTime.php` is the pattern to copy). Give the plugin `id` a
   `ys_beacon:` prefix (the module, not the function group — the attribute's
   own docblock claims the `id` must be prefixed by `group`; that is wrong,
   verified against real plugins in contrib, e.g. `ai_search:rag_search` has
   group `information_tools`). Declare `group: 'information_tools'` if the
   tool genuinely provides the model contextual information (this is metadata
   only - see the allow-list below for what actually controls exposure).
2. **An entry in the allow-list argument of the `ys_beacon.tool_call_handler`
   service definition, in `ys_beacon.services.yml`** - add the new plugin's
   id to that array (`ToolCallHandler`'s third constructor argument). **This
   is the only place that controls what the model can actually call - and
   `ToolCallHandler` enforces it twice**, not just once: `buildToolsInput()`
   only ever _offers_ the allow-listed plugins, and `executeOne()` separately
   checks the allow-list again before _executing_ a returned call. The second
   check matters on its own: `FunctionCallPluginManager::
convertToolResponseToObject()` resolves a tool call's function_name
   against every plugin **registered on the site**, not just the ones this
   handler offered - so without that second check, a model that named an
   unoffered plugin (via prompt injection, since indexed site content reaches
   the model) could still have it executed. Do not rely on function-group
   membership for exposure either:
   `ai_search`'s own `rag_search` plugin already lives in `information_tools`,
   and if `ToolCallHandler` ever resolved tools by scanning the group instead
   of an explicit list, that unrelated plugin would be offered to the model on
   every Beacon turn - bypassing ys_beacon's own RAG retrieval and citation
   tracking entirely. Keep the allow-list explicit.

Nothing else needs to change. `ToolCallHandler` (`src/Service/ToolCallHandler.php`)
already handles, for any allow-listed plugin: building the `ToolsInput` sent to the
model (`attachTools()`), detecting a returned tool call on either the streamed or
non-streamed path, executing it, and assembling the follow-up request
(`followUpInput()`, which wraps `extractToolCalls()` and
`buildFollowUpMessages()`) - absorbing any failure into a plain-text error result
rather than breaking the turn. Both `ChatApiController` (streamed, the live chat
endpoint) and `BeaconAnswerService` (non-streamed, used by `ys_ai_tester`) call the
same two methods, so a new tool gets both surfaces for free. Keep it that way: the
shared half belongs on the handler, not copied into a caller.

### Watch the input token budget when adding a tool

`ChatApiController` windows the transcript to the model's context window before the
first call (`windowTranscriptToBudget()`), and the tool round trip is _not_ counted
against that budget - neither the tool schema attached to the request nor the
assistant echo and tool result appended for the follow-up call. Today's single tool
costs roughly 160 tokens all in, comfortably inside the existing
`SAFETY_MARGIN_TOKENS` (2048), so there is no live overflow. A tool with a large
`getReadableOutput()` changes that: budget for it explicitly (subtract a tool
reserve before windowing) rather than assuming the margin absorbs it.

## Round trip, in one turn

1. `attachTools()` puts the allow-listed tools' JSON-schema declarations on the
   `ChatInput` before the first call to the provider.
2. If the model's response is a tool call, `extractToolCalls()` reads it off
   the normalized output (a plain `ChatMessage` for the non-streamed path; a
   fully-drained `StreamedChatMessageIteratorInterface` for the streamed one -
   its tool calls are only assembled after the iterator finishes, which the
   controller's answer-streaming loop already does as a side effect).
3. `buildFollowUpMessages()` executes the tool (via
   `FunctionCallPluginManager::convertToolResponseToObject()` +
   `validateContexts()` + `execute()`) and returns an assistant message
   echoing the tool call plus one `tool`-role message per call, carrying the
   result.
4. `followUpInput()` appends those to the transcript and hands the caller a
   `ChatInput` with **no tools attached** - so the turn resolves in exactly one
   extra hop. A tool that legitimately needs to chain into a second tool call
   is out of scope for this pattern; nothing here loops.
5. The caller makes that second call and streams or returns its answer. If a
   turn ends up producing no assistant text at all - a tool call that could not
   be assembled or run, or a stream that died before its first token -
   `ChatApiController` reports the turn as failed rather than sending an empty
   answer, because a blank reply is indistinguishable in the widget from one
   still arriving.

## Constructor injection for a plugin that needs a service

`FunctionCallBase`'s own `create()` only injects the two managers every
function-call plugin needs (`ai.context_definition_normalizer`,
`plugin.manager.ai_data_type_converter`). To inject anything else (a Drupal
service, like `GetCurrentDateTime`'s `datetime.time` and `date.formatter`),
override `create()` and pass the extra services as further constructor
arguments, keeping the two parent-required ones (see
`RagTool.php` in `ai_search` for the pattern this was copied from,
and `GetCurrentDateTime::create()` for a worked example).

## Verifying against a real model

Most of the round trip above is covered by tests using a provider double that
returns a synthetic tool-call message, not a live model - but with a real
Portkey key configured locally, a real model genuinely does call the tool
end to end (confirmed live for #1146, via the chat widget). Do this before
considering a new tool done; a mocked round trip proves the wiring, not that
a real model reliably calls the tool with sensible arguments.

**A live model surfaces failure modes a mock cannot**, because it can send a
provider-specific wire shape a mock never would. #1146 hit exactly this:
a zero-argument tool call (a tool whose only parameters were all optional)
arrived with a genuinely empty `arguments` string rather than `"{}"`, and both
of contrib's own assembly points (`StreamedChatMessageIterator::
assembleToolCalls()`, and `OpenAiBasedProviderClientBase::chat()`'s
non-streamed branch) unconditionally `Json::decode()` that string before
constructing a `ToolsFunctionOutput`, whose `$arguments` parameter is
non-nullable - so a genuinely empty string TypeErrors there, inside contrib
code this module cannot patch.

**Give every tool at least one required parameter.** This is the actual fix,
not just a mitigation: a required parameter forces the JSON schema offered to
the model to mark it `"required"`, so a compliant model can never send an
empty `arguments` object, which means contrib's `Json::decode()` never sees
`''`. `GetCurrentDateTime`'s `timezone` context is declared `required: TRUE`
_and_ keeps a `default_value` - Drupal's own `Context::getContextValue()`
falls back to the default whenever no value was ever explicitly set,
regardless of `required`, so the plugin still tolerates a model that omits the
key or sends an invalid timezone (`execute()` catches an invalid
`\DateTimeZone` and falls back to the default). If a tool has no naturally
required parameter, add one anyway (e.g. a required enum with a sensible
default) rather than shipping an all-optional signature.

`ToolCallHandler::extractToolCalls()` and `ChatApiController::emitAnswer()`'s
streamed drain loop both still catch a failure while assembling tool calls
(logging and continuing rather than breaking the turn) - keep this as
defense-in-depth for any other contrib-side assembly failure, but do not treat
it as a substitute for the required-parameter fix: absorbing the failure
produces a turn with no tool result and no assistant text, not a working
tool call. The Kernel test `testExtractToolCallsAbsorbsRealEmptyArgumentsToolCall`
reproduces the empty-arguments TypeError through the real
`StreamedChatMessageIterator` to prove the absorption still works, but the
tool itself is exercised live before being considered done (see above).

**A second contrib sharp edge, on the non-streamed path only:** a model can name
a `function_name` that was never offered - a hallucination, or prompt injection
from indexed page content. Contrib builds that call as
`new ToolsFunctionOutput($input->getChatTools()->getFunctionByName($name), ...)`,
and `ToolsInput::getFunctionByName()` returns `NULL` for a function it does not
know. `ToolsFunctionOutput::__construct()` only calls `setName()` when that
argument is non-`NULL`, leaving its non-nullable `string $name` uninitialized, so
`getName()` raises an `Error` rather than returning a name to reject. That is why
`ToolCallHandler::executeOne()` reads the name _inside_ its own `try` - otherwise
the allow-list check would be unreachable in exactly the injected case it exists
for, and the whole turn would fail instead of the one tool call. The streamed
path always sets a name, so this is easy to miss when testing only the live
widget.
