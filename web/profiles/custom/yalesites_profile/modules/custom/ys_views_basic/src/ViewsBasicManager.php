<?php

namespace Drupal\ys_views_basic;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityDisplayRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service for managing the Views Basic plugins.
 */
class ViewsBasicManager extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Allowed entity types for users to select.
   *
   * Format:
   * 'content_type_machine_name' => [
   *   'label' => 'Human readable label',
   *   'view_modes' => [
   *     'view_mode_machine_name1' => 'Human readable label 1',
   *     'view_mode_machine_name2' => 'Human readable label 2',
   *   ],
   *   'sort_by' => [
   *     'field_machine_name:ASC' => 'Human readable sort label',
   *   ]
   * ],
   *
   * @todo This seems fragile and would better be inside a config page for
   * admins to select.
   *
   * @var array
   */

  const ALLOWED_ENTITIES = [
    'news' => [
      'label' => 'Posts',
      'view_modes' => [
        'card' => 'News Card Grid',
        'list_item' => 'News List',
      ],
      'sort_by' => [
        'field_publish_date:DESC' => 'Publish Date - newer first',
        'field_publish_date:ASC' => 'Publish Date - older first',
      ],
    ],
    'event' => [
      'label' => 'Events',
      'view_modes' => [
        'card' => 'Event Card Grid',
        'list_item' => 'Event List',
      ],
      'sort_by' => [
        'field_event_date:DESC' => 'Event Date - newer first',
        'field_event_date:ASC' => 'Event Date - older first',
      ],
    ],
    'page' => [
      'label' => 'Pages',
      'view_modes' => [
        'teaser' => 'Teasers',
      ],
      'sort_by' => [
        'title:ASC' => 'Title - A-Z',
        'title:DESC' => 'Title - Z-A',
      ],
    ],
  ];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepository
   */
  protected $entityDisplayRepository;

  /**
   * Constructs a new ViewsBasicManager object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityDisplayRepository $entity_display_repository
    ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityDisplayRepository = $entity_display_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository')
    );
  }

  /**
   * Returns an array of entity type machine names and the human readable name.
   *
   * @return array
   *   An array of human readable entity names, with machine name as the key.
   */
  public function entityTypeList() {
    foreach (self::ALLOWED_ENTITIES as $machine_name => $type) {
      $entityTypes[$machine_name] = $type['label'];
    }

    return $entityTypes;
  }

  /**
   * Returns an array of view mode machine names and the human readable name.
   *
   * @param string $content_type
   *   The entity machine name.
   *
   * @return array
   *   An array of human readable view modes, with machine name as the key.
   */
  public function viewModeList($content_type) {
    $viewModes = self::ALLOWED_ENTITIES[$content_type]['view_modes'];
    return $viewModes;
  }

  /**
   * Returns an array of sort by machine names and the human readable name.
   *
   * @param string $content_type
   *   The entity machine name.
   *
   * @return array
   *   An array of human readable sorts, with machine name as the key.
   */
  public function sortByList($content_type) {
    $sortByList = self::ALLOWED_ENTITIES[$content_type]['sort_by'];
    return $sortByList;
  }

  /**
   * Returns a label given a content type and optional sub parameter.
   *
   * @param string $content_type
   *   Machine name of an entity type.
   * @param string $label_type
   *   Type of label to get: entity, view_mode, or sort_by.
   * @param string $sub_param
   *   Sub parameter name: view_mode or sort_by.
   *
   * @return string
   *   Human readable view mode label.
   */
  public function getLabel($content_type, $label_type, $sub_param = NULL) {
    if ($label_type == 'entity') {
      return self::ALLOWED_ENTITIES[$content_type]['label'];
    }
    if ($sub_param) {
      return self::ALLOWED_ENTITIES[$content_type][$label_type][$sub_param];
    }
    return '';
  }

  /**
   * Returns a tag label given a term ID.
   *
   * @param int $tag
   *   The taxonomy term ID.
   *
   * @return string
   *   The label of the taxonomy term or empty string.
   */
  public function getTagLabel($tag) {
    $term = $this->entityTypeManager()->getStorage('taxonomy_term')->load($tag);
    return ($term) ? $term->name->value : '';
  }

  /**
   * Returns a default value for a parameter to auto-select one in the list.
   *
   * @param string $type
   *   An internal machine name for the type of default parameter to retrieve.
   * @param string $params
   *   The full stringified JSON encoded list of parameters.
   *
   * @return string
   *   The machine default value.
   */
  public function getDefaultParamValue($type, $params) {
    $paramsDecoded = json_decode($params, TRUE);
    $defaultParam = NULL;

    switch ($type) {
      /* @todo Currently, this only selects the first entity type which is
       * okay since there is only a simple dropdown for now. We should change
       * this to better support multiple entity types.
       */
      case 'types':
        if (!empty($paramsDecoded['filters']['types'][0])) {
          $defaultParam = $paramsDecoded['filters']['types'][0];
        }
        break;

      case 'tags':
        if (!empty($paramsDecoded['filters']['tags'][0])) {
          $tid = (int) $paramsDecoded['filters']['tags'][0];
          $defaultParam = $this->entityTypeManager()->getStorage('taxonomy_term')->load($tid);
        }
        break;

      case 'limit':
        $defaultParam = (empty($paramsDecoded['limit'])) ? 0 : (int) $paramsDecoded['limit'];
        break;

      default:
        $defaultParam = $paramsDecoded[$type];
        break;
    }
    return $defaultParam;
  }

}
