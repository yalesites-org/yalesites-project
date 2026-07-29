<?php

namespace Drupal\ys_beacon\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\ys_beacon\Service\GuardrailTelemetry;
use Drupal\ys_beacon\Service\SuspectTurnLog;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports Beacon guardrail telemetry to platform administrators.
 *
 * The page is the admin-visible half of
 * yalesites-org/YaleSites-Internal#1469: counts of refusals, guardrail stops,
 * uncited answers and injection-pattern hits, a distribution of chat turns over
 * the retention window, and the recorded text of turns flagged as suspected
 * injection attempts.
 *
 * It also states plainly what is and is not recorded. That statement is the
 * point of the page as much as the numbers are: Beacon tells users their
 * conversations are not saved, and the one exception to that - a flagged turn -
 * has to be visible here rather than discoverable only by reading the schema.
 *
 * Reaching any of this needs "view ys beacon guardrail telemetry", which is
 * granted to platform_admin alone (user 1 bypasses permission checks). It is
 * deliberately not the broader "manage ys beacon settings" that site admins
 * also hold.
 */
class TelemetryController extends ControllerBase {

  /**
   * How many days of chat turns the distribution chart covers.
   *
   * The full retention window, so the chart shows everything that is kept and
   * a reader is not left wondering whether older data exists.
   */
  protected const CHART_DAYS = GuardrailTelemetry::RETENTION_DAYS;

  /**
   * How much of a flagged turn's text the table shows.
   *
   * Storage clamps at 2000 characters, which is right for reviewing an attempt
   * but wrong for a table cell: a question padded to the clamp is a single row
   * tall enough to push every other flagged turn off the screen, which defeats
   * the point of listing them. The table shows an excerpt and says so; the JSON
   * export carries the full stored text.
   */
  protected const EXCERPT_LENGTH = 300;

  /**
   * The guardrail telemetry recorder.
   *
   * @var \Drupal\ys_beacon\Service\GuardrailTelemetry
   */
  protected GuardrailTelemetry $telemetry;

  /**
   * The log of turns flagged as suspected injection attempts.
   *
   * @var \Drupal\ys_beacon\Service\SuspectTurnLog
   */
  protected SuspectTurnLog $suspectTurnLog;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->telemetry = $container->get('ys_beacon.guardrail_telemetry');
    $instance->suspectTurnLog = $container->get('ys_beacon.suspect_turn_log');
    $instance->dateFormatter = $container->get('date.formatter');
    $instance->time = $container->get('datetime.time');
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
    $labels = $this->eventLabels();

    $build = [];

    $build['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Aggregate counts of guardrail-relevant chat events from the last @days days. Events are bucketed by UTC day and kept for @retention days. The tables below cover @days days; the chart covers the full @retention-day window, so its total is larger.', [
        '@days' => $report['window_days'],
        '@retention' => GuardrailTelemetry::RETENTION_DAYS,
      ]),
    ];

    // Provisional feature - see "Removing the JSON export" in README.md.
    $build['export'] = [
      '#type' => 'link',
      '#title' => $this->t('Export raw JSON'),
      '#url' => Url::fromRoute('ys_beacon.telemetry_export'),
      // Content-Disposition on the response already forces the download, so no
      // download attribute is needed here.
      '#attributes' => ['class' => ['button', 'button--small']],
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

    $build['chart'] = $this->buildTurnChart();

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

    $build['flagged'] = $this->buildFlaggedTurns();

    $build['privacy'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('What is and is not recorded'),
      '#items' => $this->privacyStatements(),
    ];

    $build['#attached']['library'][] = 'ys_beacon/telemetry';

    // Counters change on every chat turn, so the page must not be served from
    // the render cache.
    $build['#cache']['max-age'] = 0;

    return $build;
  }

  /**
   * Serves the whole report as a raw JSON download.
   *
   * Provisional, at the reviewer's request - see "Removing the JSON export" in
   * README.md for the three things to delete if it is dropped.
   *
   * The export carries the flagged turns' question and answer text, so it is
   * gated on the same platform-admin-only permission as the report page and
   * marked no-store.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The JSON download.
   */
  public function export(): Response {
    $now = $this->time->getRequestTime();
    $report = $this->telemetry->getReport(GuardrailTelemetry::RETENTION_DAYS);
    $flagged = $this->suspectTurnLog->getRecent(SuspectTurnLog::MAX_EXPORT_ROWS);
    $stored = $this->suspectTurnLog->countStored();

    $rows = [];
    foreach ($flagged as $turn) {
      $rows[] = [
        'recorded' => gmdate('c', (int) $turn['created']),
        'pattern' => $turn['pattern'],
        'question' => $turn['question'],
        'answer' => $turn['answer'],
      ];
    }

    $data = [
      'generated' => gmdate('c', $now),
      'window_days' => $report['window_days'],
      'retention_days' => GuardrailTelemetry::RETENTION_DAYS,
      'totals' => $report['totals'],
      'breakdowns' => $report['breakdowns'],
      'days' => $report['days'],
      'turns_by_day' => $this->telemetry->getDailySeries(GuardrailTelemetry::EVENT_TURNS, self::CHART_DAYS),
      'flagged_turns' => [
        'stored' => $stored,
        'returned' => count($rows),
        // Stated rather than left implicit: a silently truncated export would
        // read as a complete one.
        'truncated' => $stored > count($rows),
        'max_rows_per_export' => SuspectTurnLog::MAX_EXPORT_ROWS,
        'text_clamped_to_characters' => SuspectTurnLog::MAX_TEXT_LENGTH,
        'rows' => $rows,
      ],
    ];

    // Slashes stay escaped: unescaping them buys nothing here and would emit a
    // literal </script> from stored text, which is a gratuitous weakening even
    // behind an attachment disposition and nosniff.
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    // Failing loudly matters more than serving something. An empty "{}" would
    // download as a valid file that reads as "nothing was recorded" - a silent
    // total truncation, and the one misreading the truncated flag exists to
    // prevent. Malformed UTF-8 in stored text is the likely trigger.
    if ($json === FALSE) {
      $this->getLogger('ys_beacon')->error('Beacon telemetry export could not be encoded: @error', [
        '@error' => json_last_error_msg(),
      ]);

      return new Response('{"error":"The export could not be generated."}', 500, [
        'Content-Type' => 'application/json',
        'Cache-Control' => 'no-store, private',
      ]);
    }

    return new Response($json, 200, [
      'Content-Type' => 'application/json',
      'Content-Disposition' => 'attachment; filename="beacon-guardrail-telemetry-' . gmdate('Y-m-d', $now) . '.json"',
      'Cache-Control' => 'no-store',
    ]);
  }

  /**
   * Builds the chat-turn distribution chart.
   *
   * @return array
   *   A render array.
   */
  protected function buildTurnChart(): array {
    $series = $this->telemetry->getDailySeries(GuardrailTelemetry::EVENT_TURNS, self::CHART_DAYS);
    $max = $series === [] ? 0 : max($series);

    $bars = [];
    foreach ($series as $date => $count) {
      $bars[] = [
        'date' => $date,
        'count' => $count,
        // Bars are scaled to the busiest day rather than to an absolute, so a
        // quiet period is still readable. A day with events is floored at 1%
        // so it never renders as an empty column.
        'percent' => $max > 0 ? max($count > 0 ? 1 : 0, (int) round($count / $max * 100)) : 0,
      ];
    }

    return [
      '#theme' => 'ys_beacon_telemetry_chart',
      '#bars' => $bars,
      '#max' => $max,
      '#days' => self::CHART_DAYS,
      '#total' => array_sum($series),
    ];
  }

  /**
   * Builds the list of turns flagged as suspected injection attempts.
   *
   * @return array
   *   A render array.
   */
  protected function buildFlaggedTurns(): array {
    $turns = $this->suspectTurnLog->getRecent();
    $stored = $this->suspectTurnLog->countStored();

    $rows = [];
    foreach ($turns as $turn) {
      $rows[] = [
        // Forced to UTC: every other date on this page is a UTC day - the
        // chart buckets, the per-day quota, the disclosure text - and the site
        // default timezone would put a 02:00 UTC row on the previous day here
        // while the chart kept it on this one.
        $this->dateFormatter->format((int) $turn['created'], 'custom', 'Y-m-d H:i', 'UTC') . ' UTC',
        $turn['pattern'],
        $this->excerpt((string) $turn['question']),
        $this->excerpt((string) $turn['answer']),
      ];
    }

    $build = [
      '#type' => 'container',
    ];

    $build['table'] = [
      '#type' => 'table',
      '#caption' => $this->t('Flagged turns (suspected injection attempts)'),
      '#header' => [
        $this->t('Recorded (UTC)'),
        $this->t('Pattern'),
        $this->t('Question'),
        $this->t('Answer'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No turns have been flagged as suspected injection attempts.'),
      '#attributes' => ['class' => ['ys-beacon-flagged-turns']],
    ];

    // Both limits are said out loud whenever there is anything to list: a short
    // list must never be mistaken for a quiet period, and a shortened cell must
    // never be mistaken for the whole question.
    if ($rows !== []) {
      $build['note'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $stored > count($rows)
          ? $this->t('Showing the @shown most recent of @stored flagged turns held, each shortened to @chars characters. The JSON export has the rest, and the full stored text.', [
            '@shown' => count($rows),
            '@stored' => $stored,
            '@chars' => self::EXCERPT_LENGTH,
          ])
          : $this->t('Question and answer are shortened to @chars characters here. The JSON export has the full stored text.', [
            '@chars' => self::EXCERPT_LENGTH,
          ]),
      ];
    }

    return $build;
  }

  /**
   * Shortens stored text for display in the table.
   *
   * @param string $text
   *   The stored text.
   *
   * @return string
   *   At most self::EXCERPT_LENGTH characters, with an ellipsis when shortened
   *   so a truncated cell is never mistaken for the whole thing.
   */
  protected function excerpt(string $text): string {
    if (mb_strlen($text) <= self::EXCERPT_LENGTH) {
      return $text;
    }

    return mb_substr($text, 0, self::EXCERPT_LENGTH) . '…';
  }

  /**
   * The human labels for the counted event types.
   *
   * @return array
   *   Translated labels keyed by event constant.
   */
  protected function eventLabels(): array {
    return [
      GuardrailTelemetry::EVENT_TURNS => $this->t('Chat turns'),
      GuardrailTelemetry::EVENT_REFUSAL => $this->t('Model refusals'),
      GuardrailTelemetry::EVENT_GUARDRAIL_STOP => $this->t('Guardrail stops'),
      GuardrailTelemetry::EVENT_ZERO_CITATIONS => $this->t('Answers with no citations'),
      GuardrailTelemetry::EVENT_INJECTION_PATTERN => $this->t('Injection-pattern hits'),
    ];
  }

  /**
   * What the page discloses about its own data.
   *
   * Kept in one method so the disclosure is reviewable in one place, and so a
   * change to what is stored has an obvious place to be declared.
   *
   * @return array
   *   Translated statements, most important first.
   */
  protected function privacyStatements(): array {
    return [
      $this->t('This is an operational safety report, not analytics. It exists to show whether guardrails and refusals are working and whether Beacon is being probed - it is not a measure of usage or engagement, and it should not be read as one or used for reporting on either.'),
      $this->t('Recorded for every turn: one count per event type per day, plus counts broken down by guardrail mode, guardrail label, guardrail set and injection-pattern name. "Chat turns" is the denominator, so the other counts can be read as rates rather than raw volumes. No question or answer text is kept for an ordinary turn.'),
      $this->t('Recorded for a flagged turn only: when a question matches a known prompt-injection pattern, that turn is kept in full - the time, the pattern name, the question and the answer - so a suspected attack can be reviewed. This is the single exception to Beacon not storing conversations, it is readable only with the permission gating this page, and each text is clamped to @chars characters.', [
        '@chars' => SuspectTurnLog::MAX_TEXT_LENGTH,
      ]),
      $this->t('Flagged turns are deleted after @days days. About @cap are kept per pattern per UTC day, keeping the most recent, so a sustained campaign is sampled here while the counts above stay complete.', [
        '@days' => SuspectTurnLog::RETENTION_DAYS,
        '@cap' => SuspectTurnLog::MAX_ROWS_PER_PATTERN_PER_DAY,
      ]),
      $this->t('Not recorded in this table: user names, account or session identifiers, or IP addresses. Two caveats rather than a bare claim of anonymity - a question is whatever the visitor typed, so it can contain personal information on its own; and the recorded time could be lined up against server access logs by someone who has them, which would identify the visitor.'),
      $this->t('Model refusals are detected with a heuristic over the opening of the answer, so the count indicates a trend rather than an exact tally.'),
      $this->t('Guardrail stops read zero until a guardrail set is configured for Beacon. Stops applied while the answer is streaming are counted only if the guardrail plugin reports them itself: the AI module evaluates those inside the stream and does not report them back to the caller. Stops applied before or after generation are always counted.'),
      $this->t('Injection-pattern hits are counted before a turn is refused for a missing provider or an over-long conversation, but NOT for requests the rate limiter rejects — the question has not been read at that point. During a sustained campaign the true number of attempts is therefore higher than the count shown.'),
      $this->t('"By plugin" means by the guardrail plugin label, because the AI module exposes no plugin id to callers. A guardrail set is named only when exactly one set was active for the turn; otherwise it is recorded as ambiguous.'),
      $this->t('Answers with no citations are counted whenever retrieval returned nothing. The relevance threshold ships at 0.0, which disables score filtering, so today this counts only questions the search index matched nothing for at all.'),
    ];
  }

}
