<?php

namespace Drupal\ys_localist;

use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\calendar_link\Twig\CalendarLinkTwigExtension;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retrieves field data from nodes for event meta block and all view modes.
 */
class MetaFieldsManager implements ContainerFactoryPluginInterface {

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * Calendar link twig extension.
   *
   * @var \Drupal\calendar_link\Twig
   */
  protected $calendarLink;

  /**
   * Localist Manager.
   *
   * @var \Drupal\ys_localist\LocalistManager
   */
  protected $localistManager;

  /**
   * Constructs a new EventMetaBlock instance.
   *
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\ys_localist\LocalistManager $localist_manager
   *   The Localist manager service.
   */
  public function __construct(
    DateFormatter $date_formatter,
    EntityTypeManager $entity_type_manager,
    LocalistManager $localist_manager,
  ) {
    $this->dateFormatter = $date_formatter;
    $this->entityTypeManager = $entity_type_manager;
    $this->localistManager = $localist_manager;
    $this->calendarLink = new CalendarLinkTwigExtension();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get('ys_localist.manager'),
    );
  }

  /**
   * Calculates if an event is set to all day.
   *
   * Code copied from contrib module smart_date/src/SmartDateTrait.php because
   * with version 3.6 of smart_date and PHP 8.1, calling static traits
   * in this way is deprecated. Patches to fix failed to apply.
   */
  private function isAllDay($start_ts, $end_ts, $timezone = NULL) {
    if ($timezone) {
      if ($timezone instanceof \DateTimeZone) {
        // If provided as an object, convert to a string.
        $timezone = $timezone->getName();
      }
      // Apply a specific timezone provided.
      $default_tz = date_default_timezone_get();
      date_default_timezone_set($timezone);
    }
    // Format timestamps to predictable format for comparison.
    $temp_start = date('H:i', $start_ts);
    $temp_end = date('H:i', $end_ts);
    if ($timezone) {
      // Revert to previous timezone.
      date_default_timezone_set($default_tz);
    }
    if ($temp_start == '00:00' && $temp_end == '23:59') {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Returns filter values for a given filter field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node object.
   * @param string $filterField
   *   The field machine name.
   *
   * @return array
   *   An array of filter names and URL to visit the taxonomy term view page.
   */
  private function getFilterValues($node, $filterField) {
    $filterValues = [];
    if ($node->$filterField) {
      $values = $node->$filterField->getValue();
      if ($values) {
        foreach ($values as $value) {
          /** @var \Drupal\taxonomy\Entity\Term $typeInfo */
          $typeInfo = $this->entityTypeManager->getStorage('taxonomy_term')->load($value['target_id']);
          $name = $url = "";
          if ($typeInfo) {
            $name = $typeInfo->getName();
            $url = $typeInfo->toUrl()->toString();
          }
          $filterValues[] = [
            'name' => $name,
            'url' => $url,
          ];
        }
      }
    }
    return $filterValues;
  }

  /**
   * Gets event all fields.
   */
  public function getEventData($node) {
    if (!($node instanceof NodeInterface)) {
      return [];
    }

    // Event basic fields.
    $localistId = $node->field_localist_id->first() ? $node->field_localist_id->first()->getValue()['value'] : NULL;
    $experience = $this->getFilterValues($node, 'field_localist_event_experience');
    $eventTypes = $this->getFilterValues($node, 'field_localist_event_type');
    $eventAudience = $this->getFilterValues($node, 'field_event_audience');
    $eventTopics = $this->getFilterValues($node, 'field_event_topics');
    $ticketLink = $node->field_ticket_registration_url->first() ? $node->field_ticket_registration_url->first()->getValue()['uri'] : NULL;
    $ticketCost = $node->field_ticket_cost->first() ? $node->field_ticket_cost->first()->getValue()['value'] : NULL;
    $costButtonText = $ticketCost ? 'Buy Tickets' : 'Register';
    $description = $node->field_event_description->first() ? $node->field_event_description->first()->getValue()['value'] : NULL;
    $room = $node->field_event_room->first() ? $node->field_event_room->first()->getValue()['value'] : NULL;
    $externalEventWebsiteUrl = ($node->field_event_cta->first()) ? Url::fromUri($node->field_event_cta->first()->getValue()['uri'])->toString() : NULL;
    $externalEventWebsiteTitle = ($node->field_event_cta->first()) ? $node->field_event_cta->first()->getValue()['title'] : NULL;
    $localistImageUrl = ($node->field_localist_event_image_url->first()) ? Url::fromUri($node->field_localist_event_image_url->first()->getValue()['uri'])->toString() : NULL;
    $localistImageAlt = $node->field_localist_event_image_alt->first() ? $node->field_localist_event_image_alt->first()->getValue()['value'] : NULL;
    $hasRegister = $node->field_localist_register_enabled->first() ? (bool) $node->field_localist_register_enabled->first()->getValue()['value'] : FALSE;
    $localistUrl = ($node->field_localist_event_url->first()) ? Url::fromUri($node->field_localist_event_url->first()->getValue()['uri'])->toString() : NULL;
    $streamUrl = ($node->field_stream_url->first()) ? Url::fromUri($node->field_stream_url->first()->getValue()['uri'])->toString() : NULL;
    $streamEmbedCode = $node->field_stream_embed_code->first() ? $node->field_stream_embed_code->first()->getValue()['value'] : NULL;

    // Retrieve the source taxonomy term name.
    $sourceTaxonomyTermName = '';
    if ($node->field_event_source->first()) {
      $termId = $node->field_event_source->first()->getValue()['target_id'];
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($termId);
      $sourceTaxonomyTermName = $term->getName();
    }
    $eventSource = $sourceTaxonomyTermName;

    // Localist register ticket changes.
    $localistRegisterTickets = $hasRegister ? $this->localistManager->getTicketInfo($localistId) : NULL;
    if ($localistRegisterTickets) {
      $costButtonText = 'Register';
      foreach ($localistRegisterTickets as $ticketInfo) {
        if ($ticketInfo['price'] > 0) {
          $costButtonText = 'Buy Tickets';
        }
        $ticketCost[] = $ticketInfo['name'] . ": $" . $ticketInfo['price'];
      }
      $ticketLink = $localistUrl . "#tickets=1";
    }
    else {
      $hasRegister = FALSE;
    }

    // Dates.
    $dates = $node->field_event_date->getValue();
    $this->orderEventDates($dates);
    $featuredIndex = $this->getFeaturedDateIndex($dates);
    $featuredDate = $this->getFeaturedDateFromIndex($dates, $featuredIndex);

    // Teaser responsive image.
    $teaserMediaRender = [];
    $teaserMediaId = ($node->field_teaser_media->first()) ? $node->field_teaser_media->getValue()[0]['target_id'] : NULL;
    if ($teaserMediaId) {
      /** @var Drupal\media\Entity\Media $teaserMedia */
      if ($teaserMedia = $this->entityTypeManager->getStorage('media')->load($teaserMediaId)) {
        /** @var Drupal\file\FileStorage $fileEntityStorage */
        $fileEntityStorage = $this->entityTypeManager->getStorage('file');
        $teaserImageFileUri = $fileEntityStorage->load($teaserMedia->field_media_image->target_id)->getFileUri();
        $isTeaserImageLandscape = $teaserMedia->get('thumbnail')->width > $teaserMedia->get('thumbnail')->height;

        $teaserMediaRender = [
          '#type' => 'responsive_image',
          '#responsive_image_style_id' => $isTeaserImageLandscape ? 'card_featured_3_2' : 'content_spotlight_portrait',
          '#uri' => $teaserImageFileUri,
          '#attributes' => [
            'alt' => $teaserMedia->get('field_media_image')->first()->get('alt')->getValue(),
          ],
        ];
      }
    }

    // Place info.
    $place = [];
    if ($placeRef = $node->field_event_place->first()) {
      /** @var \Drupal\taxonomy\Entity\Term $placeInfo */
      $placeInfo = $this->entityTypeManager->getStorage('taxonomy_term')->load($placeRef->getValue()['target_id']);
      if ($placeInfo) {
        $place = [
          'name' => $placeInfo->getName(),
          'address' => $placeInfo->field_address->address_line1 ?? NULL,
          'city' => $placeInfo->field_address->locality ?? NULL,
          'state' => $placeInfo->field_address->administrative_area ?? NULL,
          'postal_code' => $placeInfo->field_address->postal_code ?? NULL,
          'country_code' => $placeInfo->field_address->country_code ?? NULL,
          'latitude' => $placeInfo->field_latitude->first() ? $placeInfo->field_latitude->first()->getValue()['value'] : NULL,
          'longitude' => $placeInfo->field_longitude->first() ? $placeInfo->field_longitude->first()->getValue()['value'] : NULL,
        ];
      }
    }

    /*
     * ICS URL.
     *
     * If a Localist ICU url exists, use it. If not, calculate from Drupal date.
     */
    $icsUrl = $node->field_localist_ics_url->first() ? $node->field_localist_ics_url->first()->getValue()['uri'] : NULL;
    if (!$icsUrl && $dates) {
      // Dates might not be 0-based.
      $firstDate = reset($dates);
      $tz = new \DateTimeZone('America/New_York');
      $date = new \DateTime();
      $start = $date->createFromFormat('U', $firstDate['value'], $tz);
      $end = $date->createFromFormat('U', $firstDate['end_value'], $tz);

      /* Note one additional argument at the end of this function can create an
       * address in the ICS file.
       * @see calendar_link/src/Twig/CalendarLinkTwigExtension.php
       */
      $icsUrl = $this->calendarLink->calendarLink(
        'ics',
        $node->getTitle(),
        $start,
        $end,
        $firstDate['is_all_day'],
        $node->toUrl()->setAbsolute()->toString()
      );
    }

    return [
      'title' => $node->getTitle(),
      'dates' => $dates,
      'ics' => $icsUrl,
      'canonical_url' => $node->toUrl()->setAbsolute()->toString(),
      'experience' => $experience,
      'ticket_url' => $ticketLink,
      'ticket_cost' => $ticketCost,
      'description' => $description,
      'room' => $room,
      'external_website_url' => $externalEventWebsiteUrl,
      'external_website_title' => $externalEventWebsiteTitle,
      'localist_image_url' => $localistImageUrl,
      'localist_image_alt' => $localistImageAlt,
      'teaser_media' => $teaserMediaRender,
      'place_info' => $place,
      'event_types' => $eventTypes,
      'event_audience' => $eventAudience,
      'event_topics' => $eventTopics,
      'has_register' => $hasRegister,
      'cost_button_text' => $costButtonText,
      'localist_url' => $localistUrl,
      'stream_url' => $streamUrl,
      'stream_embed_code' => $streamEmbedCode,
      'event_source' => $eventSource,
      'event_featured_date' => $featuredDate,
      'event_featured_index' => $featuredIndex,
    ];
  }

  /**
   * Orders event dates by start date.
   *
   * @param array $dates
   *   An array of dates.
   *
   * @return void
   *   The array is passed by reference.
   */
  protected function orderEventDates(&$dates) {
    if ($dates) {
      foreach ($dates as $key => $date) {
        $dates[$key]['formatted_start_date'] = $this->dateFormatter->format($date['value'], 'event_date_only');
        $dates[$key]['formatted_start_time'] = $this->dateFormatter->format($date['value'], 'event_time_only');
        $dates[$key]['formatted_end_date'] = $this->dateFormatter->format($date['end_value'], 'event_date_only');
        $dates[$key]['formatted_end_time'] = $this->dateFormatter->format($date['end_value'], 'event_time_only');
        $dates[$key]['original_start'] = $date['value'];
        $dates[$key]['original_end'] = $date['end_value'];
        $dates[$key]['is_all_day'] = $this->isAllDay($date['value'], $date['end_value']);
        $dates[$key]['is_past_event'] = $date['end_value'] < time();
      }
      // Sort dates - first date is next upcoming date.
      asort($dates);
      // Reindex the array so position matches array keys.
      $dates = array_values($dates);
    }
  }

  /**
   * Get the index of the featured date from the list of dates.
   *
   * @param array $dates
   *   An array of dates.
   *
   * @return int|null
   *   The index of the featured date or NULL.
   */
  protected function getFeaturedDateIndex($dates) {
    $featuredIndex = NULL;

    if (!is_array($dates)) {
      return $dates;
    }

    // Track the position (0, 1, 2...) not the array key.
    $position = 0;
    foreach ($dates as $date) {
      if ($date['end_value'] >= time()) {
        $featuredIndex = $position;
        break;
      }
      $position++;
    }

    if (!isset($featuredIndex)) {
      // If no upcoming date, use the last position.
      $featuredIndex = count($dates) - 1;
    }

    return $featuredIndex;
  }

  /**
   * Get the first upcoming date from the list of dates.
   *
   * @param array $dates
   *   An array of dates.
   *
   * @return array|NodeInterface
   *   The first upcoming date or what was passed.
   */
  protected function getFeaturedDate($dates) {
    $featuredDate = NULL;

    if (!is_array($dates)) {
      return $dates;
    }

    // Get the first date that is not in the past.
    foreach ($dates as $date) {
      if ($date['end_value'] >= time()) {
        $featuredDate = $date;
        break;
      }
    }

    // If none were found, use the last element.
    if (!$featuredDate) {
      $featuredDate = end($dates);
    }

    return $featuredDate;
  }

  /**
   * Get the featured date from the list of dates by index.
   *
   * @param array $dates
   *   An array of dates.
   * @param int $index
   *   The index of the date to return.
   *
   * @return array|NodeInterface
   *   The date at the given index or what was passed.
   */
  protected function getFeaturedDateFromIndex($dates, $index) {
    if (!is_array($dates)) {
      return $dates;
    }

    if (isset($dates[$index])) {
      return $dates[$index];
    }

    return end($dates);
  }

}
