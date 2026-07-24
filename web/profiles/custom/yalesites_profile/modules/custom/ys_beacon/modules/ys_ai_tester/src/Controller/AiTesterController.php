<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester\Controller;

use Drupal\Component\Diff\WordLevelDiff;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
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
   * {@inheritdoc}
   */
  public function __construct(
    protected Connection $database,
    protected DateFormatterInterface $dateFormatter,
    protected RunComparator $runComparator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('ys_ai_tester.run_comparator'),
    );
  }

  /**
   * Renders the detail page for a single tester run.
   */
  public function run(int $run_id): array {
    $run = $this->loadRunOr404($run_id, 'id, created, source_filename, status');

    $results = $this->database->query(
      'SELECT * FROM {ys_ai_tester_result} WHERE run_id = :run_id ORDER BY delta ASC',
      [':run_id' => $run_id]
    )->fetchAll();

    $rows = [];
    foreach ($results as $result) {
      $rows[] = [
        ['data' => $result->question, 'class' => ['views-field', 'views-field-question']],
        ['data' => $result->answer, 'class' => ['views-field', 'views-field-answer']],
        [
          'data' => $this->buildCitationsCell($this->decodeCitations($result->citations)),
          'class' => ['views-field', 'views-field-citations', 'priority-low'],
        ],
      ];
    }

    $link_attrs = ['class' => ['button', 'button--link', 'button--link-purpose']];

    return [
      '#type' => 'container',
      'meta' => [
        '#markup' => $this->t(
          '<p><strong>Run #@id</strong> — @date — File: @file — Status: @status</p>',
          [
            '@id' => $run->id,
            '@date' => $this->dateFormatter->format($run->created, 'medium'),
            '@file' => $run->source_filename,
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
      'results' => [
        '#type' => 'table',
        '#responsive' => TRUE,
        '#header' => [
          ['data' => $this->t('Question'), 'class' => ['views-field', 'views-field-question']],
          ['data' => $this->t('Answer'), 'class' => ['views-field', 'views-field-answer']],
          ['data' => $this->t('Sources'), 'class' => ['views-field', 'views-field-citations', 'priority-low']],
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No results yet — batch may still be processing.'),
        '#attributes' => ['class' => ['table', 'cols-3']],
        '#prefix' => '<div class="table-wrapper">',
        '#suffix' => '</div>',
      ],
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
      'SELECT question, answer, citations FROM {ys_ai_tester_result}
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
   *   Result rows, each with 'question', 'answer', and 'sources' strings.
   *
   * @return string
   *   The CSV file body, including the leading BOM.
   */
  protected function buildResultsCsv(array $rows): string {
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, ['Question', 'Answer', 'Sources']);
    foreach ($rows as $row) {
      fputcsv($handle, array_map([$this, 'csvCell'], [
        $row['question'],
        $row['answer'],
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

    $rows = [];
    foreach ($data['pairs'] as $pair) {
      $rows[] = $this->comparisonRow($pair);
    }

    return [
      '#type' => 'container',
      '#attached' => ['library' => ['ys_ai_tester/compare']],
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
      'meta' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['ys-compare-meta']],
        'a' => $this->runMetaBlock($this->t('Run A'), $data['run_a']),
        'b' => $this->runMetaBlock($this->t('Run B'), $data['run_b']),
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
      ],
      'results' => [
        '#type' => 'table',
        '#responsive' => TRUE,
        '#header' => [
          $this->t('Status'),
          $this->t('Question'),
          $this->t('Run A'),
          $this->t('Run B'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('Neither run has any results.'),
        '#attributes' => ['class' => ['table', 'cols-4']],
        '#prefix' => '<div class="table-wrapper">',
        '#suffix' => '</div>',
      ],
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
      '<strong>@label — Run #@id</strong><br>@date<br>File: @file<br>Status: @status',
      [
        '@label' => $label,
        '@id' => $meta['id'],
        '@date' => $this->dateFormatter->format($meta['created'], 'medium'),
        '@file' => $meta['source_filename'],
        '@status' => $meta['status'],
      ]
    ));
  }

  /**
   * Builds one comparison table row for a question pair.
   */
  protected function comparisonRow(array $pair): array {
    $status = $pair['status'];
    $badge = $this->wrap(
      'ys-compare-badge ys-compare-badge--' . $status,
      $this->statusLabel($status),
      'span'
    );

    // A word-level diff only makes sense when both runs answered the question.
    // Answers are escaped before diffing because the diff accumulator emits its
    // word groups unescaped; the directional CSS class colors the changes.
    $diff_a = $diff_b = NULL;
    if ($pair['a'] !== NULL && $pair['b'] !== NULL) {
      $diff = new WordLevelDiff(
        explode("\n", Html::escape($pair['a']['answer'])),
        explode("\n", Html::escape($pair['b']['answer'])),
      );
      $diff_a = implode('<br>', $diff->orig());
      $diff_b = implode('<br>', $diff->closing());
    }

    return [
      ['data' => $badge],
      ['data' => ['#markup' => Html::escape($pair['question'])]],
      ['data' => $this->sideCell($pair, 'a', $diff_a)],
      ['data' => $this->sideCell($pair, 'b', $diff_b)],
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

    $direction = $key === 'a' ? 'removed' : 'added';
    // $diff_html is built from already-escaped answer text plus the diff's own
    // static <span>/<br> markup; the escaped answer is the only fallback.
    $answer_html = $diff_html ?? Html::escape($side['answer']);

    $cell = [
      'answer' => $this->wrap('ys-diff ys-diff--' . $direction, $answer_html),
    ];

    if ($side['empty']) {
      $cell['meta'] = $this->wrap(
        'ys-compare-side-meta ys-compare-side-meta--empty',
        $this->t('Empty answer')
      );
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
   * Returns the human-readable label for a pair status.
   */
  protected function statusLabel(string $status): string {
    return match ($status) {
      'identical' => (string) $this->t('Identical'),
      'differs' => (string) $this->t('Differs'),
      'only_a' => (string) $this->t('Only in Run A'),
      'only_b' => (string) $this->t('Only in Run B'),
      default => $status,
    };
  }

  /**
   * Returns the run comparison as a downloadable JSON file.
   */
  public function downloadComparisonJson(int $run_a, int $run_b): JsonResponse {
    $data = $this->runComparator->compare($run_a, $run_b);

    $response = new JsonResponse([
      'run_a' => $data['run_a'],
      'run_b' => $data['run_b'],
      'summary' => $data['summary'],
      'pairs' => $data['pairs'],
    ]);
    $response->headers->set(
      'Content-Disposition',
      'attachment; filename="compare-' . $run_a . '-' . $run_b . '.json"'
    );
    return $response;
  }

  /**
   * Returns the run comparison as a downloadable CSV file.
   */
  public function downloadComparisonCsv(int $run_a, int $run_b): Response {
    $data = $this->runComparator->compare($run_a, $run_b);

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, [
      'question', 'status', 'answer_a', 'answer_b',
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
