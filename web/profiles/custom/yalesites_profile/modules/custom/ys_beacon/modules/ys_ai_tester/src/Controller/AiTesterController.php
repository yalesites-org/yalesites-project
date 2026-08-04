<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester\Controller;

use Drupal\Component\Diff\WordLevelDiff;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\ys_ai_tester\AnswerBackendRegistry;
use Drupal\ys_ai_tester\RunComparator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for AI Tester run detail and file download routes.
 */
class AiTesterController extends ControllerBase {

  /**
   * Constructs the AI Tester controller.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\ys_ai_tester\RunComparator $runComparator
   *   The run comparator.
   * @param \Drupal\ys_ai_tester\AnswerBackendRegistry $backendRegistry
   *   The answer backend registry, used to label a run's assistant.
   */
  public function __construct(
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
    protected RunComparator $runComparator,
    protected AnswerBackendRegistry $backendRegistry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('ys_ai_tester.run_comparator'),
      $container->get('ys_ai_tester.answer_backend_registry'),
    );
  }

  /**
   * Renders the detail page for a single tester run.
   */
  public function run(int $run_id): array {
    $run = $this->loadRunOr404($run_id, 'id, created, source_filename, status, backend');

    $results = $this->database->query(
      'SELECT * FROM {ys_ai_tester_result} WHERE run_id = :run_id ORDER BY delta ASC',
      [':run_id' => $run_id]
    )->fetchAll();

    $link_attrs = ['class' => ['button', 'button--link', 'button--link-purpose']];

    return [
      '#type' => 'container',
      'meta' => [
        '#markup' => $this->t(
          '<p><strong>Run #@id</strong> — @date — File: @file — Assistant: @backend — Status: @status</p>',
          [
            '@id' => $run->id,
            '@date' => $this->dateFormatter->format($run->created, 'medium'),
            '@file' => $run->source_filename,
            '@backend' => $this->backendRegistry->labelFor((string) $run->backend),
            '@status' => $run->status,
          ]
        ),
      ],
      'downloads' => [
        '#type' => 'container',
        'json' => [
          '#type' => 'link',
          '#title' => $this->t('Download JSON'),
          '#url' => Url::fromRoute('ys_ai_tester.download_json', ['run_id' => $run_id]),
          '#attributes' => $link_attrs,
        ],
        'separator_csv' => ['#markup' => ' '],
        'csv' => [
          '#type' => 'link',
          '#title' => $this->t('Download CSV'),
          '#url' => Url::fromRoute('ys_ai_tester.download_csv', ['run_id' => $run_id]),
          '#attributes' => $link_attrs,
        ],
        'separator_questions' => ['#markup' => ' '],
        'questions' => [
          '#type' => 'link',
          '#title' => $this->t('Download questions (.txt)'),
          '#url' => Url::fromRoute('ys_ai_tester.download_questions', ['run_id' => $run_id]),
          '#attributes' => $link_attrs,
        ],
      ],
      'results' => $this->runQuestionTabs($results),
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back to tester'),
        '#url' => Url::fromRoute('ys_ai_tester.tester'),
        '#attributes' => $link_attrs,
      ],
    ];
  }

  /**
   * Builds the citations cell: every retrieved source, flagged if cited.
   *
   * @param array $citations
   *   The normalized citation list stored for a result.
   *
   * @return array
   *   An item-list render array, or a fallback markup when there are none.
   */
  protected function buildCitationsCell(array $citations): array {
    if (!$citations) {
      return ['#markup' => $this->t('No sources retrieved.')];
    }

    $items = [];
    foreach ($citations as $citation) {
      $title = (string) ($citation['title'] ?? $this->t('Untitled'));
      $url = $citation['url'] ?? NULL;
      $flag = !empty($citation['cited'])
        ? $this->t('cited')
        : $this->t('retrieved, not cited');

      // The title links to its source when a URL is present; the cited flag
      // lets a tester evaluate citation quality at a glance.
      $items[] = [
        'link' => $this->citationLink($title, $url),
        'flag' => ['#markup' => ' — <em>' . $flag . '</em>'],
        'url' => ($url !== NULL && $url !== '')
          ? ['#markup' => '<br><small>' . htmlspecialchars($url, ENT_QUOTES) . '</small>']
          : [],
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
    ];
  }

  /**
   * Builds a citation link that opens its source in a new window.
   *
   * Only http(s) URLs become links; any other scheme (or an empty/absent URL)
   * degrades to escaped plain text. Citation URLs come from server-side
   * entity/file URLs today, but allowlisting the scheme keeps a javascript:
   * URI from ever rendering as a live link if that changes. New-window links
   * carry rel="noopener noreferrer" and a visually-hidden "(opens in new
   * window)" cue so assistive-tech users are warned of the context switch
   * (WCAG 2.1 AA, technique G201).
   *
   * @param string $title
   *   The link text.
   * @param string|null $url
   *   The citation URL, or NULL when the source has none.
   *
   * @return array
   *   A #type link render element, or a #markup text fallback when the URL is
   *   empty or not an http(s) link.
   */
  protected function citationLink(string $title, ?string $url): array {
    $scheme = $url !== NULL ? strtolower((string) parse_url($url, PHP_URL_SCHEME)) : '';
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      return ['#markup' => htmlspecialchars($title, ENT_QUOTES)];
    }

    return [
      '#type' => 'link',
      '#title' => Markup::create(
        htmlspecialchars($title, ENT_QUOTES)
        . '<span class="visually-hidden"> ' . $this->t('(opens in new window)') . '</span>'
      ),
      '#url' => Url::fromUri($url),
      '#attributes' => [
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
    ];
  }

  /**
   * Returns run results as a downloadable JSON file.
   */
  public function downloadJson(int $run_id): JsonResponse {
    $this->loadRunOr404($run_id, 'id');

    $results = $this->loadResultRows($run_id);

    $output = [];
    foreach ($results as $result) {
      $output[] = [
        'question' => $result->question,
        'answer' => $result->answer,
        // Exported so a failed question is not read as an assistant that
        // answered nothing — the export is the artefact people quote.
        'error' => (string) ($result->error ?? ''),
        'citations' => $this->decodeCitations($result->citations),
      ];
    }

    $response = new JsonResponse($output);
    $response->headers->set('Content-Disposition', 'attachment; filename="run-' . $run_id . '.json"');
    return $response;
  }

  /**
   * Returns the run's question list as a downloadable plain-text file.
   *
   * One question per line, ready to edit and re-upload as a new run.
   */
  public function downloadQuestions(int $run_id): Response {
    $run = $this->loadRunOr404($run_id, 'source_content');

    $response = new Response($run->source_content);
    $response->headers->set('Content-Type', 'text/plain; charset=utf-8');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('Content-Disposition', 'attachment; filename="run-' . $run_id . '-questions.txt"');
    return $response;
  }

  /**
   * Returns the run's results as a downloadable, spreadsheet-friendly CSV.
   */
  public function downloadCsv(int $run_id): Response {
    $this->loadRunOr404($run_id, 'id');

    $results = $this->loadResultRows($run_id);

    $rows = [];
    foreach ($results as $result) {
      // Drop URL-less citations from the Sources column: they carry no URL to
      // list, matching how the comparison CSV omits them.
      $sources = array_filter(
        $this->decodeCitations($result->citations),
        static fn (array $citation): bool => !empty($citation['url']),
      );
      $rows[] = [
        'question' => (string) $result->question,
        'answer' => (string) $result->answer,
        'error' => (string) ($result->error ?? ''),
        'sources' => $this->joinSourceUrls($sources),
      ];
    }

    $response = new Response($this->buildResultsCsv($rows));
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('Content-Disposition', 'attachment; filename="run-' . $run_id . '.csv"');
    return $response;
  }

  /**
   * Loads a run's result rows in delta order.
   *
   * @param int $run_id
   *   The run id.
   *
   * @return object[]
   *   Result rows, each with question, answer, and citations properties.
   */
  protected function loadResultRows(int $run_id): array {
    return $this->database->query(
      'SELECT question, answer, citations, error FROM {ys_ai_tester_result}
       WHERE run_id = :run_id ORDER BY delta ASC',
      [':run_id' => $run_id]
    )->fetchAll();
  }

  /**
   * Builds the run-detail results CSV body.
   *
   * Prepends a UTF-8 BOM so Excel renders non-ASCII characters correctly, and
   * runs every cell through csvCell() to neutralize spreadsheet formula
   * injection. Multiline answers are quoted by fputcsv and stay in one cell.
   *
   * @param array $rows
   *   Result rows, each with 'question', 'answer', 'error', and 'sources'
   *   strings.
   *
   * @return string
   *   The CSV file body, including the leading BOM.
   */
  protected function buildResultsCsv(array $rows): string {
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, ['Question', 'Answer', 'Error', 'Sources']);
    foreach ($rows as $row) {
      fputcsv($handle, array_map([$this, 'csvCell'], [
        $row['question'],
        $row['answer'],
        $row['error'] ?? '',
        $row['sources'],
      ]));
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    return "\xEF\xBB\xBF" . $csv;
  }

  /**
   * Renders the side-by-side comparison of two tester runs.
   */
  public function compare(int $run_a, int $run_b): array {
    $data = $this->runComparator->compare($run_a, $run_b);
    $summary = $data['summary'];

    $link_attrs = ['class' => ['button', 'button--link', 'button--link-purpose']];

    return [
      '#type' => 'container',
      // The class is load-bearing: the stylesheet scopes the diff colors to it
      // and, below the mobile breakpoint, hides every child except the notice.
      '#attributes' => ['class' => ['ys-compare']],
      '#attached' => [
        'library' => [
          'ys_ai_tester/compare',
          // The question tabs attach their own library; see questionTabs().
          // Supplies the modal the export button opens, including its focus
          // trap and Escape handling.
          'core/drupal.dialog.ajax',
        ],
      ],
      // Only rendered below the mobile breakpoint, where the side-by-side
      // comparison has no workable layout.
      'mobile_notice' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['ys-compare-mobile-notice']],
        '#value' => $this->t('This run comparison is a wide side-by-side view and is not designed for small screens. Please open it on a laptop, a desktop, or a larger screen to compare these runs.'),
      ],
      'summary' => $this->wrap('ys-compare-summary', $this->t(
        '@total compared · @differ differ · @identical identical · @a only in Run A · @b only in Run B',
        [
          '@total' => $summary['total_compared'],
          '@differ' => $summary['differ'],
          '@identical' => $summary['identical'],
          '@a' => $summary['only_a'],
          '@b' => $summary['only_b'],
        ]
      ), 'p'),
      'caveat' => $this->crossAssistantCaveat($data),
      'meta' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ys-compare-meta']],
        'a' => $this->runMetaBlock($this->t('Run A'), $data['run_a']),
        'b' => $this->runMetaBlock($this->t('Run B'), $data['run_b']),
      ],
      'help' => $this->diffHelp(),
      'legend' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ys-compare-legend']],
        'only_a' => $this->legendItem('a', $this->t('Only in Run A')),
        'only_b' => $this->legendItem('b', $this->t('Only in Run B')),
      ],
      'downloads' => [
        '#type' => 'container',
        'json' => [
          '#type' => 'link',
          '#title' => $this->t('Download JSON'),
          '#url' => Url::fromRoute('ys_ai_tester.compare_json', ['run_a' => $run_a, 'run_b' => $run_b]),
          '#attributes' => $link_attrs,
        ],
        'separator' => ['#markup' => ' '],
        'csv' => [
          '#type' => 'link',
          '#title' => $this->t('Download CSV'),
          '#url' => Url::fromRoute('ys_ai_tester.compare_csv', ['run_a' => $run_a, 'run_b' => $run_b]),
          '#attributes' => $link_attrs,
        ],
        'export_separator' => ['#markup' => ' '],
        'export' => [
          '#type' => 'link',
          '#title' => $this->t('Export for AI analysis'),
          '#url' => Url::fromRoute('ys_ai_tester.compare_export', ['run_a' => $run_a, 'run_b' => $run_b]),
          '#attributes' => [
            'class' => ['button', 'button--primary', 'use-ajax'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => Json::encode(['width' => 760]),
          ],
        ],
      ],
      'results' => $this->questionTabs($data),
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back to tester'),
        '#url' => Url::fromRoute('ys_ai_tester.tester'),
        '#attributes' => $link_attrs,
      ],
    ];
  }

  /**
   * Wraps already-safe inner markup in a tagged element.
   *
   * Collapses the comparison view's repeated "<tag class>…</tag>" #markup
   * fragments. Callers must pass inner content that is already safe (a t()
   * string, an Html::escape() result, or the diff's own escaped markup); the
   * class is built from internal, non-user values.
   *
   * @param string $class
   *   The element class attribute.
   * @param string|\Drupal\Component\Render\MarkupInterface $inner
   *   The already-safe inner markup.
   * @param string $tag
   *   The HTML tag name.
   *
   * @return array
   *   A #markup render element.
   */
  protected function wrap(string $class, string|MarkupInterface $inner, string $tag = 'div'): array {
    return [
      '#markup' => Markup::create('<' . $tag . ' class="' . $class . '">' . $inner . '</' . $tag . '>'),
    ];
  }

  /**
   * Builds the meta block for one run in the comparison header.
   *
   * @param string|\Drupal\Component\Render\MarkupInterface $label
   *   The run label (e.g. a t() "Run A").
   * @param array $meta
   *   The run meta: id, created, source_filename, status.
   *
   * @return array
   *   A #markup render element.
   */
  protected function runMetaBlock(string|MarkupInterface $label, array $meta): array {
    return $this->wrap('ys-compare-meta__run', $this->t(
      '<strong>@label — Run #@id</strong><br>@date<br>File: @file<br>Assistant: @backend<br>Status: @status',
      [
        '@label' => $label,
        '@id' => $meta['id'],
        '@date' => $this->dateFormatter->format($meta['created'], 'medium'),
        '@file' => $meta['source_filename'],
        '@backend' => $this->backendRegistry->labelFor($meta['backend']),
        '@status' => $meta['status'],
      ]
    ));
  }

  /**
   * Builds the paragraph explaining what the answer highlighting means.
   *
   * The highlights are not individually focusable and carry no per-word
   * tooltip. A paragraph-length answer produces dozens of changed-word spans,
   * so making each one a tab stop would cost a keyboard user far more than the
   * repeated tooltip would tell them; the explanation is given once here and
   * reinforced by the legend beside it.
   *
   * @return array
   *   An html_tag render element.
   */
  protected function diffHelp(): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#attributes' => ['class' => ['ys-compare-help']],
      '#value' => $this->t('Highlighted words are the parts of an answer that do not appear in the other run: a highlight in Run A is wording missing from Run B, and a highlight in Run B is wording missing from Run A. Wording the two runs share is left unhighlighted.'),
    ];
  }

  /**
   * Builds one legend entry for a run's highlight color.
   *
   * @param string $side
   *   The run the color belongs to, 'a' or 'b'. Must match the key sideCell()
   *   puts on the answer wrapper, or the swatch advertises the wrong run.
   * @param string|\Drupal\Component\Render\MarkupInterface $label
   *   The entry label.
   *
   * @return array
   *   A container render element holding a swatch and its label.
   */
  protected function legendItem(string $side, string|MarkupInterface $label): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ys-compare-legend__item']],
      'swatch' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => '',
        '#attributes' => [
          'class' => [
            'ys-compare-legend__swatch',
            'ys-compare-legend__swatch--' . $side,
          ],
          // The swatch only restates the adjacent label's color, so announcing
          // it would add nothing but noise.
          'aria-hidden' => 'true',
        ],
      ],
      'label' => ['#plain_text' => $label],
    ];
  }

  /**
   * Builds the modal offering the comparison as an LLM analysis package.
   *
   * Deliberately names no particular assistant. Clarity was the original
   * target, but it cannot ingest a comparison of any real size, so the modal
   * ships the prompt and the file for whichever assistant the reviewer uses and
   * offers Clarity as one destination rather than the only one. The exported
   * data is public site content, so a general-purpose assistant is a sanctioned
   * choice.
   *
   * The download reuses the existing comparison JSON route rather than adding a
   * second export path, so the file a reviewer attaches is the same artefact
   * the compare view already offers.
   *
   * @param int $run_a
   *   The first run id.
   * @param int $run_b
   *   The second run id.
   *
   * @return array
   *   The modal's render array.
   */
  public function compareExport(int $run_a, int $run_b): array {
    $data = $this->runComparator->compare($run_a, $run_b);
    $prompt_id = 'ys-compare-ai-prompt';

    return [
      '#theme' => 'ys_ai_tester_ai_export',
      '#attached' => ['library' => ['ys_ai_tester/compare_export']],
      '#question_count' => count($data['pairs']),
      '#run_a' => $data['run_a']['id'],
      '#run_b' => $data['run_b']['id'],
      '#label_a' => $this->backendRegistry->labelFor($data['run_a']['backend']),
      '#label_b' => $this->backendRegistry->labelFor($data['run_b']['backend']),
      '#prompt_id' => $prompt_id,
      '#download' => [
        '#type' => 'link',
        '#title' => $this->t('Download comparison JSON'),
        '#url' => Url::fromRoute('ys_ai_tester.compare_json', [
          'run_a' => $run_a,
          'run_b' => $run_b,
        ]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      '#copy' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Copy prompt'),
        '#attributes' => [
          // Explicitly not a submit button: inside the dialog it must never
          // navigate or post.
          'type' => 'button',
          'class' => ['button'],
          'data-ys-copy-target' => $prompt_id,
        ],
      ],
    ];
  }

  /**
   * Builds the caveat shown when the two runs used different assistants.
   *
   * Two assistants differ in content set, index, system prompt, retrieval and
   * model, so a high "differs" count is the expected outcome of the comparison
   * rather than evidence that one of them regressed. Saying so on the page
   * stops the number being quoted as a defect count.
   *
   * @param array $data
   *   The comparison structure from the run comparator.
   *
   * @return array
   *   A #markup render element, or an empty array when both runs used the same
   *   assistant and no caveat is warranted.
   */
  protected function crossAssistantCaveat(array $data): array {
    if ($data['run_a']['backend'] === $data['run_b']['backend']) {
      return [];
    }

    return $this->wrap('ys-compare-caveat', $this->t(
      'These runs were answered by different assistants (@a vs @b). They use a different content set, index, system prompt, retrieval strategy and model, so answers are expected to differ — a high "differs" count here is not a regression in either assistant.',
      [
        '@a' => $this->backendRegistry->labelFor($data['run_a']['backend']),
        '@b' => $this->backendRegistry->labelFor($data['run_b']['backend']),
      ]
    ), 'p');
  }

  /**
   * Builds the question tabs and their per-run answer panels.
   *
   * One tab per question down the left, that question's answers side by side on
   * the right. This replaced a four-column table: the table's Status column had
   * nowhere to go in the new layout, so the status travels on the tab as a
   * visible badge rather than being dropped or left to colour alone.
   *
   * Two sides, which is all this view can produce: compare() works from a
   * two-sided pairing (run_a/run_b). The panel grid takes its column count from
   * however many sides it gets, so nothing here states "two" a second time.
   *
   * @param array $data
   *   The comparison as returned by RunComparator::compare().
   *
   * @return array
   *   A ys_ai_tester_question_tabs render element.
   */
  protected function questionTabs(array $data): array {
    $tabs = [];
    $panels = [];
    $total = count($data['pairs']);

    foreach (array_values($data['pairs']) as $index => $pair) {
      $tab = $this->questionTab($index + 1, $pair['question'], $pair['status']);
      $tabs[] = $tab;

      [$diff_a, $diff_b] = $this->pairDiffs($pair);

      $panels[] = [
        'id' => $tab['panel_id'],
        'tab_id' => $tab['id'],
        'heading' => $this->questionHeading($index + 1, $total, $pair['question']),
        'sides' => [
          $this->sidePanel($this->t('Run A'), $data['run_a'], $this->sideCell($pair, 'a', $diff_a)),
          $this->sidePanel($this->t('Run B'), $data['run_b'], $this->sideCell($pair, 'b', $diff_b)),
        ],
      ];
    }

    return $this->questionTabsElement($tabs, $panels, $this->t('Neither run has any results.'));
  }

  /**
   * Builds the question tabs and single answer panel for one run.
   *
   * The same widget the comparison uses, handed one side instead of two, so the
   * two views of a run's answers share a tab rail, a panel and a stylesheet
   * rather than looking like different features. It replaced a
   * Question/Answer/Sources table whose three columns could not give a
   * paragraph-length answer usable width.
   *
   * @param array $results
   *   Result rows for the run, in delta order, each with question, answer,
   *   citations and error properties.
   *
   * @return array
   *   A ys_ai_tester_question_tabs render element.
   */
  protected function runQuestionTabs(array $results): array {
    $tabs = [];
    $panels = [];
    $total = count($results);

    foreach (array_values($results) as $index => $result) {
      $question = (string) $result->question;
      $answer = (string) ($result->answer ?? '');
      $error = (string) ($result->error ?? '');
      // Trimmed to match RunComparator::side(): a whitespace-only answer counts
      // as empty in the comparison, so it has to count as empty here too or one
      // result reads differently depending on which page you opened it on.
      $empty = trim($answer) === '';

      $tab = $this->questionTab($index + 1, $question, $this->resultStatus($empty, $error));
      $tabs[] = $tab;

      $panels[] = [
        'id' => $tab['panel_id'],
        'tab_id' => $tab['id'],
        'heading' => $this->questionHeading($index + 1, $total, $question),
        // One side, so the panel grid gives the answer the full width. The
        // comparison labels each of its columns because a reader has to tell
        // them apart; a single run has nothing to disambiguate and the page
        // header already names the run, its file and its assistant, so the
        // heading and meta line are left off rather than repeated per panel.
        'sides' => [
          [
            'heading' => NULL,
            'meta' => NULL,
            'content' => $this->runSide($result, $answer, $empty, $error),
          ],
        ],
      ];
    }

    return $this->questionTabsElement(
      $tabs,
      $panels,
      $this->t('No results yet — batch may still be processing.')
    );
  }

  /**
   * Builds one question tab, and the ids pairing it to its panel.
   *
   * Shared by both views so the tab shape and the aria-controls/aria-labelledby
   * id pair are decided once. The template wires the ARIA from these values, so
   * a caller inventing its own would break the tab list for keyboard and screen
   * reader users without changing how it looks.
   *
   * @param int $number
   *   The question's position, counted from one.
   * @param string $question
   *   The full question text.
   * @param string $status
   *   The status machine name, used both as a badge modifier class and as the
   *   key into statusLabel().
   *
   * @return array
   *   A tab entry for the tabs template.
   */
  protected function questionTab(int $number, string $question, string $status): array {
    return [
      'id' => 'ys-qtab-' . $number,
      'panel_id' => 'ys-qpanel-' . $number,
      'number' => $this->t('Question @number', ['@number' => $number]),
      // Word-safe so the preview does not break mid-word, and ellipsised
      // so it reads as truncated rather than as a shorter question.
      'preview' => Unicode::truncate($question, 60, TRUE, TRUE),
      'status' => $status,
      'status_label' => $this->statusLabel($status),
    ];
  }

  /**
   * Builds a panel's headline: which question this is, then the question.
   *
   * The position is carried in the heading rather than left to the tab rail:
   * the rail scrolls once a run has more questions than fit it, so "Question 4
   * of 19" is the only place saying how far through the set an open panel is.
   * One t() string rather than concatenation in the template, so a translator
   * can reorder the whole sentence.
   *
   * @param int $number
   *   The question's position, counted from one.
   * @param int $total
   *   How many questions the run or comparison has.
   * @param string $question
   *   The full, untruncated question text.
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   The panel heading text.
   */
  protected function questionHeading(int $number, int $total, string $question): string|MarkupInterface {
    return $this->t('Question @number of @total: @question', [
      '@number' => $number,
      '@total' => $total,
      '@question' => $question,
    ]);
  }

  /**
   * Wraps built tabs and panels in the tabs render element.
   *
   * Keeps the library attachment and the tab list's accessible name in one
   * place for both callers — those are exactly the details that drift when a
   * second view builds the same widget.
   *
   * @param array $tabs
   *   The tab entries.
   * @param array $panels
   *   The panel entries, index-aligned with $tabs.
   * @param string|\Drupal\Component\Render\MarkupInterface $empty
   *   The message shown when there are no questions to show.
   *
   * @return array
   *   A ys_ai_tester_question_tabs render element.
   */
  protected function questionTabsElement(array $tabs, array $panels, string|MarkupInterface $empty): array {
    return [
      '#theme' => 'ys_ai_tester_question_tabs',
      // The tabs are inert without their behavior, so the element carries its
      // own library rather than relying on whatever route embedded it.
      '#attached' => ['library' => ['ys_ai_tester/question_tabs']],
      '#tabs' => $tabs,
      '#panels' => $panels,
      '#tablist_label' => $this->t('Questions'),
      '#empty' => $empty,
    ];
  }

  /**
   * Builds the answer, its meta line and its sources for one run's question.
   *
   * @param object $result
   *   The result row, read for its stored citations.
   * @param string $answer
   *   The recorded answer, empty when the backend returned nothing.
   * @param bool $empty
   *   Whether the answer is blank once trimmed.
   * @param string $error
   *   The recorded error, empty when the question succeeded.
   *
   * @return array
   *   The panel side's content: answer, an optional meta line, and sources.
   */
  protected function runSide(object $result, string $answer, bool $empty, string $error): array {
    $side = [
      // Escaped here rather than downstream: this builds a markup string, so
      // unlike the table cell it replaces nothing else will escape a backend's
      // answer on the way to the page.
      'answer' => $this->wrap('ys-qtabs__answer', Html::escape($answer)),
    ];

    if ($error !== '' || $empty) {
      $side['meta'] = $this->errorOrEmptyMeta($error);
    }

    // Every retrieved source, flagged for whether it was cited. This is the
    // run view's own information — the comparison lists only the sources
    // unique to one side — so it survives the move out of the table.
    $side['sources'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ys-qtabs__sources']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#attributes' => ['class' => ['ys-qtabs__sources-heading']],
        '#value' => $this->t('Sources'),
      ],
      'list' => $this->buildCitationsCell($this->decodeCitations($result->citations)),
    ];

    return $side;
  }

  /**
   * Classifies one run result for its tab badge.
   *
   * The comparison's badge carries a pair status. A single run has no pair, so
   * the badge reports the one thing worth scanning a long question list for:
   * which questions did not come back with an answer.
   *
   * @param bool $empty
   *   Whether the answer is blank once trimmed.
   * @param string $error
   *   The recorded error.
   *
   * @return string
   *   One of 'error', 'empty' or 'answered'.
   */
  protected function resultStatus(bool $empty, string $error): string {
    if ($error !== '') {
      return 'error';
    }

    return $empty ? 'empty' : 'answered';
  }

  /**
   * Builds the meta line for a side that failed or came back blank.
   *
   * Shared by both views so the two pages cannot describe the same failed
   * question differently. An error takes precedence over emptiness: a failure
   * and a genuinely blank answer look identical in the answer column, and the
   * recorded error is the more useful of the two things to say.
   *
   * @param string $error
   *   The recorded error, empty when the question merely returned nothing.
   *
   * @return array
   *   A #markup render element.
   */
  protected function errorOrEmptyMeta(string $error): array {
    $class = 'ys-compare-side-meta ys-compare-side-meta--empty';

    if ($error !== '') {
      return $this->wrap($class, $this->t('Error: @msg', ['@msg' => $error]));
    }

    return $this->wrap($class, $this->t('Empty answer'));
  }

  /**
   * Builds the word-level diff for both sides of a question pair.
   *
   * A word-level diff only makes sense when both runs answered the question.
   * Answers are escaped before diffing because the diff accumulator emits its
   * word groups unescaped; the per-run CSS class colors the changes.
   *
   * @param array $pair
   *   The question pair.
   *
   * @return array
   *   Run A's and Run B's diffed answer HTML, each NULL when only one run
   *   answered and there is nothing to diff against.
   */
  protected function pairDiffs(array $pair): array {
    if ($pair['a'] === NULL || $pair['b'] === NULL) {
      return [NULL, NULL];
    }

    $diff = new WordLevelDiff(
      explode("\n", Html::escape($pair['a']['answer'])),
      explode("\n", Html::escape($pair['b']['answer'])),
    );

    return [
      implode('<br>', $diff->orig()),
      implode('<br>', $diff->closing()),
    ];
  }

  /**
   * Builds one run's column inside a question panel.
   *
   * The heading names the run's source file, and the meta line keeps the run id
   * and the assistant that answered it: a cross-assistant comparison is the
   * point of the compare view, so which assistant produced a column cannot be
   * left to the header blocks alone.
   *
   * @param string|\Drupal\Component\Render\MarkupInterface $label
   *   The run label (a t() "Run A").
   * @param array $meta
   *   The run meta: id, created, source_filename, status, backend.
   * @param array $content
   *   The already-built answer cell for this run.
   *
   * @return array
   *   A heading/meta/content structure for the tabs template.
   */
  protected function sidePanel(string|MarkupInterface $label, array $meta, array $content): array {
    return [
      'heading' => $this->t('@label: @file', [
        '@label' => $label,
        '@file' => $meta['source_filename'],
      ]),
      'meta' => $this->t('Run #@id · @backend', [
        '@id' => $meta['id'],
        '@backend' => $this->backendRegistry->labelFor($meta['backend']),
      ]),
      'content' => $content,
    ];
  }

  /**
   * Builds one run's answer cell: diffed answer, signals, and unique sources.
   */
  protected function sideCell(array $pair, string $key, ?string $diff_html): array {
    $side = $pair[$key];
    if ($side === NULL) {
      return $this->wrap(
        'ys-compare-not-asked',
        $this->t('— not asked in this run —'),
        'span'
      );
    }

    // $diff_html is built from already-escaped answer text plus the diff's own
    // static <span>/<br> markup; the escaped answer is the only fallback.
    $answer_html = $diff_html ?? Html::escape($side['answer']);

    $cell = [
      // Keyed by run, so the stylesheet paints this column in its own run's
      // color; legendItem() must be given the same key.
      'answer' => $this->wrap('ys-diff ys-diff--' . $key, $answer_html),
    ];

    if ($side['error'] !== '' || $side['empty']) {
      $cell['meta'] = $this->errorOrEmptyMeta($side['error']);
    }
    else {
      $cell['meta'] = $this->wrap('ys-compare-side-meta', $this->t(
        '@chars chars · @cited of @retrieved sources cited',
        [
          '@chars' => $side['len'],
          '@cited' => $side['cited'],
          '@retrieved' => $side['retrieved'],
        ]
      ));
    }

    $unique = $pair['citation_overlap'][$key === 'a' ? 'only_a' : 'only_b'];
    if ($unique) {
      // Each unique source renders as a new-window link (falling back to text
      // when non-linkable) so a reviewer can open a source without losing the
      // comparison; a comma joins them into the "Sources only here:" line.
      $cell['unique'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ys-compare-unique']],
        'label' => ['#markup' => $this->t('Sources only here:') . ' '],
      ];
      foreach (array_values($unique) as $i => $source) {
        if ($i > 0) {
          $cell['unique']['sep_' . $i] = ['#markup' => ', '];
        }
        $title = trim($source['title']) !== '' ? $source['title'] : $source['url'];
        $cell['unique']['link_' . $i] = $this->citationLink($title, $source['url']);
      }
    }

    return $cell;
  }

  /**
   * Returns the human-readable label for a question's status badge.
   *
   * Both vocabularies live here: the first four are comparison pair states, the
   * last three are single-run result states. One map, because the badge that
   * renders them is one element in one template.
   */
  protected function statusLabel(string $status): string {
    return match ($status) {
      'identical' => (string) $this->t('Identical'),
      'differs' => (string) $this->t('Differs'),
      'only_a' => (string) $this->t('Only in Run A'),
      'only_b' => (string) $this->t('Only in Run B'),
      'answered' => (string) $this->t('Answered'),
      'empty' => (string) $this->t('No answer'),
      'error' => (string) $this->t('Error'),
      default => $status,
    };
  }

  /**
   * Returns the run comparison as a downloadable JSON file.
   *
   * This file doubles as the analysis package a reviewer hands to an LLM, so
   * its size is a token budget rather than a detail. See withoutSourceText()
   * for what is left out of it and why.
   */
  public function downloadComparisonJson(int $run_a, int $run_b): JsonResponse {
    $data = $this->runComparator->compare($run_a, $run_b);

    $response = new JsonResponse([
      'run_a' => $data['run_a'],
      'run_b' => $data['run_b'],
      'summary' => $data['summary'],
      'pairs' => array_map([$this, 'withoutSourceText'], $data['pairs']),
    ]);
    $response->headers->set(
      'Content-Disposition',
      'attachment; filename="compare-' . $run_a . '-' . $run_b . '.json"'
    );
    return $response;
  }

  /**
   * Strips every retrieved source's full text from one comparison pair.
   *
   * CitationFormatter::format() stores both 'content' — the entire retrieved
   * chunk — and 'excerpt', that same text's first 300 characters. Exporting
   * both made the file grow with however long the indexed pages happened to be,
   * multiplied by up to top_k (default 5) sources per question per side, while
   * adding no category of information the excerpt does not already carry. That
   * is what put the download beyond what an LLM will accept in one go.
   *
   * Dropping 'content' bounds every source at its excerpt, so the export scales
   * with the number of questions instead of the length of the site's pages.
   * Nothing reads the field: the compare view never rendered it and the CSV
   * never emitted it. The stored citation keeps it, so this narrows the export
   * only.
   *
   * @param array $pair
   *   One comparison pair from the run comparator.
   *
   * @return array
   *   The pair with 'content' removed from every citation on both sides.
   */
  protected function withoutSourceText(array $pair): array {
    foreach (['a', 'b'] as $key) {
      // A question asked in only one run has a null side, which must stay null:
      // the prompt reads an absent side as "not asked in this run".
      if (!isset($pair[$key]['citations'])) {
        continue;
      }
      foreach (array_keys($pair[$key]['citations']) as $index) {
        unset($pair[$key]['citations'][$index]['content']);
      }
    }
    return $pair;
  }

  /**
   * Returns the run comparison as a downloadable CSV file.
   */
  public function downloadComparisonCsv(int $run_a, int $run_b): Response {
    $data = $this->runComparator->compare($run_a, $run_b);

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, [
      'question', 'status', 'answer_a', 'answer_b',
      'error_a', 'error_b',
      'cited_a', 'cited_b', 'len_a', 'len_b',
      'shared_sources', 'only_a_sources', 'only_b_sources',
    ]);

    foreach ($data['pairs'] as $pair) {
      $a = $pair['a'];
      $b = $pair['b'];
      $overlap = $pair['citation_overlap'];
      fputcsv($handle, array_map([$this, 'csvCell'], [
        $pair['question'],
        $pair['status'],
        $a['answer'] ?? '',
        $b['answer'] ?? '',
        (string) ($a['error'] ?? ''),
        (string) ($b['error'] ?? ''),
        (string) ($a['cited'] ?? ''),
        (string) ($b['cited'] ?? ''),
        (string) ($a['len'] ?? ''),
        (string) ($b['len'] ?? ''),
        $this->joinSourceUrls($overlap['both']),
        $this->joinSourceUrls($overlap['only_a']),
        $this->joinSourceUrls($overlap['only_b']),
      ]));
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    $response = new Response($csv);
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set(
      'Content-Disposition',
      'attachment; filename="compare-' . $run_a . '-' . $run_b . '.csv"'
    );
    return $response;
  }

  /**
   * Joins citation URLs for a CSV cell.
   */
  protected function joinSourceUrls(array $sources): string {
    return implode(' | ', array_map(static fn (array $s): string => (string) $s['url'], $sources));
  }

  /**
   * Neutralizes spreadsheet formula injection in a CSV cell.
   *
   * Cells beginning with =, +, -, @ can be executed as formulas by a
   * spreadsheet, including when the trigger hides behind leading whitespace or
   * control characters. Prefixing a single quote forces the cell to be text.
   */
  protected function csvCell(string $value): string {
    if ($value === '') {
      return $value;
    }

    $trimmed = ltrim($value, " \t\r\n");
    if (in_array($value[0], ["\t", "\r", "\n"], TRUE)
      || ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], TRUE))) {
      return "'" . $value;
    }
    return $value;
  }

  /**
   * Loads a tester run row by id, or throws a 404.
   *
   * @param int $run_id
   *   The run id.
   * @param string $fields
   *   The columns to select (a code-controlled field list, not user input).
   *
   * @return object
   *   The run row.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When no run with the given id exists.
   */
  private function loadRunOr404(int $run_id, string $fields): object {
    $run = $this->database->query(
      'SELECT ' . $fields . ' FROM {ys_ai_tester_run} WHERE id = :id',
      [':id' => $run_id]
    )->fetchObject();

    if (!$run) {
      throw new NotFoundHttpException();
    }

    return $run;
  }

  /**
   * Decodes a JSON-encoded citations string to an array.
   */
  private function decodeCitations(?string $citations): array {
    return json_decode($citations ?? '', TRUE) ?? [];
  }

}
