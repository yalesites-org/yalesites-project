<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
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
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Renders the detail page for a single tester run.
   */
  public function run(int $run_id): array {
    $run = $this->loadRunOr404($run_id, 'id, created, yaml_filename, status');

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
            '@file' => $run->yaml_filename,
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
        'separator' => ['#markup' => ' '],
        'yaml' => [
          '#type' => 'link',
          '#title' => $this->t('Download YAML'),
          '#url' => Url::fromRoute('ys_ai_tester.download_yaml', ['run_id' => $run_id]),
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
      $link = ($url !== NULL && $url !== '')
        ? [
          '#type' => 'link',
          '#title' => $title,
          '#url' => Url::fromUri($url),
        ]
        : ['#markup' => htmlspecialchars($title, ENT_QUOTES)];

      $items[] = [
        'link' => $link,
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
   * Returns run results as a downloadable JSON file.
   */
  public function downloadJson(int $run_id): JsonResponse {
    $this->loadRunOr404($run_id, 'id');

    $results = $this->database->query(
      'SELECT question, answer, citations FROM {ys_ai_tester_result}
       WHERE run_id = :run_id ORDER BY delta ASC',
      [':run_id' => $run_id]
    )->fetchAll();

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
   * Returns the original uploaded YAML file as a download.
   */
  public function downloadYaml(int $run_id): Response {
    $run = $this->loadRunOr404($run_id, 'yaml_content, yaml_filename');

    $response = new Response($run->yaml_content);
    $safe_filename = preg_replace('/[^\w.\-]/', '_', $run->yaml_filename);
    $response->headers->set('Content-Type', 'application/x-yaml');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $safe_filename . '"');
    return $response;
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
