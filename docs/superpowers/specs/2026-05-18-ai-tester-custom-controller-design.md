# AI Tester Custom Controller Design

**Date:** 2026-05-18
**Status:** Approved

## Context

`ai-tester-py` is a Python CLI tool that bulk-tests a RAG chatbot by sending questions from a YAML file and exporting answers + citations. The existing Drupal AI chatbot endpoint (`/api/deepchat`) requires a session cookie and a path-scoped CSRF token obtained from a separate session endpoint — both protected by a Drupal permission that anonymous users do not have by default. Rather than grant anonymous access to the contrib module's endpoints, we expose a dedicated testing endpoint in `ys_ai` that authenticates via a simple API key header.

## Architecture

```
Python tool (ai-tester-py)            Drupal (ys_ai)
--------------------------            --------------
POST /api/ys-ai-tester           →    AiTesterController::query()
  X-Api-Key: <secret>                   │
  {"assistant_id": "...",               ├── validate key vs ys_ai.settings:tester_api_key
   "question": "..."}                   ├── load AiAssistant entity
                                        ├── ai_assistant_api.runner->process()
                                        ├── extract plain text + citation URLs (regex on markdown)
                                        └── JsonResponse
  ← {"question": "...",
      "answer": "...",
      "citations": ["url1", "url2"]}
```

No session, no CSRF token. The route is open (`_access: 'TRUE'`) and relies entirely on the API key for authentication.

## Drupal Changes (`ys_ai` module)

### `ys_ai.info.yml`

Add dependency:
```yaml
dependencies:
  - ai:ai_assistant_api
```

### `ys_ai.routing.yml`

Add new route:
```yaml
ys_ai.tester_api:
  path: '/api/ys-ai-tester'
  defaults:
    _controller: '\Drupal\ys_ai\Controller\AiTesterController::query'
    _format: json
  requirements:
    _access: 'TRUE'
    _method: POST
```

### `src/Controller/AiTesterController.php`

New controller with one public method `query(Request $request)`:

1. Read `X-Api-Key` header. Compare against `ys_ai.settings:tester_api_key` config. Return `401` if the header is missing, if the config value is empty/unset, or if the values do not match.
2. Decode JSON body. Return `400` if `assistant_id` or `question` is absent.
3. Load `AiAssistant` entity by `assistant_id`. Return `422` if not found.
4. Inject and call `ai_assistant_api.runner`:
   - `setAssistant($assistant)`
   - `setUserMessage(new UserMessage($question))`
   - `setThrowException(TRUE)`
   - `$response = process()`
5. Get raw markdown text: `$text = $response->getNormalized()->getText()`
6. Extract citation URLs from markdown links via regex: `/\[.*?\]\((https?:\/\/[^)]+)\)/`
7. Return `JsonResponse(['question' => $question, 'answer' => $text, 'citations' => $urls])`

Dependencies injected via `create()`: `config.factory`, `ai_assistant_api.runner`.

### Setup (one-time per environment)

```bash
lando drush config-set ys_ai.settings tester_api_key "your-secret-key"
lando drush cr
```

## Python Changes (`test-questions.py`)

### Remove
- `create_session()` function
- `parse_html()` function and `HTMLParser` import
- CSRF token variable and header
- `requests.Session` usage

### Add
- `--api-key` CLI argument (required)

### Change
- Endpoint: `/api/ys-ai-tester`
- Payload: `{"assistant_id": args.assistant_id, "question": question}`
- `fetch_data()`: plain `requests.post(url, json=payload, headers={"X-Api-Key": args.api_key})`
- Response handling: `response.json()` returns `{question, answer, citations}` directly — no HTML parsing

### `outputOptions.py`
No changes required. Receives the same `{question, answer, citations}` keys it always expected.

## Request / Response Contract

**Request:**
```
POST /api/ys-ai-tester
Content-Type: application/json
X-Api-Key: <secret>

{"assistant_id": "beacon", "question": "How do I apply for a grant?"}
```

**Success response (200):**
```json
{
  "question": "How do I apply for a grant?",
  "answer": "To apply for a grant, visit the [grants portal](https://example.yale.edu/grants)...",
  "citations": ["https://example.yale.edu/grants"]
}
```

**Error responses:**
- `401` — missing or invalid `X-Api-Key`
- `400` — missing `assistant_id` or `question`
- `422` — unknown `assistant_id`
- `500` — AI processing error (logged to Drupal watchdog)

## Verification

```bash
# 1. Set the API key
lando drush config-set ys_ai.settings tester_api_key "test-key-local"
lando drush cr

# 2. Smoke test the endpoint directly
curl -X POST http://yalesites-drupalai.lndo.site/api/ys-ai-tester \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: test-key-local" \
  -d '{"assistant_id": "beacon", "question": "Who are you?"}'

# 3. Run the bulk tester
cd /Users/db2553/code/ai-tester-py
python test-questions.py http://yalesites-drupalai.lndo.site \
  --assistant-id beacon \
  --api-key test-key-local \
  --format json

# 4. Verify 401 on bad key
curl -X POST http://yalesites-drupalai.lndo.site/api/ys-ai-tester \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: wrong" \
  -d '{"assistant_id": "beacon", "question": "test"}'
```
