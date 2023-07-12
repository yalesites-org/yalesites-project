<?php

namespace Drupal\ys_layouts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Url;

/**
 * Block for event meta data that appears above events.
 *
 * @Block(
 *   id = "event_meta_block",
 *   admin_label = @Translation("Event Meta Block"),
 *   category = @Translation("YaleSites Layouts"),
 * )
 */
class EventMetaBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new BookNavigationBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match')
    );
  }

  /**
   * Calculates if an event is set to all day.
   *
   * Code copied from contrib module smart_date/src/SmartDateTrait.php because
   * with version 3.5 of smart_date and PHP 8.1, calling static traits
   * in this way is deprecated.
   */
  private function isAllDay($start_ts, $end_ts, $timezone = NULL) {
    if ($timezone) {
      if ($timezone instanceof DateTimeZone) {
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
   * {@inheritdoc}
   */
  public function build() {

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->routeMatch->getParameter('node');
    if (!($node instanceof NodeInterface)) {
      return [];
    }

    $title = $node->getTitle();
    $dateStart = ($node->field_event_date->first()) ? $node->field_event_date->first()->getValue()['value'] : NULL;
    $dateEnd = ($node->field_event_date->first()) ? $node->field_event_date->first()->getValue()['end_value'] : NULL;
    $dateDuration = ($node->field_event_date->first()) ? $node->field_event_date->first()->getValue()['duration'] : NULL;
    $url = ($node->field_event_cta->first()) ? Url::fromUri($node->field_event_cta->first()->getValue()['uri'])->toString() : NULL;
    $urlTitle = ($node->field_event_cta->first()) ? $node->field_event_cta->first()->getValue()['title'] : NULL;

    $eventFormats = [];

    foreach ($node->field_event_type->referencedEntities() as $entity) {
      $eventFormats[] = $entity->label();
    }
    // kint($dateDuration);
    // kint($dateDuration / 1439);
    // kint($dateDuration % 1439);

    kint($this->isAllDay($dateStart, $dateEnd));

    return [
      '#theme' => 'ys_event_meta_block',
      '#event_title__heading' => $title,
      '#event_meta__date_start' => $dateStart,
      '#event_meta__date_end' => $dateEnd,
      '#event_meta__date_duration' => $dateDuration,
      '#event_meta__format' => $eventFormats,
      '#event_meta__cta_primary__href' => $url,
      '#event_meta__cta_primary__content' => $urlTitle,
    ];
  }

}
