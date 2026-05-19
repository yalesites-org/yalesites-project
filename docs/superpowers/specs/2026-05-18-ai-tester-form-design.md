# AI Tester Form Design

**Date:** 2026-05-18
**Status:** Approved

## Context

The YaleSites AI module needs a way to bulk-test a RAG chatbot assistant by uploading a YAML file of questions and reviewing the answers. The previous approach (Python CLI tool hitting a custom HTTP endpoint) required auth complexity. This design replaces it with a Drupal admin form that runs questions through `ai_assistant_api.runner` directly server-side, stores results persistently across runs, and presents a history table so teams can compare outputs over time.

## Goals

- Upload a YAML file of questions, select an AI assistant, run all questions through the chatbot
- Process questions via Drupal Batch API to avoid PHP timeouts on long lists
- Store every run and its results in the database permanently
- Let all users with the tester permission view the full run history
- View per-run results in a detail page and download as JSON or YAML

## Permissions

New permission added to `ys_ai.permissions.yml`:

```yaml
use ys ai tester:
  title: 'Use YaleSites AI Tester'
  description: 'Access the AI batch tester, view run history, and download results.'
```

All tester routes require this permission. It is intentionally separate from `configure ys ai admin settings` so tester access can be granted independently.

## Database Schema

### `ys_ai_tester_run`

| Column | Type | Description |
|---|---|---|
| `id` | serial (PK) | Auto-increment run ID |
| `uid` | int unsigned | Drupal user ID who created the run |
| `assistant_id` | varchar(255) | ID of the AiAssistant entity used |
| `created` | int unsigned | Unix timestamp |
| `yaml_filename` | varchar(255) | Original uploaded filename |
| `yaml_content` | longblob | Full YAML file contents |
| `status` | varchar(16) | `processing`, `complete`, or `failed` |
| `question_count` | int unsigned | Total number of questions in the run |

### `ys_ai_tester_result`

| Column | Type | Description |
|---|---|---|
| `id` | serial (PK) | Auto-increment result ID |
| `run_id` | int unsigned | FK → `ys_ai_tester_run.id` |
| `delta` | int unsigned | Position of question within the run (0-based) |
| `question` | text | The question text |
| `answer` | longtext | Raw markdown answer from the assistant |
| `citations` | text | JSON-encoded array of citation URLs extracted from the answer |

## Routes

```yaml
ys_ai.tester:
  path: '/admin/config/yalesites/ys_ai/tester'
  # AiTesterForm — upload form + run history table
  permission: 'use ys ai tester'

ys_ai.tester_run:
  path: '/admin/config/yalesites/ys_ai/tester/{run_id}'
  # AiTesterController::run() — detail page
  permission: 'use ys ai tester'

ys_ai.tester_download_json:
  path: '/admin/config/yalesites/ys_ai/tester/{run_id}/download/json'
  # AiTesterController::downloadJson() — JSON file response
  permission: 'use ys ai tester'

ys_ai.tester_download_yaml:
  path: '/admin/config/yalesites/ys_ai/tester/{run_id}/download/yaml'
  # AiTesterController::downloadYaml() — YAML file response
  permission: 'use ys ai tester'
```

## Components

### `ys_ai.install`

Implements `hook_schema()` to define both tables. Also implements `hook_update_N()` if this is added to an existing installation (creates tables on update).

### `ys_ai.info.yml`

Add dependency: `ai:ai_assistant_api`

### `src/Form/AiTesterForm.php`

Extends `FormBase`. Single page showing:

**Form fields:**
- `yaml_file` — managed file upload, accepts `.yml`/`.yaml`, required
- `assistant_id` — if user has `administer ai providers`: `select` element populated from all `AiAssistant` entities loaded via `entityTypeManager`; otherwise: hidden field using `ys_ai.settings:default_tester_assistant` config value

**Below the form:** a render array table of all runs from `ys_ai_tester_run`, ordered by `created` DESC, columns: Date | User | Assistant | File | Questions | Status | Actions (View link).

**`submitForm()`:**
1. Parse uploaded YAML into an array of question strings
2. Validate that all values are strings — if any value is not a string, call `$form_state->setError()` and return early without creating a run row
3. Insert a new row into `ys_ai_tester_run` with status `processing`
3. Build a batch definition — one operation per question calling `AiTesterBatch::processQuestion()`
4. Set `finished` callback to `AiTesterBatch::finished()`
5. Call `batch_set()` — Drupal redirects to the batch progress page automatically

### `src/AiTesterBatch.php`

Static class with two public static methods (required by Drupal Batch API):

**`processQuestion(int $run_id, string $assistant_id, string $question, int $delta, array &$context)`**
1. Load `AiAssistant` entity via `\Drupal::entityTypeManager()`
2. Get runner via `\Drupal::service('ai_assistant_api.runner')`
3. Call `setAssistant()`, `setUserMessage(new UserMessage($question))`, `setThrowException(TRUE)`, `process()`
4. Get `$text = $response->getNormalized()->getText()`
5. Extract citation URLs via regex: `/\[.*?\]\((https?:\/\/[^)]+)\)/`
6. Insert row into `ys_ai_tester_result` with `run_id`, `delta`, `question`, `answer`, `citations` (JSON-encoded)
7. On exception: log error, insert row with empty answer and `['error' => $e->getMessage()]` as citations

**`finished(bool $success, array $results, array $operations)`**
1. Update `ys_ai_tester_run.status` to `complete` (or `failed` if `!$success`)
2. Set a Drupal status/error message
3. Redirect to `/admin/config/yalesites/ys_ai/tester` via `\Drupal::request()` batch finish (Drupal handles redirect automatically after batch)

### `src/Controller/AiTesterController.php`

Extends `ControllerBase`. Three methods:

**`run(int $run_id)`**
- Load run row from DB; 404 if not found
- Load all results for the run ordered by `delta`
- Return a render array with: run metadata, a table of results (Question | Answer | Citations), links to JSON and YAML download routes

**`downloadJson(int $run_id)`**
- Load run + results from DB
- Decode `citations` JSON per result
- Return `JsonResponse` with `Content-Disposition: attachment; filename="run-{$run_id}.json"` containing array of `{question, answer, citations}`

**`downloadYaml(int $run_id)`**
- Load `yaml_content` from run row
- Return `Response` with `Content-Type: application/x-yaml`, `Content-Disposition: attachment; filename="{$yaml_filename}"`

## Assistant Selector Logic

In `AiTesterForm::buildForm()`:

```php
if ($this->currentUser()->hasPermission('administer ai providers')) {
  $assistants = $this->entityTypeManager()->getStorage('ai_assistant')->loadMultiple();
  $options = array_map(fn($a) => $a->label(), $assistants);
  $form['assistant_id'] = ['#type' => 'select', '#options' => $options, ...];
} else {
  $default = $this->config('ys_ai.settings')->get('default_tester_assistant');
  $form['assistant_id'] = ['#type' => 'hidden', '#value' => $default];
}
```

The `default_tester_assistant` config key is set via:
```bash
lando drush config-set ys_ai.settings default_tester_assistant "beacon"
```

## YAML Input Format

Same format as the original `ai-tester-py` tool:

```yaml
- Who are you?
- How can I use my pcard?
- |
  Using a pcard can I do the following:
  * Purchase from Amazon
```

A flat YAML sequence of strings. Multi-line questions supported via `|` syntax.

## Verification

```bash
# Install updated module (creates DB tables)
lando drush updb -y
lando drush cr

# Set default assistant for non-admin users
lando drush config-set ys_ai.settings default_tester_assistant "beacon"

# Grant permission to a role
lando drush role:perm:add administrator 'use ys ai tester'
```

Then in the browser:
1. Log in as a user with `use ys ai tester` permission
2. Navigate to `/admin/config/yalesites/ys_ai/tester`
3. Upload a `.yml` file with 2-3 questions, select an assistant, submit
4. Watch the batch progress bar complete
5. Verify the run appears in the history table with status `complete`
6. Click View → confirm results table shows question/answer/citations
7. Click Download JSON → verify file downloads with correct structure
8. Click Download YAML → verify original YAML file downloads
9. Run a second batch → confirm both runs appear in history table
