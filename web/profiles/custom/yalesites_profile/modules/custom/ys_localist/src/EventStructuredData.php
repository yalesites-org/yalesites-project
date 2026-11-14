<?php

namespace Drupal\ys_localist;

use Drupal\Core\Cache\Cache;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\node\NodeInterface;

/**
 * Builds schema.org Event structured data (JSON-LD) for an event node.
 *
 * The array is built from MetaFieldsManager::getEventData(), so the page head
 * and the event meta block agree on the featured date, location and image.
 *
 * @see https://github.com/yalesites-org/YaleSites-Internal/issues/1808
 */
class EventStructuredData {

  /**
   * Timezone used when a stored date has none.
   */
  const DEFAULT_TIMEZONE = 'America/New_York';

  /**
   * Constructs the service.
   *
   * @param \Drupal\ys_localist\MetaFieldsManager $metaFieldsManager
   *   The event field data service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   */
  public function __construct(
    protected MetaFieldsManager $metaFieldsManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Builds the structured data for a published event node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The event node.
   * @param array|null $eventData
   *   The node's getEventData() result, if the caller already has it.
   *
   * @return array|null
   *   The schema.org Event, or NULL when the node should output none.
   */
  public function buildForNode(NodeInterface $node, ?array $eventData = NULL): ?array {
    if ($node->getType() !== 'event' || !$node->isPublished()) {
      return NULL;
    }

    $eventData ??= $this->metaFieldsManager->getEventData($node);
    if (!$eventData) {
      return NULL;
    }

    $imageUrl = NULL;
    $uri = $eventData['teaser_media']['#uri'] ?? NULL;
    if ($uri) {
      $imageUrl = $this->fileUrlGenerator->generateAbsoluteString($uri);
    }

    $statusName = '';
    if ($node->hasField('field_event_status') && ($status = $node->get('field_event_status')->entity)) {
      $statusName = $status->label();
    }

    $format = [];
    if ($node->hasField('field_event_format')) {
      $format = array_column($node->get('field_event_format')->getValue(), 'value');
    }

    $teaser = $node->hasField('field_teaser_text') ? (string) $node->get('field_teaser_text')->value : '';

    return $this->build($eventData, [
      'status' => $statusName,
      'format' => $format,
      'teaser_text' => $teaser,
      'image_url' => $imageUrl,
    ]);
  }

  /**
   * Maps event data to a schema.org Event.
   *
   * @param array $eventData
   *   The result of MetaFieldsManager::getEventData().
   * @param array $extra
   *   Values read straight off the node: 'status' (status term name),
   *   'format' (field_event_format values), 'teaser_text' and 'image_url'
   *   (absolute teaser image URL, or NULL).
   *
   * @return array|null
   *   The Event, or NULL when the event has no date.
   */
  public function build(array $eventData, array $extra = []): ?array {
    $featured = $eventData['event_featured_date'] ?? NULL;
    if (!is_array($featured) || !isset($featured['value'], $featured['end_value'])) {
      return NULL;
    }

    $allDay = !empty($featured['is_all_day']);
    $timezone = $this->resolveTimezone($featured['timezone'] ?? NULL);

    $locations = $this->buildLocations($eventData);

    $event = [
      '@context' => 'https://schema.org',
      '@type' => 'Event',
      'name' => (string) ($eventData['title'] ?? ''),
      'startDate' => $this->formatDate((int) $featured['value'], $timezone, $allDay),
      'endDate' => $this->formatDate((int) $featured['end_value'], $timezone, $allDay),
      'eventStatus' => $this->mapStatus($extra['status'] ?? ''),
      'eventAttendanceMode' => $this->mapAttendanceMode(
        $extra['format'] ?? [],
        array_column($eventData['experience'] ?? [], 'name'),
        $locations['physical'] !== NULL,
        $locations['virtual'] !== NULL,
      ),
    ];

    $description = $this->plainText($eventData['description'] ?? '');
    if ($description === '') {
      $description = $this->plainText($extra['teaser_text'] ?? '');
    }
    if ($description !== '') {
      $event['description'] = $description;
    }

    $image = $extra['image_url'] ?? $eventData['localist_image_url'] ?? NULL;
    if ($image) {
      $event['image'] = $image;
    }

    if (!empty($eventData['canonical_url'])) {
      $event['url'] = $eventData['canonical_url'];
    }

    $offers = $this->buildOffers($eventData, $extra['status'] ?? '');
    if ($offers) {
      $event['offers'] = $offers;
    }

    $location = array_values(array_filter([$locations['physical'], $locations['virtual']]));
    if (count($location) === 1) {
      $event['location'] = $location[0];
    }
    elseif ($location) {
      $event['location'] = $location;
    }

    return $event;
  }

  /**
   * Seconds until the featured occurrence ends.
   *
   * The structured data names the featured date, and the featured date moves
   * on once it ends, so a cached copy must expire then.
   *
   * @param array $eventData
   *   The result of MetaFieldsManager::getEventData().
   * @param int $now
   *   The current timestamp.
   *
   * @return int
   *   Seconds until the end, or Cache::PERMANENT when the featured date has
   *   already ended (it is the last date, so nothing takes over).
   */
  public function getMaxAge(array $eventData, int $now): int {
    $end = $eventData['event_featured_date']['end_value'] ?? NULL;
    if ($end === NULL || (int) $end <= $now) {
      return Cache::PERMANENT;
    }
    return (int) $end - $now;
  }

  /**
   * Builds the Place and VirtualLocation parts.
   *
   * @return array
   *   'physical' and 'virtual', each an array or NULL.
   */
  protected function buildLocations(array $eventData): array {
    $physical = NULL;
    $place = $eventData['place_info'] ?? [];
    if (!empty($place['name'])) {
      $name = $place['name'];
      if (!empty($eventData['room'])) {
        $name .= ', ' . $eventData['room'];
      }
      $physical = ['@type' => 'Place', 'name' => $name];

      $address = array_filter([
        '@type' => 'PostalAddress',
        'streetAddress' => $place['address'] ?? NULL,
        'addressLocality' => $place['city'] ?? NULL,
        'addressRegion' => $place['state'] ?? NULL,
        'postalCode' => $place['postal_code'] ?? NULL,
        'addressCountry' => $place['country_code'] ?? NULL,
      ]);
      if (count($address) > 1) {
        $physical['address'] = $address;
      }

      $lat = $place['latitude'] ?? NULL;
      $lng = $place['longitude'] ?? NULL;
      if (is_numeric($lat) && is_numeric($lng)) {
        $physical['geo'] = [
          '@type' => 'GeoCoordinates',
          'latitude' => (float) $lat,
          'longitude' => (float) $lng,
        ];
      }
    }
    else {
      $details = $eventData['location_details']['#text'] ?? '';
      $details = $this->plainText($details);
      if ($details !== '') {
        $physical = ['@type' => 'Place', 'address' => $details];
      }
    }

    $virtual = NULL;
    if (!empty($eventData['stream_url'])) {
      $virtual = ['@type' => 'VirtualLocation', 'url' => $eventData['stream_url']];
    }

    return ['physical' => $physical, 'virtual' => $virtual];
  }

  /**
   * Builds the offers, only when the event has a ticket or registration URL.
   */
  protected function buildOffers(array $eventData, string $statusName): ?array {
    if (empty($eventData['ticket_url'])) {
      return NULL;
    }

    $offer = ['@type' => 'Offer', 'url' => $eventData['ticket_url']];

    $cost = $eventData['ticket_cost'] ?? NULL;
    if (is_string($cost) || is_numeric($cost)) {
      $cost = trim((string) $cost);
      if (strcasecmp($cost, 'free') === 0) {
        $offer['price'] = 0;
      }
      elseif (is_numeric($cost)) {
        $offer['price'] = $cost + 0;
      }
      if (isset($offer['price'])) {
        $offer['priceCurrency'] = 'USD';
      }
    }

    if (strcasecmp(trim($statusName), 'sold out') === 0) {
      $offer['availability'] = 'https://schema.org/SoldOut';
    }

    return $offer;
  }

  /**
   * Maps the status term name to a schema.org EventStatusType.
   */
  protected function mapStatus(string $statusName): string {
    return match (strtolower(trim($statusName))) {
      'canceled', 'cancelled' => 'https://schema.org/EventCancelled',
      'postponed' => 'https://schema.org/EventPostponed',
      default => 'https://schema.org/EventScheduled',
    };
  }

  /**
   * Works out the attendance mode.
   *
   * Prefers field_event_format, then Localist's experience, then falls back
   * to which locations exist.
   *
   * @param string[] $format
   *   The field_event_format values ('inperson', 'online').
   * @param string[] $experiences
   *   The Localist experience term names (In-Person, Virtual, Hybrid).
   * @param bool $hasPhysical
   *   Whether the event has a physical location.
   * @param bool $hasVirtual
   *   Whether the event has a stream URL.
   */
  protected function mapAttendanceMode(array $format, array $experiences, bool $hasPhysical, bool $hasVirtual): string {
    $base = 'https://schema.org/';
    $inPerson = in_array('inperson', $format, TRUE);
    $online = in_array('online', $format, TRUE);
    if ($inPerson && $online) {
      return $base . 'MixedEventAttendanceMode';
    }
    if ($online) {
      return $base . 'OnlineEventAttendanceMode';
    }
    if ($inPerson) {
      return $base . 'OfflineEventAttendanceMode';
    }

    foreach ($experiences as $experience) {
      switch (strtolower(trim((string) $experience))) {
        case 'hybrid':
          return $base . 'MixedEventAttendanceMode';

        case 'virtual':
          return $base . 'OnlineEventAttendanceMode';

        case 'in-person':
        case 'in person':
          return $base . 'OfflineEventAttendanceMode';
      }
    }

    if ($hasPhysical && $hasVirtual) {
      return $base . 'MixedEventAttendanceMode';
    }
    if ($hasVirtual) {
      return $base . 'OnlineEventAttendanceMode';
    }
    return $base . 'OfflineEventAttendanceMode';
  }

  /**
   * Formats a timestamp: ISO 8601 with offset, or the date only if all day.
   */
  protected function formatDate(int $timestamp, \DateTimeZone $timezone, bool $allDay): string {
    $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
    return $date->format($allDay ? 'Y-m-d' : 'c');
  }

  /**
   * Returns a valid timezone, defaulting when the stored one is missing/bad.
   */
  protected function resolveTimezone(?string $name): \DateTimeZone {
    try {
      return new \DateTimeZone($name ?: self::DEFAULT_TIMEZONE);
    }
    catch (\Exception) {
      return new \DateTimeZone(self::DEFAULT_TIMEZONE);
    }
  }

  /**
   * Strips tags and collapses whitespace.
   */
  protected function plainText(?string $html): string {
    $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Localist pads with non-breaking spaces.
    $text = str_replace("\xC2\xA0", ' ', $text);
    return trim((string) preg_replace('/\s+/', ' ', $text));
  }

}
