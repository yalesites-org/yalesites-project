<?php

namespace Drupal\ys_views_basic;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityDisplayRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
    'post' => [
      'label' => 'Posts',
      'view_modes' => [
        'card' => 'Post Card Grid',
        'list_item' => 'Post List',
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
        'card' => 'Page Grid',
        'list_item' => 'Page List',
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
   * The term storage.
   *
   * @var \Drupal\taxonomy\TermStorageInterface
   */
  protected $termStorage;

  /**
   * Constructs a new ViewsBasicManager object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityDisplayRepository $entity_display_repository
    ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityDisplayRepository = $entity_display_repository;
    $this->termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
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
   * Retrieves an overridden Views Basic Scaffold view.
   *
   * Views Basic Scaffold view is overridden with the data from the parameters.
   *
   * @param string $type
   *   Can be either 'rendered' or 'count'.
   * @param string $params
   *   JSON of the parameter settings.
   *
   * @return array|int
   *   An array of a rendered view or a count of the number of results based
   *   on the parameters specified.
   */
  public function getView($type, $params) {
    // Prevents views recursion.
    static $running;
    if ($running) {
      return NULL;
    }
    $running = TRUE;

    // Set up the view and initial decoded parameters.
    $view = Views::getView('views_basic_scaffold');
    $view->setDisplay('block_1');
    $paramsDecoded = json_decode($params, TRUE);

    /*
     * Sets the arguments that will get passed to contextual filters as well
     * as to the custom sort plugin (ViewsBasicSort), custom style
     * plugin (ViewsBasicDynamicStyle), and custom pager
     * (ViewsBasicFullPager).
     *
     * This is coded this way to work with Ajax pagers specifically as
     * without arguments, the subsequent Ajax calls to load more data do not
     * know what sorting, filters, view modes, or number if items in the pager
     * to use.
     *
     * The order of these arguments is required as follows:
     * 0) Content type machine name (can combine content types with + or ,)
     * 1) Taxonomy term ID to include (can combine with + or ,)
     * 2) Taxonomy term ID to exclude (can combine with + or ,)
     * 3) Sort field and direction (in format field_name:ASC)
     * 4) View mode machine name (i.e. teaser)
     * 5) Items per page (set to 0 for all items)
     * 6) Event time period (future, past, all)
     */

    $filterType = implode('+', $paramsDecoded['filters']['types']);

    // Get terms to include.
    if (isset($paramsDecoded['filters']['terms_include'])) {
      foreach ($paramsDecoded['filters']['terms_include'] as $term) {
        $termsIncludeArray[] = (int) $term;
      }
    }

    // Get terms to exclude.
    if (isset($paramsDecoded['filters']['terms_exclude'])) {
      foreach ($paramsDecoded['filters']['terms_exclude'] as $term) {
        $termsExcludeArray[] = (int) $term;
      }
    }

    // Set operator: "+" is "OR" and "," is "AND".
    $operator = $paramsDecoded['operator'] ?: '+';
    $termsInclude = isset($termsIncludeArray) ? implode($operator, $termsIncludeArray) : 'all';
    $termsExclude = isset($termsExcludeArray) ? implode($operator, $termsExcludeArray) : NULL;

    if (
      ($type == 'count' && $paramsDecoded['display'] != 'limit') ||
      ($type == 'rendered' && $paramsDecoded['display'] == 'all')) {
      $itemsLimit = 0;
    }
    else {
      $itemsLimit = $paramsDecoded['limit'];
    }

    $view->setArguments(
      [
        'type' => $filterType,
        'terms_include' => $termsInclude,
        'terms_exclude' => $termsExclude,
        'sort' => $paramsDecoded['sort_by'],
        'view' => $paramsDecoded['view_mode'],
        'items' => $itemsLimit,
        'event_time_period' => str_contains($filterType, 'event') ? $paramsDecoded['filters']['event_time_period'] : NULL,
      ]
    );

    /*
     * End setting dynamic arguments.
     */

    $view->execute();

    // Unset the pager. Needs to be done after view->execute();
    if ($paramsDecoded['display'] != "pager") {
      unset($view->pager);
    }

    switch ($type) {
      case "rendered":
        $view = $view->preview();
        break;

      case "count":
        $view = count($view->result);
        break;
    }

    // End current view run.
    $running = FALSE;

    return $view;
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
    $label = '';
    switch ($label_type) {
      case 'entity':
        $label = self::ALLOWED_ENTITIES[$content_type]['label'];
        break;

      default:
        $label = ($sub_param) ? self::ALLOWED_ENTITIES[$content_type][$label_type][$sub_param] : '';
    }
    return $label;
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
  public function getTagLabel($tag) : string {
    $term = $this->termStorage->load($tag);
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

      case 'terms_include':
      case 'terms_exclude':
        if (!empty($paramsDecoded['filters'][$type])) {
          foreach ($paramsDecoded['filters'][$type] as $term) {
            $defaultParam[] = (int) $term;
          }
        }
        break;

      case 'limit':
        $defaultParam = (empty($paramsDecoded['limit'])) ? 10 : (int) $paramsDecoded['limit'];
        break;

      case 'event_time_period':
        $defaultParam = (empty($paramsDecoded['filters']['event_time_period'])) ? 'future' : $paramsDecoded['filters']['event_time_period'];
        break;

      default:
        $defaultParam = $paramsDecoded[$type];
        break;
    }
    return $defaultParam;
  }

  /**
   * Returns the vocabulary id for a given term.
   *
   * @param mixed $term
   *   The taxonomy term.
   *
   * @return string
   *   The vocabulary machine name.
   */
  private function getVocabulary($term) : string {
    return $term->bundle();
  }

  /**
   * Returns the vocabulary label for a given term.
   *
   * @param mixed $term
   *   The taxonomy term.
   * @param \Drupal\taxonomy\VocabularyInterface $vocabularyInterface
   *   The vocabulary class to load from.
   *
   * @return string
   *   The vocabulary label.
   */
  private function getVocabularyLabel($term, $vocabularyInterface) : string {
    return $vocabularyInterface->load($this->getVocabulary($term))->label();
  }

  /**
   * Returns the label for a given term with the vocabulary label.
   *
   * @param mixed $term
   *   The taxonomy term.
   *
   * @return string
   *   The label with the vocabulary label.
   */
  private function getLabelWithVocabularyLabel($term) : string {
    return $term->label() . ' (' . $this->getVocabularyLabel($term, $this->entityTypeManager->getStorage('taxonomy_vocabulary')) . ')';
  }

  /**
   * Returns an array of all tags.
   *
   * @return array
   *   An array of all taxonomy term IDs and labels.
   */
  public function getAllTags() : array {
    $terms = $this->termStorage->loadMultiple();
    $tagList = [];

    foreach ($terms as $term) {
      $tagList[$term->id()] = $this->getLabelWithVocabularyLabel($term);
    }

    asort($tagList);

    return $tagList;
  }

}
