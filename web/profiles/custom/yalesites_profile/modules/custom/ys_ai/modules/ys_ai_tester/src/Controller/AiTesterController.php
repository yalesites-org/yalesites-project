<?php

declare(strict_types=1);

namespace Drupal\ys_ai_tester\Controller;

use Drupal\Component\Utility\Html;
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
    $run = $this->database->query(
      'SELECT id, created, assistant_id, yaml_filename, status
       FROM {ys_ai_tester_run} WHERE id = :id',
      [':id' => $run_id]
    )->fetchObject();

    if (!$run) {
      throw new NotFoundHttpException();
    }

    $results = $this->database->query(
      'SELECT * FROM {ys_ai_tester_result} WHERE run_id = :run_id ORDER BY delta ASC',
      [':run_id' => $run_id]
    )->fetchAll();

    $rows = [];
    foreach ($results as $result) {
      $citations = $this->decodeCitations($result->citations);
      $rows[] = [
        ['data' => $result->question, 'class' => ['views-field', 'views-field-question']],
        ['data' => $result->answer, 'class' => ['views-field', 'views-field-answer']],
        [
          'data' => ['#markup' => implode('<br>', array_map([Html::class, 'escape'], $citations))],
          'class' => ['views-field', 'views-field-citations', 'priority-low'],
        ],
      ];
    }

    $link_attrs = ['class' => ['button', 'button--link', 'button--link-purpose']];

    return [
      '#type' => 'container',
      'meta' => [
        '#markup' => $this->t(
          '<p><strong>Run #@id</strong> — @date — Assistant: @assistant — File: @file — Status: @status</p>',
          [
            '@id' => $run->id,
            '@date' => $this->dateFormatter->format($run->created, 'medium'),
            '@assistant' => $run->assistant_id,
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
          '#url' => Url::fromRoute('ys_ai.tester_download_json', ['run_id' => $run_id]),
          '#attributes' => $link_attrs,
        ],
        'separator' => ['#markup' => ' '],
        'yaml' => [
          '#type' => 'link',
          '#title' => $this->t('Download YAML'),
          '#url' => Url::fromRoute('ys_ai.tester_download_yaml', ['run_id' => $run_id]),
          '#attributes' => $link_attrs,
        ],
      ],
      'results' => [
        '#type' => 'table',
        '#responsive' => TRUE,
        '#header' => [
          ['data' => $this->t('Question'), 'class' => ['views-field', 'views-field-question']],
          ['data' => $this->t('Answer'), 'class' => ['views-field', 'views-field-answer']],
          ['data' => $this->t('Citations'), 'class' => ['views-field', 'views-field-citations', 'priority-low']],
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
        '#url' => Url::fromRoute('ys_ai.tester'),
        '#attributes' => $link_attrs,
      ],
    ];
  }

  /**
   * Returns run results as a downloadable JSON file.
   */
  public function downloadJson(int $run_id): JsonResponse {
    $run = $this->database->query(
      'SELECT id FROM {ys_ai_tester_run} WHERE id = :id',
      [':id' => $run_id]
    )->fetchObject();

    if (!$run) {
      throw new NotFoundHttpException();
    }

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
    $run = $this->database->query(
      'SELECT yaml_content, yaml_filename FROM {ys_ai_tester_run} WHERE id = :id',
      [':id' => $run_id]
    )->fetchObject();

    if (!$run) {
      throw new NotFoundHttpException();
    }

    $response = new Response($run->yaml_content);
    $safe_filename = preg_replace('/[^\w.\-]/', '_', $run->yaml_filename);
    $response->headers->set('Content-Type', 'application/x-yaml');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $safe_filename . '"');
    return $response;
  }

  /**
   * Decodes a JSON-encoded citations string to an array.
   */
  private function decodeCitations(?string $citations): array {
    return json_decode($citations ?? '', TRUE) ?? [];
  }

}
