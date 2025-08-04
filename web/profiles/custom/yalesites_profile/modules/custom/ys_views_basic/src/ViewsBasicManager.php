<?php

namespace Drupal\ys_views_basic;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityDisplayRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
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
      'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/content-type-post.svg',
      'img_alt' => 'Speech bubble icon',
      'view_modes' => [
        'card' => [
          'label' => 'Post Card Grid',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-card-grid.svg',
          'img_alt' => 'Icon showing 3 generic cards next to each other. Image placement is on the top of each card.',
        ],
        'list_item' => [
          'label' => 'Post List',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-list-view.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other. Image placement is on the left of each list item.',
        ],
        'condensed' => [
          'label' => 'Condensed',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-condensed.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other with no images on the items.',
        ],
      ],
      'sort_by' => [
        'field_publish_date:DESC' => 'Publish Date - newer first',
        'field_publish_date:ASC' => 'Publish Date - older first',
      ],
    ],
    'event' => [
      'label' => 'Events',
      'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/content-type-event.svg',
      'img_alt' => 'Calendar icon',
      'view_modes' => [
        'card' => [
          'label' => 'Event Card Grid',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-card-grid.svg',
          'img_alt' => 'Icon showing 3 generic cards next to each other. Image placement is on the top of each card.',
        ],
        'list_item' => [
          'label' => 'Event List',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-list-view.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other. Image placement is on the left of each list item.',
        ],
        'condensed' => [
          'label' => 'Condensed',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-condensed.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other with no images on the items.',
        ],
      ],
      'sort_by' => [
        'field_event_date:DESC' => 'Event Date - newer first',
        'field_event_date:ASC' => 'Event Date - older first',
      ],
    ],
    'page' => [
      'label' => 'Pages',
      'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/content-type-page.svg',
      'img_alt' => 'Blank page icon',
      'view_modes' => [
        'card' => [
          'label' => 'Page Grid',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-card-grid.svg',
          'img_alt' => 'Icon showing 3 generic cards next to each other. Image placement is on the top of each card.',
        ],
        'list_item' => [
          'label' => 'Page List',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-list-view.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other. Image placement is on the left of each list item.',
        ],
        'condensed' => [
          'label' => 'Condensed',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-condensed.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other with no images on the items.',
        ],
      ],
      'sort_by' => [
        'title:ASC' => 'Title - A-Z',
        'title:DESC' => 'Title - Z-A',
      ],
    ],
    'profile' => [
      'label' => 'Profiles',
      'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/content-type-profile.svg',
      'img_alt' => 'Generic person head icon',
      'view_modes' => [
        'card' => [
          'label' => 'Profile Grid',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-card-grid.svg',
          'img_alt' => 'Icon showing 3 generic cards next to each other. Image placement is on the top of each card.',
        ],
        'list_item' => [
          'label' => 'Profile List',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-list-view.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other. Image placement is on the left of each list item.',
        ],
        'directory' => [
          'label' => 'Directory Grid',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-directory.svg',
          'img_alt' => 'Icon showing 3 cards next to each other with a generic person image on the top of each card.',
        ],
        'condensed' => [
          'label' => 'Condensed',
          'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-condensed.svg',
          'img_alt' => 'Icon showing 3 generic list items one on top of the other with no images on the items.',
        ],
      ],
      'sort_by' => [
        'field_last_name:ASC' => 'Last Name - A-Z',
        'field_last_name:DESC' => 'Last Name - Z-A',
      ],
    ],
  ];

  /**
   * Default pin label.
   */
  const DEFAULT_PIN_LABEL = 'Pinned';

  /*
   * Define constants for content types.
   */
  const CONTENT_TYPE_POST = 'post';
  const CONTENT_TYPE_EVENT = 'event';
  const CONTENT_TYPE_PAGE = 'page';
  const CONTENT_TYPE_PROFILE = 'profile';

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
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected $cacheTagsInvalidator;

  /**
   * Constructs a new ViewsBasicManager object.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityDisplayRepository $entity_display_repository,
    RouteMatchInterface $route_match,
    CacheTagsInvalidatorInterface $cache_tags_invalidator,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityDisplayRepository = $entity_display_repository;
    $this->termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $this->routeMatch = $route_match;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository'),
      $container->get('current_route_match'),
      $container->get('cache_tags.invalidator'),
    );
  }

  /**
   * Initializes the view based on the content type.
   *
   * @param array $types
   *   An array of content types.
   *
   * @return \Drupal\views\ViewExecutable
   *   The view object.
   */
  public function initView($types) {
    if (in_array('event', $types)) {
      return Views::getView('views_basic_scaffold_events');
    }
    else {
      return Views::getView('views_basic_scaffold');
    }

  }

  /**
   * Sets up the view with the parameters.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view object.
   * @param string $params
   *   The JSON encoded string of parameters.
   *
   * @return void
   *   No return value.
   */
  public function setupView(&$view, $params) {
    static $setupRunning;
    if ($setupRunning) {
      return;
    }
    $setupRunning = TRUE;

    $paramsDecoded = json_decode($params, TRUE);
    $pinned_to_top = isset($paramsDecoded['pinned_to_top']) ? (bool) $paramsDecoded['pinned_to_top'] : FALSE;

    /* Events need to have aggregation turned on in the view. Therefore, we
     * retrieve a special event scaffold view and apply sorting here instead of
     * the custom sort plugin.
     */

    if (in_array('event', $paramsDecoded['filters']['types'])) {
      $sortArray = [];
      $sortDirection = explode(":", $paramsDecoded['sort_by']);

      if ($pinned_to_top) {
        $sortArray[] = [
          'id' => 'sticky',
          'table' => "node_field_data",
          'field' => 'sticky',
          'order' => 'desc',
        ];
      }

      $sortArray[] =
      [
        'id' => 'field_event_date_value',
        'table' => "node__field_event_date",
        'field' => 'field_event_date_value',
        'order' => $sortDirection[1],
      ];

      $view->getDisplay()->setOption('sorts', $sortArray);
    }

    $view->setDisplay('block_1');
    $filterType = implode('+', $paramsDecoded['filters']['types']);

    // Retrieve the current filter options from the view's display settings.
    $filters = $view->getDisplay()->getOption('filters');

    // Mapping content types to their respective category filters.
    $category_filters = [
      self::CONTENT_TYPE_POST => 'field_category_target_id',
      self::CONTENT_TYPE_EVENT => 'field_category_target_id',
      self::CONTENT_TYPE_PAGE => 'field_category_target_id_1',
      self::CONTENT_TYPE_PROFILE => 'field_affiliation_target_id',
    ];

    // Determine the category filter name based on the filter type.
    $category_filter_name = $category_filters[$filterType] ?? NULL;

    // Show the exposed filter 'Category' or 'Affiliation'.
    if (!empty($paramsDecoded['exposed_filter_options']['show_category_filter']) && $category_filter_name) {
      // Determine which filters to unset based on the current filter type.
      $filters_to_unset = match ($filterType) {
        self::CONTENT_TYPE_POST, self::CONTENT_TYPE_EVENT => [
          'field_category_target_id_1',
          'field_affiliation_target_id',
        ],
        self::CONTENT_TYPE_PROFILE => [
          'field_category_target_id',
          'field_category_target_id_1',
        ],
        self::CONTENT_TYPE_PAGE => [
          'field_category_target_id',
          'field_affiliation_target_id',
        ],
        default => [],
      };

      // Remove the filters that are not relevant to the current type.
      foreach ($filters_to_unset as $filter) {
        unset($filters[$filter]);
      }

      // Check if 'category_included_terms' is provided for the current
      // filter type.
      if (!empty($paramsDecoded['category_included_terms'])) {
        // Determine the vocabulary ID based on the selected filter type.
        $vid = $filterType == self::CONTENT_TYPE_PROFILE
          ? 'affiliation'
          : "{$filterType}_category";

        // Limit the filter to specific terms if provided.
        $filters[$category_filter_name]['value'] = $this->getChildTermsByParentId($paramsDecoded['category_included_terms'], $vid);
        $filters[$category_filter_name]['limit'] = TRUE;
        $filters[$category_filter_name]['expose']['reduce'] = TRUE;
      }

      // Set a custom label for the 'Category' filter if provided.
      if (!empty($paramsDecoded['category_filter_label'])) {
        $filters[$category_filter_name]['expose']['label'] = $paramsDecoded['category_filter_label'];
      }
    }
    else {
      // Remove all category and affiliation filters if 'show_category_filter'
      // is not set or category filter name is not defined.
      foreach ($category_filters as $filter_name) {
        unset($filters[$filter_name]);
      }
    }

    // Custom vocab filter.
    if (!empty($paramsDecoded['exposed_filter_options']['show_custom_vocab_filter'])) {
      // Get the label of the custom vocab.
      $custom_vocab_label = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load('custom_vocab')->label();
      $filters['field_custom_vocab_target_id']['expose']['label'] = $custom_vocab_label;

      // Check if 'custom_vocab_included_terms' is provided for the current
      // filter type.
      if (!empty($paramsDecoded['custom_vocab_included_terms'])) {
        // Determine the vocabulary ID based on the selected filter type.
        $vid = 'custom_vocab';

        // Limit the filter to specific terms if provided.
        $filters['field_custom_vocab_target_id']['value'] = $this->getChildTermsByParentId($paramsDecoded['custom_vocab_included_terms'], $vid);
        $filters['field_custom_vocab_target_id']['limit'] = TRUE;
        $filters['field_custom_vocab_target_id']['expose']['reduce'] = TRUE;
      }
    }
    else {
      // Remove filter if 'show filter' field is not set.
      unset($filters['field_custom_vocab_target_id']);
    }

    // Audience filter.
    if (!isset($paramsDecoded['exposed_filter_options']['show_audience_filter'])) {
      unset($filters['field_audience_target_id']);
    }

    if (!isset($paramsDecoded['exposed_filter_options']['show_search_filter'])) {
      // If the 'show_search_filter' option is not set,
      // remove the 'combine' filter.
      // The 'combine' filter is used for full-text search
      // across multiple fields.
      unset($filters['combine']);
    }

    if (!isset($paramsDecoded['exposed_filter_options']['show_year_filter']) || $filterType !== self::CONTENT_TYPE_POST) {
      // Remove the 'Year' filter if the 'show_year_filter' is not set.
      unset($filters['post_year_filter']);
    }

    // Set the modified filters back to the view display options.
    $view->getDisplay()->setOption('filters', $filters);

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

    $termsIncludeArray = [];
    $termsExcludeArray = [];

    // Get terms to include.
    if (isset($paramsDecoded['filters']['terms_include'])) {
      foreach ($paramsDecoded['filters']['terms_include'] as $term) {
        $termsIncludeArray[] = $this->getTermId($term);
      }
    }

    // Get terms to exclude.
    if (isset($paramsDecoded['filters']['terms_exclude'])) {
      foreach ($paramsDecoded['filters']['terms_exclude'] as $term) {
        $termsExcludeArray[] = $this->getTermId($term);
      }
    }

    // Set operator: "+" is "OR" and "," is "AND".
    $operator = $paramsDecoded['operator'] ?? '+';

    // Fix for older setting terms for nodes not saved with the new storage.
    if (isset($termsIncludeArray[0]) && is_array($termsIncludeArray[0])) {
      foreach ($termsIncludeArray as $terms) {
        $termsIncludeArrayFixed[] = $terms['target_id'];
      }
      $termsIncludeArray = $termsIncludeArrayFixed;
    }
    if (isset($termsExcludeArray[0]) && is_array($termsExcludeArray[0])) {
      foreach ($termsExcludeArray as $terms) {
        $termsExcludeArrayFixed[] = $terms['target_id'];
      }
      $termsExcludeArray = $termsExcludeArrayFixed;
    }
    // End fix.
    $termsInclude = (count($termsIncludeArray) != 0) ? implode($operator, $termsIncludeArray) : 'all';
    $termsExclude = (count($termsExcludeArray) != 0) ? implode($operator, $termsExcludeArray) : NULL;

    if ($paramsDecoded['display'] == 'all') {
      $itemsLimit = 0;
    }
    else {
      $itemsLimit = $paramsDecoded['limit'];
    }

    $eventTimePeriod = $paramsDecoded['filters']['event_time_period'] ?? NULL;

    // Determine if we are in a state where field_options doesn't yet exist
    // (pre-existing view)
    $no_field_display_options_saved = !array_key_exists('field_options', $paramsDecoded) || !is_array($paramsDecoded['field_options']);

    $field_display_options = [
      'show_categories' => (int) !empty($paramsDecoded['field_options']['show_categories']),
      'show_tags' => (int) !empty($paramsDecoded['field_options']['show_tags']),
      'show_thumbnail' => (int) $no_field_display_options_saved || !empty($paramsDecoded['field_options']['show_thumbnail']),
    ];

    $event_field_display_options = [
      'hide_add_to_calendar' => (int) !empty($paramsDecoded['event_field_options']['hide_add_to_calendar']),
    ];

    $post_field_display_options = [
      'show_eyebrow' => (int) !empty($paramsDecoded['post_field_options']['show_eyebrow']),
    ];

    $pin_label = $paramsDecoded['pin_label'] ?? self::DEFAULT_PIN_LABEL;

    if (!$pinned_to_top) {
      $pin_label = NULL;
    }

    $pin_options = [
      'pinned_to_top' => $pinned_to_top,
      'pin_label' => $pin_label,
    ];

    /*
     * End setting dynamic arguments.
     */

    /*
     * Includes current node, if specified in settings.
     */
    $includeCurrent = $paramsDecoded['show_current_entity'] ?? 0;
    if (!$includeCurrent) {
      $node = $this->routeMatch->getParameter('node');
      if ($node instanceof NodeInterface) {
        $currentNid = $node->id();
        /** @var Drupal\views\Plugin\views\query\Sql $query */
        $query = $view->getQuery();
        $baseTableAlias = $query->ensureTable('node_field_data');
        if ($baseTableAlias) {
          $query->addWhere(0, "$baseTableAlias.nid", $currentNid, '<>');
        }
      }
    }

    /*
     * End include current node.
     */

    $view_args = [
      'type' => $filterType,
      'terms_include' => $termsInclude,
      'terms_exclude' => $termsExclude,
      'sort' => $paramsDecoded['sort_by'],
      'view' => $paramsDecoded['view_mode'],
      'items' => $itemsLimit,
      'event_time_period' => str_contains($filterType, 'event') ? $eventTimePeriod : NULL,
      'offset' => $paramsDecoded['offset'] ?? 0,
      'field_display_options' => json_encode($field_display_options),
      'event_field_display_options' => json_encode($event_field_display_options),
      'post_field_display_options' => json_encode($post_field_display_options),
      'pin_settings' => json_encode($pin_options),
      'original_settings' => $params,
    ];

    $view->setArguments($view_args);
    $view->execute();

    // Unset the pager. Needs to be done after view->execute();
    if ($paramsDecoded['display'] != "pager") {
      unset($view->pager);
    }

    $view = $view->preview();
    // Add cache keys for each display option.
    // This ensures that if the options for showing categories, tags,
    // or thumbnails change, the cache will be invalidated,
    // and the view will be re-rendered with the new options.
    if ($view['#rows'] && $view['#rows']['#rows']) {
      foreach ($view['#rows']['#rows'] as &$resultRow) {
        $resultRow['#cache']['keys'][] = $field_display_options['show_categories'];
        $resultRow['#cache']['keys'][] = $field_display_options['show_tags'];
        $resultRow['#cache']['keys'][] = $field_display_options['show_thumbnail'];
        $resultRow['#cache']['keys'][] = $event_field_display_options['hide_add_to_calendar'];
        $resultRow['#cache']['keys'][] = $post_field_display_options['show_eyebrow'];
        $resultRow['#cache']['keys'][] = $pin_options['pinned_to_top'];
        $resultRow['#cache']['keys'][] = $pin_options['pin_label'];

        $resultRow['#cache']['contexts'][] = 'url.query_args:page';
      }
    }

    $setupRunning = FALSE;
  }

  /**
   * Retrieves an overridden Views Basic Scaffold view.
   *
   * Views Basic Scaffold view is overridden with the data from the parameters.
   *
   * @param string $type
   *   Type of view output: 'rendered' (used to allow 'count').
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
    $paramsDecoded = json_decode($params, TRUE);
    $view = $this->initView($paramsDecoded['filters']['types']);
    $this->setupView($view, $params);

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
      $entityTypes[$machine_name] = $type['label'] . "<img src='{$type['img']}' alt='{$type['img_alt']}'>";
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
    foreach (self::ALLOWED_ENTITIES[$content_type]['view_modes'] as $machine_name => $viewMode) {
      $viewModes[$machine_name] = $viewMode['label'] . "<img src='{$viewMode['img']}' alt='{$viewMode['img_alt']}'>";
    }

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
            $defaultParam[] = $this->getTermId($term);
          }
        }
        break;

      case 'view_mode':
        $defaultParam = (empty($paramsDecoded['view_mode'])) ? 'card' : $paramsDecoded['view_mode'];
        break;

      case 'operator':
        $defaultParam = (empty($paramsDecoded['operator'])) ? '+' : $paramsDecoded['operator'];
        break;

      case 'limit':
        $defaultParam = (empty($paramsDecoded['limit'])) ? 10 : (int) $paramsDecoded['limit'];
        break;

      case 'offset':
        $defaultParam = (empty($paramsDecoded['offset'])) ? 0 : (int) $paramsDecoded['offset'];
        break;

      case 'event_time_period':
        $defaultParam = (empty($paramsDecoded['filters']['event_time_period'])) ? 'future' : $paramsDecoded['filters']['event_time_period'];
        break;

      case 'field_options':
        $defaultParam = (empty($paramsDecoded['field_options'])) ? ['show_thumbnail' => 'show_thumbnail'] : $paramsDecoded['field_options'];
        break;

      case 'event_field_options':
        $defaultParam = (empty($paramsDecoded['event_field_options'])) ? [] : $paramsDecoded['event_field_options'];
        break;

      case 'post_field_options':
        $defaultParam = (empty($paramsDecoded['post_field_options'])) ? [] : $paramsDecoded['post_field_options'];
        break;

      case 'exposed_filter_options':
        $defaultParam = (empty($paramsDecoded['exposed_filter_options'])) ? [] : $paramsDecoded['exposed_filter_options'];
        break;

      case 'category_filter_label':
        $defaultParam = (empty($paramsDecoded['category_filter_label'])) ? NULL : $paramsDecoded['category_filter_label'];
        break;

      case 'category_included_terms':
        $defaultParam = (empty($paramsDecoded['category_included_terms'])) ? NULL : $paramsDecoded['category_included_terms'];
        break;

      case 'audience_included_terms':
        $defaultParam = (empty($paramsDecoded['audience_included_terms'])) ? NULL : $paramsDecoded['audience_included_terms'];
        break;

      case 'custom_vocab_included_terms':
        $defaultParam = (empty($paramsDecoded['custom_vocab_included_terms'])) ? NULL : $paramsDecoded['custom_vocab_included_terms'];
        break;

      case 'show_current_entity':
        $defaultParam = (empty($paramsDecoded['show_current_entity'])) ? 0 : $paramsDecoded['show_current_entity'];
      case 'pinned_to_top':
        $defaultParam = (empty($paramsDecoded['pinned_to_top'])) ? FALSE : (bool) $paramsDecoded['pinned_to_top'];
        break;

      case 'pin_label':
        $defaultParam = (empty($paramsDecoded['pin_label'])) ? self::DEFAULT_PIN_LABEL : $paramsDecoded['pin_label'];
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

  /**
   * Get all taxonomy terms associated with events.
   *
   * @return array
   *   An array of all taxonomy term IDs and labels.
   */
  public function getEventTags(): array {
    $vocabularies = ['event_category', 'audience', 'custom_vocab', 'tags'];
    $tagList = [];
    foreach ($vocabularies as $vid) {
      $terms = $this->termStorage->loadTree($vid);
      foreach ($terms as $term) {
        $tagList[$term->tid] = $term->name . ' (' . $vid . ')';
      }
    }
    asort($tagList);
    return $tagList;
  }

  /**
   * Get taxonomy parent terms by vocabulary ID.
   *
   * @param string $vid
   *   The machine name of the vocabulary.
   *
   * @return array
   *   An array of parent terms where the key is the term ID and
   *   the value is the term name.
   */
  public function getTaxonomyParents(string $vid): array {
    $list = ['' => '-- All Items --'];
    // Load all top-level (parent) terms for the given vocabulary ID.
    $terms = $this->termStorage->loadTree($vid, 0, 1);

    foreach ($terms as $term) {
      $list[$term->tid] = $term->name;
    }

    return $list;
  }

  /**
   * Get child taxonomy terms by parent ID.
   *
   * @param int $parentId
   *   The ID of the parent term.
   * @param string $vid
   *   The machine name of the vocabulary.
   *
   * @return array
   *   An associative array of child terms where the key is the term ID and
   *   the value is the term ID.
   */
  public function getChildTermsByParentId(int $parentId, string $vid): array {
    $list = [];
    // Load all child terms for the given parent term ID and vocabulary ID.
    $terms = $this->termStorage->loadTree($vid, $parentId, NULL);

    foreach ($terms as $term) {
      $list[$term->tid] = (int) $term->tid;
    }

    return $list;
  }

  /**
   * Returns an integer representation of the term.
   *
   * The term could be either the old Drupal way of an array with a
   * target_id attribute containing a string representation of the id, or the
   * chosen way of a string representation of the id. This ensures that the
   * decision of what should be return is handled here and not elsewhere.
   *
   * @param mixed $term
   *   The taxonomy term.
   *
   * @return int
   *   The term ID.
   */
  private function getTermId($term) : int {
    return (int) is_array($term) ? $term['target_id'] : $term;
  }

  /**
   * Gets views widget form selectors based on which form is being loaded.
   *
   * Checks if the form is loaded via layout builder and, if so, is the
   * block reusable. This aids in setting the correct arrays and Ajax calls
   * below as the selectors are different depending on what form is being
   * loaded.
   *
   * @see https://www.drupal.org/project/drupal/issues/2758631
   */
  public function getFormSelectors($formState, $form = NULL, $entityValue = NULL) {
    $formSelectors = [];

    $rebuildValues = ($formState->isRebuilding()) ? $formState->getValues() : NULL;

    if ($formState->getCompleteForm() && str_starts_with($formState->getCompleteForm()['#form_id'], 'layout_builder_')) {
      if (isset($formState->getCompleteForm()['block_form']['#block']) && $formState->getCompleteForm()['block_form']['#block']->isReusable()) {
        // Reusable block Layout Builder form.
        $formSelectors = [
          'entity_types' => $rebuildValues['block_form']['group_user_selection']['entity_and_view_mode']['entity_types'] ?? $entityValue,
          'entity_types_ajax' => ':input[name="block_form[group_user_selection][entity_and_view_mode][entity_types]"]',
          'view_mode_input_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][view_mode]"]',
          'view_mode_ajax' => ($form) ? $form['block_form']['group_user_selection']['entity_and_view_mode']['view_mode'] : NULL,
          'category_included_terms_ajax' => ($form) ? $form['block_form']['group_user_selection']['entity_and_view_mode']['category_included_terms'] : NULL,
          'show_category_filter_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][exposed_filter_options][show_category_filter]"]',
          'show_audience_filter_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][exposed_filter_options][show_audience_filter]"]',
          'show_custom_vocab_filter_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][exposed_filter_options][show_custom_vocab_filter]"]',
          'custom_vocab_included_terms_ajax' => ($form) ? $form['block_form']['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms'] : NULL,
          'massage_terms_include_array' => [
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'terms_include',
          ],
          'massage_terms_exclude_array' => [
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'terms_exclude',
          ],
          'sort_by_array' => [
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'sort_by',
          ],
          'sort_by_ajax' => ($form) ? $form['block_form']['group_user_selection']['filter_and_sort']['sort_by'] : NULL,
          'display_array' => [
            'block_form',
            'group_user_selection',
            'options',
            'display',
          ],
          'display_ajax' => ':input[name="block_form[group_user_selection][options][display]"]',
          'display_value_ajax' => $formState->getValue(
          ['block_form', 'group_user_selection', 'options', 'display']
          ),
          'limit_array' => [
            'block_form',
            'group_user_selection',
            'options',
            'limit',
          ],
          'limit_ajax' => ($form) ? $form['block_form']['group_user_selection']['options']['limit'] : NULL,
          'offset_array' => [
            'block_form',
            'group_user_selection',
            'options',
            'offset',
          ],
          'pinned_to_top' => ($form) ? $form['block_form']['group_user_selection']['filter_and_sort']['pinned_to_top'] : NULL,
          'pinned_to_top_array' => [
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'pinned_to_top',
          ],
          'pinned_to_top_selector' => ':input[name="block_form[group_user_selection][filter_and_sort][pinned_to_top]"]',
          'pin_label' => ($form) ? $form['block_form']['group_user_selection']['filter_and_sort']['pin_label'] : self::DEFAULT_PIN_LABEL,
          'pin_label_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'pin_label',
          ],
        ];
      }
      else {
        // Regular block Layout Builder form.
        $formSelectors = [
          'entity_types' => $rebuildValues['settings']['block_form']['group_user_selection']['entity_and_view_mode']['entity_types'] ?? $entityValue,
          'entity_types_ajax' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][entity_types]"]',
          'view_mode_input_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][view_mode]"]',
          'view_mode_ajax' => ($form) ? $form['settings']['block_form']['group_user_selection']['entity_and_view_mode']['view_mode'] : NULL,
          'category_included_terms_ajax' => ($form) ? $form['settings']['block_form']['group_user_selection']['entity_and_view_mode']['category_included_terms'] : NULL,
          'show_category_filter_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][exposed_filter_options][show_category_filter]"]',
          'show_audience_filter_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][exposed_filter_options][show_audience_filter]"]',
          'show_custom_vocab_filter_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][exposed_filter_options][show_custom_vocab_filter]"]',
          'custom_vocab_included_terms_ajax' => ($form) ? $form['settings']['block_form']['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms'] : NULL,
          'massage_terms_include_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'terms_include',
          ],
          'massage_terms_exclude_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'terms_exclude',
          ],
          'sort_by_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'sort_by',
          ],
          'sort_by_ajax' => ($form) ? $form['settings']['block_form']['group_user_selection']['filter_and_sort']['sort_by'] : NULL,
          'display_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'options',
            'display',
          ],
          'display_ajax' => ':input[name="settings[block_form][group_user_selection][options][display]"]',
          'display_value_ajax' => $formState->getValue(
          [
            'settings',
            'block_form',
            'group_user_selection',
            'options',
            'display',
          ]
          ),
          'limit_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'options',
            'limit',
          ],
          'limit_ajax' => ($form) ? $form['settings']['block_form']['group_user_selection']['options']['limit'] : NULL,
          'offset_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'options',
            'offset',
          ],
          'pinned_to_top_ajax' => ($form) ? $form['settings']['block_form']['filter_and_sort']['pinned_to_top'] : NULL,
          'pinned_to_top_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'pinned_to_top',
          ],
          'pinned_to_top_selector' => ':input[name="settings[block_form][group_user_selection][filter_and_sort][pinned_to_top]"]',
          'pin_label_ajax' => ($form) ? $form['settings']['block_form']['filter_and_sort']['pin_label'] : self::DEFAULT_PIN_LABEL,
          'pin_label_array' => [
            'settings',
            'block_form',
            'group_user_selection',
            'filter_and_sort',
            'pin_label',
          ],
        ];
      }
    }
    else {
      // Drupal core block form.
      $formSelectors = [
        'entity_types' => $rebuildValues['entity_types'] ?? $entityValue,
        'entity_types_ajax' => ':input[name="entity_types"]',
        'view_mode_input_selector' => ':input[name="view_mode"]',
        'view_mode_ajax' => ($form) ? $form['group_user_selection']['entity_and_view_mode']['view_mode'] : NULL,
        'category_included_terms_ajax' => ($form) ? $form['group_user_selection']['entity_and_view_mode']['category_included_terms'] : NULL,
        'show_category_filter_selector' => ':input[name="show_category_filter"]',
        'show_audience_filter_selector' => ':input[name="show_audience_filter"]',
        'show_custom_vocab_filter_selector' => ':input[name="show_custom_vocab_filter"]',
        'custom_vocab_included_terms_ajax' => ($form) ? $form['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms'] : NULL,
        'massage_terms_include_array' => ['terms_include'],
        'massage_terms_exclude_array' => ['terms_exclude'],
        'sort_by_array' => ['sort_by'],
        'sort_by_ajax' => ($form) ? $form['group_user_selection']['filter_and_sort']['sort_by'] : NULL,
        'display_array' => ['display'],
        'display_ajax' => ':input[name="display"]',
        'display_value_ajax' => $formState->getValue(
        ['group_user_selection', 'options', 'display']
        ),
        'limit_array' => ['limit'],
        'limit_ajax' => ($form) ? $form['group_user_selection']['options']['limit'] : NULL,
        'offset_array' => ['offset'],
        'pinned_to_top' => ['pinned_to_top'],
        'pinned_to_top_selector' => ':input[name="settings[block_form][group_user_selection][filter_and_sort][pinned_to_top]"]',
        'pinned_to_top_array' => ['pinned_to_top'],
        'pinned_to_top_ajax' => ($form) ? $form['group_user_selection']['filter_and_sort']['pinned_to_top'] : NULL,
        'pin_label_array' => ['pin_label'],
        'pin_label_ajax' => ($form) ? $form['settings']['block_form']['filter_and_sort']['pin_label'] : self::DEFAULT_PIN_LABEL,
      ];
    }

    return $formSelectors;
  }

}
