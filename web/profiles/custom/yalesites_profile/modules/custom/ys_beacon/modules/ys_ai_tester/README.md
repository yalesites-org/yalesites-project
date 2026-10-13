# YaleSites AI Tester

`ys_ai_tester` is the internal tool we use to run a list of questions through Beacon and read the answers back. It is a submodule of `ys_beacon`. We use it to check what Beacon says about a site's content, and to compare two runs side by side. The results download as files we can hand to someone else.

It is for the YaleSites team and for the site admins who look after a Beacon-enabled site. Nothing here is visible to visitors or editors.

## Access

Every route in this module sits behind two gates. Both must pass.

1. **The `use ys ai tester` permission.** On the platform it is held by the `platform_admin` and `site_admin` roles (`config/sync/user.role.*.yml` in the profile). It carries `restrict access: true`, so the permissions page flags it as sensitive.
2. **The `_ys_beacon_authorized` route requirement.** This is `BeaconAuthorizedAccessCheck` in `ys_beacon`. It reads one flag, `ys_beacon.settings:platform_authorized`, through the `ys_beacon.authorization` service. A platform admin turns that flag on per site from the Platform Admin Settings page. Until it is on, the tester's routes and menu link are denied for everyone, whatever their role.

The pacing comment in `AiTesterBatch::pace()` calls the audience "a handful of platform operators". The permission is wider than that: any site admin on an authorized site can run the tester.

The tester also has a card on the Integrations dashboard (`/admin/yalesites/integrations`). The card offers its "Open AI Tester" link only when `ys_beacon.settings:enable_chat` is on.

## Running a test

Open **AI Tester** under YaleSites Beacon in the admin menu, or go to `/admin/config/yalesites/ys-beacon/tester`.

**Questions.** One question per line. Blank lines are dropped and each line is trimmed. Either upload a `.txt` file or type the questions into the text box, not both. The limit either way is 256 KB. A typed list is stored under the filename `typed-questions.txt`.

**Assistant.** Beacon is the default and the only built-in assistant. A selector appears only when a second backend is registered. Today that is the `ys_ai_tester_legacy` submodule, which adds the legacy `ai_engine` assistant. With two backends you can run the list against one, or against both at once. "Both" records two runs over the same list and lands you on the comparison view.

**During a run.** The form queues one Batch API operation per question, so you watch a progress bar. Each question goes through `BeaconAnswerService`, the non-streamed twin of the chat endpoint. The answer and its retrieved sources are stored as one row. A question that fails is logged, recorded against its row, and the run keeps going. Transient failures (5xx, 429, 408, a dropped connection) are retried up to three times with backoff. Deterministic ones are not.

When the batch finishes, the run gets a status: `complete` (every question answered), `partial` (some answers, some errors), or `failed` (nothing usable). A batch that stops without reporting, usually a dropped browser connection, is left on `processing`. `StaleRunReconciler` moves it to `partial` or `failed` after 10 minutes of silence. It runs on cron and whenever the tester, rerun or resume forms load.

**Throttle.** Before every question after the first, the batch sleeps for `ys_beacon.settings:ai_tester_question_delay_ms` (default 500 ms, maximum 10 s, 0 to turn it off). The knob is on the Beacon administration form (`/admin/config/yalesites/ys-beacon/admin`, user 1 only), under "AI Tester". It is a blocking `usleep` on purpose. The worker is already held for the request to the assistant. The progress bar and the status reads depend on batch semantics. And only a few people run it. `AiTesterBatch::pace()` records the reasoning. Raise the value if long runs report questions that fail here but answer fine when asked on their own.

**Rerun and resume.** The run history table offers two repair actions on finished runs. **Rerun** asks the whole list again as a new run against the same assistant, and keeps the original for comparison. **Resume** appears only when a run has questions with no stored row. It asks just those and writes them into the same run. It never overwrites an answer, so it is safe to repeat. Both refuse to act on a run that is still processing.

**Prune.** The "Prune run history" section deletes everything beyond the last 5, 10 or 15 runs.

## Reading results

**Run view** (`/tester/{run_id}`). A header line (run id, date, file, assistant, status), the three download links, then one tab per question. Each tab carries a badge: Answered, No answer, or Error. The panel shows the answer, an error or "Empty answer" line where relevant, and a Sources list. Every source the assistant retrieved is listed, flagged "cited" or "retrieved, not cited", with its URL under the title. What Beacon had and chose not to use is as useful as what it quoted.

**Compare view** (`/tester/compare/{run_a}/{run_b}`). Pick two runs in the history table and press "Compare selected", or run "Both". The older run is always Run A. The page opens with a summary line: compared, differ, identical, only in A, only in B. Below it sit a meta block per run (including the host its citations point at), a legend, the download links, and the question tabs with two columns per panel. Words that appear in only one answer are highlighted in that run's color. A source that only one run retrieved carries an "only in this run" badge.

Questions are paired by their text, not by position, so two runs over the same list align even when different assistants answered them. When the two runs used different assistants, a caveat says so. A high "differs" count between Beacon and the legacy assistant is expected, not a regression.

Citation overlap ignores each run's own host. A multidev run and a production run over the same content therefore report shared sources. Foreign hosts are kept, so two sites' `/about` pages are never counted as one.

The compare view is a wide layout. On small screens it shows a notice instead.

## Exports

The run view offers three downloads and the compare view two more, plus a modal. `RunExporter` builds every file body. The controller only adds the HTTP envelope.

| File | Route | What it holds | Useful for |
|---|---|---|---|
| `run-N.json` | `/tester/N/download/json` | One entry per question: question, answer, error, and the full citation list, including each source's stored `content`. | Scripted analysis, or keeping a run as evidence with every field the UI shows. |
| `run-N.csv` | `/tester/N/download/csv` | Columns Question, Answer, Error, Sources (URLs joined with a pipe). | Opening in Excel or Sheets to score answers by hand. |
| `run-N-questions.txt` | `/tester/N/download/questions` | The question list as stored, one per line. | Editing and re-uploading as a new run, or running the same set on another site. |
| `compare-A-B.json` | `/tester/compare/A/B/download/json` | Both runs' meta, the summary counts, and one pair per question: each side's answer, error, length, cited and retrieved counts, citations (excerpts only, `content` stripped), and the citation overlap. | Handing to an AI assistant for analysis. The `beacon-comparison-review` skill reads this file. |
| `compare-A-B.csv` | `/tester/compare/A/B/download/csv` | One row per question: status, both answers, both errors, cited counts, lengths, and the shared, only-A and only-B source URLs. | Spreadsheet review of a comparison. |

**Export for AI analysis** (`/tester/compare/A/B/export`) is a modal on the compare view, not a new file. It holds a ready-made research prompt that explains the comparison JSON's structure, a "Copy prompt" button, and a download link for the same `compare-A-B.json`. Attach both to whichever AI assistant you use.

Both CSVs start with a UTF-8 BOM so accented characters open cleanly in Excel. Every cell goes through `RunExporter::csvCell()`. It prefixes a quote to any value starting with `=`, `+`, `-` or `@`, so a spreadsheet cannot run it as a formula. Multiline answers stay in one cell.

## Environments

A run needs an environment with live Beacon credentials: the Portkey and Azure AI Search keys that resolve from Pantheon secrets, and a populated index. Production qualifies, and so does a local Lando site set up with its own keys as the Beacon README describes.

A multidev does not. It has no live credentials, so a run started there records an error for every question. **A multidev cannot complete a real run.** When a PR's testing steps say "with a completed run", the rows on that multidev were seeded straight into `ys_ai_tester_run` and `ys_ai_tester_result`. That is fine for checking the views and the exports, and it is the only way to control an answer's exact text. It tells you nothing about Beacon's answers.

Seeding rows works locally too, when a test needs answers it can predict.

## Where things live

Module root: `web/profiles/custom/yalesites_profile/modules/custom/ys_beacon/modules/ys_ai_tester/`. There is no `web/modules/custom/ys_ai_tester`.

### Routes

All eleven are under `/admin/config/yalesites/ys-beacon/tester`. All require `use ys ai tester` plus `_ys_beacon_authorized`.

| Path (after the prefix) | Route name | Shows |
|---|---|---|
| `/` | `ys_ai_tester.tester` | The run form, prune controls and run history. |
| `/{run_id}` | `ys_ai_tester.run` | One run's header, downloads and question tabs. |
| `/{run_id}/rerun` | `ys_ai_tester.rerun` | Confirm page that re-asks the whole list as a new run. |
| `/{run_id}/resume` | `ys_ai_tester.resume` | Confirm page that asks only the unanswered questions. |
| `/{run_id}/download/json` | `ys_ai_tester.download_json` | `run-N.json`. |
| `/{run_id}/download/csv` | `ys_ai_tester.download_csv` | `run-N.csv`. |
| `/{run_id}/download/questions` | `ys_ai_tester.download_questions` | `run-N-questions.txt`. |
| `/compare/{run_a}/{run_b}` | `ys_ai_tester.compare` | The side-by-side comparison. |
| `/compare/{run_a}/{run_b}/export` | `ys_ai_tester.compare_export` | The "Export for AI analysis" modal. |
| `/compare/{run_a}/{run_b}/download/json` | `ys_ai_tester.compare_json` | `compare-A-B.json`. |
| `/compare/{run_a}/{run_b}/download/csv` | `ys_ai_tester.compare_csv` | `compare-A-B.csv`. |

### Services

| Service | Class | Does |
|---|---|---|
| `ys_ai_tester.run_exporter` | `RunExporter` | Builds the body of every download. |
| `ys_ai_tester.run_comparator` | `RunComparator` | Pairs two runs' results by question text and partitions their citations. |
| `ys_ai_tester.run_progress` | `RunProgress` | Reports which of a run's questions have no stored row. |
| `ys_ai_tester.stale_run_reconciler` | `StaleRunReconciler` | Moves abandoned `processing` runs to a final status. |
| `ys_ai_tester.answer_backend_registry` | `AnswerBackendRegistry` | Collects every assistant tagged `ys_ai_tester.answer_backend`. |
| `ys_ai_tester.backend.beacon` | `Backend\BeaconAnswerBackend` | The Beacon assistant. Priority 100, so it sorts first and becomes Run A. |

`AiTesterBatch` holds the static batch callbacks: `processQuestion()`, `pace()`, the retry loop, and the status derivations. `AiTesterRetry` and `AiTesterFailure` hold the timing policy and the failure classification. Both are static, so they unit test without a request. `TesterRunStorageTrait` gives the controller and the exporter one shared `loadRunOr404()` and `decodeCitations()`, so a change to either lands in one place.

### Forms and controller

`Form/AiTesterForm` (the run form and history), `Form/AiTesterRerunForm`, `Form/AiTesterResumeForm`, and `Controller/AiTesterController` (the run and compare views, plus the download envelopes).

### Templates

| Template | Renders |
|---|---|
| `ys-ai-tester-run-summary.html.twig` | The run view's header line. |
| `ys-ai-tester-compare-run-meta.html.twig` | One run's block in the comparison header, with its host. |
| `ys-ai-tester-citation.html.twig` | One source in a Sources list: link, cited flag, only-here badge, URL. |
| `ys-ai-tester-question-tabs.html.twig` | The vertical question tabs. Both views use it. |
| `ys-ai-tester-ai-export.html.twig` | The "Export for AI analysis" modal and its research prompt. |

Libraries in `ys_ai_tester.libraries.yml`: `compare` (CSS), `compare_export` (the copy button), and `question_tabs` (tab behavior and CSS).

### Tables

`ys_ai_tester_run` holds one row per run: who, when, the source filename and content, `source_run_id` (set on a rerun), `status`, `changed` (the heartbeat the reconciler reads), `question_count`, and `backend`. `ys_ai_tester_result` holds one row per question: `run_id`, `delta`, `question`, `answer`, `citations` (JSON), and `error`. The schema and its update hooks are in `ys_ai_tester.install`.

### Config

The only setting is `ys_beacon.settings:ai_tester_question_delay_ms`. It lives in Beacon's config object rather than its own, so the `ys_beacon*` config_ignore pattern keeps an operator's value across `drush deploy`.

### Tests

Unit tests in `tests/src/Unit/` cover the exporter, the comparator, progress, the reconciler, retry and failure logic, and the form guards. `tests/src/Kernel/AiTesterTemplateEscapingTest` compiles the run-summary, compare-run-meta and citation templates from their files, so it runs without installing Beacon. Everything is in the `ys_beacon` PHPUnit group.

## Related

- [`ys_beacon` README](../../README.md): Beacon's architecture, per-site configuration, credentials, the cutover from `ai_engine`, and local development. Read it first. The tester only works where Beacon does.
- [`ys_ai_tester_legacy` README](../ys_ai_tester_legacy/README.md): the temporary second backend that lets a run target the legacy `ai_engine` assistant, and how to remove it.
