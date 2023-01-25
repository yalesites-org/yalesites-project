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
   * ],
   *
   * @todo This seems fragile and would better be inside a config page for
   * admins to select.
   *
   * @var array
   */

  const ALLOWED_ENTITIES = [
    'news' => [
      'label' => 'News articles',
      'view_modes' => [
        'card' => 'News cards',
        'list_item' => 'News list items',
      ],
    ],
    'event' => [
      'label' => 'Events',
      'view_modes' => [
        'card' => 'Event cards',
        'list_item' => 'Event list items',
      ],
    ],
    'page' => [
      'label' => 'Pages',
      'view_modes' => [
        'teaser' => 'Teasers',
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
   */
  public function entityTypeList() {
    foreach (self::ALLOWED_ENTITIES as $machine_name => $type) {
      $entityTypes[$machine_name] = $type['label'];
    }

    return $entityTypes;
  }

  /**
   * Returns an array of view mode machine names and the human readable name.
   */
  public function viewModeList($content_type) {
    $viewModes = self::ALLOWED_ENTITIES[$content_type]['view_modes'];
    return $viewModes;
  }

  /**
   * Returns an array of taxonomy tags.
   */
  public function tagsList() {
    $vid = 'tags';
    /** @var Drupal\Core\Entity\EntityTypeManagerInterface $vocab */
    $vocab = $this->entityTypeManager()->getStorage('taxonomy_term');
    $terms = $vocab->loadTree($vid);

    if (empty($terms)) {
      return NULL;
    }

    foreach ($terms as $term) {
      $tagsList[$term->tid] = $term->name;
    }

    return $tagsList;
  }

  /**
   * Returns an entity label given an entity type and machine name.
   */
  public function getEntityLabel($type) {
    return self::ALLOWED_ENTITIES[$type]['label'];
  }

  /**
   * Returns a view mode label given an view mode type stored in the params.
   */
  public function getViewModeLabel($type, $view_mode) {
    return self::ALLOWED_ENTITIES[$type]['view_modes'][$view_mode];
  }

  /**
   * Returns a tag label given a term ID.
   */
  public function getTagLabel($tag) {
    $term = $this->entityTypeManager()->getStorage('taxonomy_term')->load($tag);
    return $term->name->value;
  }

  /**
   * Returns a default value for a parameter to auto-select one in the list.
   */
  public function getDefaultParamValue($type, $params) {
    $paramsDecoded = json_decode($params, TRUE);

    switch ($type) {
      /* @todo Currently, this only selects the first entity type which is
       * okay since there is only a simple dropdown for now. We should change
       * this to better support multiple entity types.
       */
      case 'types':
        $defaultParam = $paramsDecoded['filters']['types'][0];
        break;

      case 'tags':
        if (!isset($paramsDecoded['filters']['tags'])) {
          $defaultParam = NULL;
          break;
        }
        $tid = (int) $paramsDecoded['filters']['tags'][0];
        $defaultParam = $this->entityTypeManager()->getStorage('taxonomy_term')->load($tid);
        break;

      case 'limit':
        $defaultParam = (empty($paramsDecoded['limit'])) ? 0 : $paramsDecoded['limit'];
        break;

      default:
        $defaultParam = $paramsDecoded[$type];
        break;
    }
    return $defaultParam;
  }

}
