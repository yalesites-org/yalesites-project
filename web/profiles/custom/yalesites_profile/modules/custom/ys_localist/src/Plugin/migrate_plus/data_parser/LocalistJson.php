<?php

declare(strict_types=1);

namespace Drupal\ys_localist\Plugin\migrate_plus\data_parser;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate_plus\DataParserPluginInterface;
use Drupal\migrate_plus\Plugin\migrate_plus\data_parser\Json;

/**
 * Obtain JSON data for migration.
 *
 * @DataParser(
 *   id = "localist_json",
 *   title = @Translation("Localist JSON")
 * )
 */
class LocalistJson extends Json implements ContainerFactoryPluginInterface, DataParserPluginInterface {

  /**
   * Iterator over the JSON data.
   */
  protected ?\ArrayIterator $iterator = NULL;

  /**
   * Retrieves the JSON data and returns it as an array.
   *
   * @param string $url
   *   URL of a JSON feed.
   *
   * @throws \GuzzleHttp\Exception\RequestException
   */
  protected function getSourceData(string $url): array {
    $response = $this->getDataFetcherPlugin()->getResponseContent($url);

    // Convert objects to associative arrays.
    $source_data = json_decode($response, TRUE, 512, JSON_THROW_ON_ERROR);

    // If json_decode() has returned NULL, it might be that the data isn't
    // valid utf8 - see http://php.net/manual/en/function.json-decode.php#86997.
    if (is_null($source_data)) {
      $utf8response = utf8_encode($response);
      $source_data = json_decode($utf8response, TRUE, 512, JSON_THROW_ON_ERROR);
    }

    // Backwards-compatibility for depth selection.
    if (is_int($this->itemSelector)) {
      return $this->selectByDepth($source_data);
    }

    // Otherwise, we're using xpath-like selectors.
    $selectors = explode('/', trim((string) $this->itemSelector, '/'));
    foreach ($selectors as $selector) {
      if (is_array($source_data) && array_key_exists($selector, $source_data)) {
        $source_data = $source_data[$selector];
      }
    }

    $reformattedSource = [];

    foreach ($source_data as $data) {
      $eventInstance = $data['event']['event_instances'][0]['event_instance'];
      $parentEventId = $data['event']['id'];
      // Only add dates from event instances that match the parent event ID.
      if ($parentEventId == $eventInstance['event_id']) {
        $startDate = strtotime($eventInstance['start']);
        // If no end date, event is all day - set end time at start + 23h59m.
        $endDate = $eventInstance['end'] ? strtotime($eventInstance['end']) : $startDate + 86340;
        // If no end date, event is all day - set duration to 1439m.
        $duration = $eventInstance['end'] ? ($endDate - $startDate) / 60 : 1439;
        $dates[$parentEventId][] = [
          'value' => $startDate,
          'end_value' => $endDate,
          'timezone' => 'America/New_York',
          'duration' => $duration,
        ];
      }
      $reformattedSource[$parentEventId] = [
        'localist_data' => $data['event'],
        'instances' => $dates[$parentEventId],
      ];

    }

    return $reformattedSource;
  }

}
