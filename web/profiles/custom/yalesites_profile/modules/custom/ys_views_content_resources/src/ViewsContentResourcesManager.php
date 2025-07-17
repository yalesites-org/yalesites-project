<?php

namespace Drupal\ys_views_content_resources;

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
class ViewsContentResourcesManager extends ControllerBase implements ContainerInjectionInterface {

  /**
   * Allowed view modes for users to select.
   *
   * Format:
   * 'view_modes' => [
   *   'view_mode_machine_name1' => 'Human readable label 1',
   *   'view_mode_machine_name2' => 'Human readable label 2',
   * ],
   *
   * @var array
   */

  const ALLOWED_VIEW_MODES = [
    'card' => [
      'label' => 'Card Grid',
      'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-card-grid.svg',
      'img_alt' => 'Icon showing 3 generic cards next to each other. Image placement is on the top of each card.',
    ],
    'list_item' => [
      'label' => 'List',
      'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-list-view.svg',
      'img_alt' => 'Icon showing 3 generic list items one on top of the other. Image placement is on the left of each list item.',
    ],
    'condensed' => [
      'label' => 'Condensed',
      'img' => '/profiles/custom/yalesites_profile/modules/custom/ys_views_basic/assets/icons/display-type-condensed.svg',
      'img_alt' => 'Icon showing 3 generic list items one on top of the other with no images on the items.',
    ],
  ];

  /**
   * Default pin label.
   */
  const DEFAULT_PIN_LABEL = 'Pinned';

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
   * @return \Drupal\views\ViewExecutable
   *   The view object.
   */
  public function initView() {
    return Views::getView('content_resources');
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

    $view->setDisplay('block_1');
    $filterType = implode('+', $paramsDecoded['filters']['types']);

    // Retrieve the current filter options from the view's display settings.
    $filters = $view->getDisplay()->getOption('filters');

    // Mapping content types to their respective category filters.
    $category_filters = [
      'resource' => 'field_category_target_id',
    ];

    $category_filter_name = $category_filters[$filterType] ?? NULL;

    // Show the exposed filter 'Category' or 'Affiliation'.
    if (!empty($paramsDecoded['exposed_filter_options']['show_category_filter']) && $category_filter_name) {
      $filters_to_unset = match ($filterType) {
        'resource' => [
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
        $vid = "resource_category";

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

    if (!isset($paramsDecoded['exposed_filter_options']['show_year_filter'])) {
      // Remove the 'Year' filter if the 'show_year_filter' is not set.
      unset($filters['resource_year_filter']);
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
      'type' => 'resource',
      'terms_include' => $termsInclude,
      'terms_exclude' => $termsExclude,
      'sort' => $paramsDecoded['sort_by'],
      'view' => $paramsDecoded['view_mode'],
      'items' => $itemsLimit,
      'offset' => $paramsDecoded['offset'] ?? 0,
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

    // Set up the view.
    $view = $this->initView();
    $this->setupView($view, $params);

    // End current view run.
    $running = FALSE;

    return $view;
  }

  /**
   * Returns an array of view mode machine names and the human readable name.
   *
   * @return array
   *   An array of human readable view modes, with machine name as the key.
   */
  public function viewModeList() {
    foreach (self::ALLOWED_VIEW_MODES as $machine_name => $viewMode) {
      $viewModes[$machine_name] = $viewMode['label'] . "<img src='{$viewMode['img']}' alt='{$viewMode['img_alt']}'>";
    }

    return $viewModes;
  }

  /**
   * Returns an array of sort by machine names and the human readable name.
   *
   * @return array
   *   An array of human readable sorts, with machine name as the key.
   */
  public function sortByList() {
    return [
      'field_publish_date:DESC' => 'Published Date - newer first',
      'field_publish_date:ASC' => 'Published Date - older first',
    ];
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
    return 'Resources';
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

      case 'exposed_filter_options':
        $defaultParam = (empty($paramsDecoded['exposed_filter_options'])) ? [] : $paramsDecoded['exposed_filter_options'];
        break;

      case 'category_filter_label':
        $defaultParam = (empty($paramsDecoded['category_filter_label'])) ? NULL : $paramsDecoded['category_filter_label'];
        break;

      case 'category_included_terms':
        $defaultParam = (empty($paramsDecoded['category_included_terms'])) ? NULL : $paramsDecoded['category_included_terms'];
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

    if ($formState->getCompleteForm() && str_starts_with($formState->getCompleteForm()['#form_id'], 'layout_builder_')) {
      if (isset($formState->getCompleteForm()['block_form']['#block']) && $formState->getCompleteForm()['block_form']['#block']->isReusable()) {
        // Reusable block Layout Builder form.
        $formSelectors = [
          'view_mode_input_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][view_mode]"]',
          'view_mode_ajax' => ($form) ? $form['block_form']['group_user_selection']['entity_and_view_mode']['view_mode'] : NULL,
          'category_included_terms_ajax' => ($form) ? $form['block_form']['group_user_selection']['entity_and_view_mode']['category_included_terms'] : NULL,
          'show_category_filter_selector' => ':input[name="block_form[group_user_selection][entity_and_view_mode][exposed_filter_options][show_category_filter]"]',
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
          'view_mode_input_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][view_mode]"]',
          'view_mode_ajax' => ($form) ? $form['settings']['block_form']['group_user_selection']['entity_and_view_mode']['view_mode'] : NULL,
          'category_included_terms_ajax' => ($form) ? $form['settings']['block_form']['group_user_selection']['entity_and_view_mode']['category_included_terms'] : NULL,
          'show_category_filter_selector' => ':input[name="settings[block_form][group_user_selection][entity_and_view_mode][exposed_filter_options][show_category_filter]"]',
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
        'view_mode_input_selector' => ':input[name="view_mode"]',
        'view_mode_ajax' => ($form) ? $form['group_user_selection']['entity_and_view_mode']['view_mode'] : NULL,
        'category_included_terms_ajax' => ($form) ? $form['group_user_selection']['entity_and_view_mode']['category_included_terms'] : NULL,
        'show_category_filter_selector' => ':input[name="show_category_filter"]',
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
