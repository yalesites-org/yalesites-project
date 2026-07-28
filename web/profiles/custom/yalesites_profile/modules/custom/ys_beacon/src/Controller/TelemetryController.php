<?php

namespace Drupal\ys_beacon\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ys_beacon\Service\GuardrailTelemetry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports aggregate guardrail telemetry to Beacon administrators.
 *
 * The page is the admin-visible half of
 * yalesites-org/YaleSites-Internal#1469: counts of refusals, guardrail stops,
 * uncited answers and injection-pattern hits, with an explicit statement of
 * what is and is not recorded so the platform's "conversations are not stored"
 * claim stays verifiable from the interface itself.
 */
class TelemetryController extends ControllerBase {

  /**
   * The guardrail telemetry recorder.
   *
   * @var \Drupal\ys_beacon\Service\GuardrailTelemetry
   */
  protected GuardrailTelemetry $telemetry;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->telemetry = $container->get('ys_beacon.guardrail_telemetry');
    return $instance;
  }

  /**
   * Builds the telemetry report.
   *
   * @return array
   *   A render array.
   */
  public function report(): array {
    $report = $this->telemetry->getReport();
    $labels = [
      GuardrailTelemetry::EVENT_TURNS => $this->t('Chat turns'),
      GuardrailTelemetry::EVENT_REFUSAL => $this->t('Model refusals'),
      GuardrailTelemetry::EVENT_GUARDRAIL_STOP => $this->t('Guardrail stops'),
      GuardrailTelemetry::EVENT_ZERO_CITATIONS => $this->t('Answers with no citations'),
      GuardrailTelemetry::EVENT_INJECTION_PATTERN => $this->t('Injection-pattern hits'),
    ];

    $build = [];

    $build['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Aggregate counts of guardrail-relevant chat events from the last @days days. Events are bucketed by UTC day and kept for @retention days.', [
        '@days' => $report['window_days'],
        '@retention' => GuardrailTelemetry::RETENTION_DAYS,
      ]),
    ];

    // Driven by the telemetry service's own event list, so a new event type
    // shows up as a total even before it is given a label here.
    $totals = [];
    foreach (GuardrailTelemetry::EVENTS as $key) {
      $totals[] = [$labels[$key] ?? $key, (int) ($report['totals'][$key] ?? 0)];
    }
    $build['totals'] = [
      '#type' => 'table',
      '#caption' => $this->t('Event totals'),
      '#header' => [$this->t('Event'), $this->t('Count')],
      '#rows' => $totals,
    ];

    $breakdowns = [];
    foreach ($report['breakdowns'] as $key => $count) {
      $breakdowns[] = [$key, $count];
    }
    $build['breakdowns'] = [
      '#type' => 'table',
      '#caption' => $this->t('Breakdowns'),
      '#header' => [$this->t('Event key'), $this->t('Count')],
      '#rows' => $breakdowns,
      '#empty' => $this->t('No breakdowns recorded in this window.'),
    ];

    $days = [];
    foreach ($report['days'] as $date => $counts) {
      $row = [$date];
      foreach (GuardrailTelemetry::EVENTS as $key) {
        $row[] = (int) ($counts[$key] ?? 0);
      }
      $days[] = $row;
    }
    $day_header = [$this->t('Date (UTC)')];
    foreach (GuardrailTelemetry::EVENTS as $key) {
      $day_header[] = $labels[$key] ?? $key;
    }
    $build['days'] = [
      '#type' => 'table',
      '#caption' => $this->t('By day'),
      '#header' => $day_header,
      '#rows' => $days,
      '#empty' => $this->t('No events recorded yet.'),
    ];

    $build['privacy'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('What is and is not recorded'),
      '#items' => [
        $this->t('Recorded: one count per event type per day, plus counts broken down by guardrail mode, guardrail label, guardrail set and injection-pattern name. "Chat turns" is the denominator, so the other counts can be read as rates rather than raw volumes.'),
        $this->t('Not recorded: questions, answers, user names, IP addresses, session identifiers, or any sample of conversation text - hashed, redacted or otherwise. The counter table has no column that could hold them.'),
        $this->t('Model refusals are detected with a heuristic over the opening of the answer, so the count indicates a trend rather than an exact tally.'),
        $this->t('Guardrail stops read zero until a guardrail set is configured for Beacon. Stops applied while the answer is streaming are counted only if the guardrail plugin reports them itself: the AI module evaluates those inside the stream and does not report them back to the caller. Stops applied before or after generation are always counted.'),
        $this->t('Injection-pattern hits are counted before a turn is refused for a missing provider or an over-long conversation, but NOT for requests the rate limiter rejects — the question has not been read at that point. During a sustained campaign the true number of attempts is therefore higher than the count shown.'),
        $this->t('"By plugin" means by the guardrail plugin label, because the AI module exposes no plugin id to callers. A guardrail set is named only when exactly one set was active for the turn; otherwise it is recorded as ambiguous.'),
        $this->t('Answers with no citations are counted whenever retrieval returned nothing. The relevance threshold ships at 0.0, which disables score filtering, so today this counts only questions the search index matched nothing for at all.'),
      ],
    ];

    // Counters change on every chat turn, so the page must not be served from
    // the render cache.
    $build['#cache']['max-age'] = 0;

    return $build;
  }

}
