<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ys_views_basic\ViewsBasicManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for Views Basic field widgets ("Views Core" layer).
 *
 * This abstract class is layer 1 of the layered Views Block architecture
 * described in the ADR (YaleSites-Internal #1162, decisions DR-1/DR-2/DR-4).
 * It holds the dependency wiring and form helpers shared by every listing
 * widget, the bundle-keyed definition that maps each block content bundle to
 * its (content type, display mode) pair, and the abstract contract that the
 * per-content-type widgets implement.
 *
 * Display mode is NOT a form control. It is encoded in the block content
 * bundle id (e.g. "post_card", "profile_directory") and resolved from the host
 * entity via ::LISTING_BUNDLES. Per-mode availability (such as whether the
 * "Show Teaser Image" option applies) is a capability flag in that same
 * definition rather than a scattered conditional.
 *
 * Two legacy widgets also extend this base for backward compatibility until
 * the migration (#1169) and deprecation (#1170) land:
 * - ViewsBasicDefaultWidget: the monolithic all-content-types "view" widget,
 *   which keeps its own formElement()/massageFormValues() unchanged.
 * - EventCalendarDefaultWidget: the calendar widget, which stores a divergent
 *   JSON shape, so this base must not assume a uniform stored-JSON schema.
 */
abstract class ViewsBasicWidgetBase extends WidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The views basic manager service.
   *
   * @var \Drupal\ys_views_basic\ViewsBasicManager
   */
  protected $viewsBasicManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Memoized label of the custom_vocab vocabulary.
   *
   * @var string|null
   */
  protected ?string $customVocabularyLabel = NULL;

  /**
   * Constructs a ViewsBasicWidgetBase object.
   *
   * @param string $plugin_id
   *   The plugin_id for the widget.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the widget is associated.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\ys_views_basic\ViewsBasicManager $views_basic_manager
   *   The ViewsBasic management service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    ViewsBasicManager $views_basic_manager,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct(
      $plugin_id,
      $plugin_definition,
      $field_definition,
      $settings,
      $third_party_settings
    );
    $this->viewsBasicManager = $views_basic_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('ys_views_basic.views_basic_manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * The machine name of the content type this widget builds listings for.
   *
   * Per-content-type widgets return a single content type (e.g. "post").
   * The legacy ViewsBasicDefaultWidget returns NULL because it supports every
   * content type and resolves the selection at runtime.
   *
   * @return string|null
   *   The content type machine name, or NULL for the legacy multi-type widget.
   */
  abstract protected function getContentType(): ?string;

  /**
   * Builds the content-type-specific form controls.
   *
   * Per-content-type widgets add only the controls relevant to their type
   * (e.g. the post eyebrow option, the event time period) with no #states
   * gating, because the widget is single-type by construction. The legacy
   * widget builds these inline in its own formElement() and so implements this
   * as a no-op.
   *
   * @param array $form
   *   The form array, passed by reference.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The field delta.
   */
  abstract protected function buildEntitySpecificOptions(array &$form, FieldItemListInterface $items, int $delta): void;

  /**
   * Returns the taxonomy vocabulary used for this widget's category filter.
   *
   * Defaults to "{content_type}_category", with profiles using "affiliation".
   * Override only when a content type needs a different vocabulary.
   *
   * @return string
   *   The vocabulary machine name, or an empty string when not applicable.
   */
  protected function getCategoryVocabulary(): string {
    $content_type = $this->getContentType();
    if ($content_type === NULL) {
      return '';
    }
    return $content_type === ViewsBasicManager::CONTENT_TYPE_PROFILE
      ? 'affiliation'
      : $content_type . '_category';
  }

  /**
   * Returns the label for the category field-display option.
   *
   * Defaults to "Show Categories". ProfileViewWidget overrides this with
   * "Show Affiliations" so the override lives in exactly one class rather than
   * a runtime conditional (ADR Fear 2).
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The category display label.
   */
  protected function buildCategoryLabel() {
    return $this->t('Show Categories');
  }

  /**
   * Returns the listing block content bundle this widget instance serves.
   *
   * The widget is attached to exactly one bundle's form display, so the bundle
   * (which encodes the (content type, display mode) pair) is read from the
   * field definition rather than the host entity — this works identically in
   * formElement() and massageFormValues().
   *
   * @return string
   *   The block content bundle id (e.g. "post_card").
   */
  protected function getBundle(): string {
    return $this->fieldDefinition->getTargetBundle();
  }

  /**
   * Returns the node view mode this widget instance renders results in.
   *
   * @return string
   *   The view mode machine name, resolved from the bundle (ADR DR-2).
   */
  protected function getViewMode(): string {
    return ViewsBasicManager::getViewModeForBundle($this->getBundle());
  }

  /**
   * Get a valid value for the view mode.
   *
   * Ensures the view mode is valid for the content type: the "calendar" view
   * mode only applies to events, so any other content type falls back to
   * "card".
   *
   * @param string $value
   *   The view mode value.
   * @param string $contentType
   *   The content type.
   *
   * @return string
   *   The view mode value.
   */
  protected function viewModeValue($value, $contentType) {
    if ($contentType != 'event' && $value == 'calendar') {
      return 'card';
    }

    return $value;
  }

  /**
   * Ajax callback to return only view modes for the specified content type.
   */
  public function updateOtherSettings(array &$form, FormStateInterface $form_state): AjaxResponse {
    $formSelectors = $this->viewsBasicManager->getFormSelectors($form_state, $form);

    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#edit-view-mode', $formSelectors['view_mode_ajax']));
    $response->addCommand(new ReplaceCommand('#edit-sort-by', $formSelectors['sort_by_ajax']));
    $firstViewModeItem = $formSelectors['view_mode_input_selector'] . ':first';
    $response->addCommand(new InvokeCommand($firstViewModeItem, 'prop', [['checked' => TRUE]]));

    return $response;
  }

  /**
   * {@inheritdoc}
   *
   * Builds the per-content-type listing form. The content type and display
   * mode are fixed by the bundle (ADR DR-2), so there is no entity-type or
   * view-mode selector and no cross-type #states: each per-type widget builds
   * only its own controls. Per-mode availability (the teaser-image option) is
   * driven by the capability flag in the bundle definition, not a conditional.
   */
  public function formElement(
    FieldItemListInterface $items,
    $delta,
    array $element,
    array &$form,
    FormStateInterface $formState,
  ) {
    $formSelectors = $this->viewsBasicManager->getFormSelectors($formState, NULL, $this->getContentType());
    $form['#form_selectors'] = $formSelectors;

    $element['group_params'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['views-basic--params']],
    ];

    // The live mockup preview is scoped to the block-creation form only
    // (#1318): it helps a site builder picture the result while placing a new
    // block and is not shown when reconfiguring an existing one.
    $is_create_form = $this->isCreateForm($items);

    $this->initSelectionContainers($form);
    $this->buildFieldDisplayOptions($form, $items, $delta);
    $this->buildEntitySpecificOptions($form, $items, $delta);
    if ($is_create_form) {
      $this->buildPreviewPanel($form);
    }
    $this->buildExposedFilterControls($form, $items, $delta, $formSelectors);
    $this->buildTermIncludeExclude($form, $items, $delta);
    $this->buildSortControl($form, $items, $delta);
    $this->buildPinnedControls($form, $items, $delta, $formSelectors);
    $this->buildDisplayControls($form, $items, $delta);
    $this->buildHiddenParamsField($element, $items, $delta);

    $form['#attached']['library'][] = 'ys_views_basic/ys_views_basic';
    if ($is_create_form) {
      $form['#attached']['library'][] = 'ys_views_basic/ys_views_basic_preview';
    }

    return $element;
  }

  /**
   * Determines whether the form is creating a new block (vs. configuring one).
   *
   * The live mockup preview (#1318) is scoped to the creation form. A new
   * inline block's host entity is unsaved, so isNew() distinguishes the
   * "Add block" form from the "Configure" form of an existing block.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items whose host entity is the block being placed.
   *
   * @return bool
   *   TRUE on the block-creation form, FALSE when reconfiguring.
   */
  protected function isCreateForm(FieldItemListInterface $items): bool {
    $block = $items->getEntity();
    return $block && $block->isNew();
  }

  /**
   * Builds the live, client-side mockup preview panel (#1318).
   *
   * Renders a compact placeholder card below the field-display options (markup
   * in the `views_basic_mockup_preview` theme hook,
   * templates/views-basic-mockup-preview.html.twig) whose parts the preview
   * behavior (views-basic-preview.js) shows/hides as the site builder toggles
   * the Field Display and pinned settings. The content is entirely static
   * placeholder text — no query is run — and each toggleable part is rendered
   * visible so the panel degrades gracefully without JS.
   *
   * The compact inline panel suits both the larger creation dialog and the
   * sidebar edit context; a split-pane variant for the creation dialog is a
   * design follow-up (#1318 acceptance criteria).
   *
   * @param array $form
   *   The form array, passed by reference.
   */
  protected function buildPreviewPanel(array &$form): void {
    $form['group_user_selection']['entity_and_view_mode']['preview'] = [
      '#theme' => 'views_basic_mockup_preview',
      '#weight' => 100,
      '#content_type' => $this->getContentType(),
      '#view_mode' => $this->getViewMode(),
      // 1x1 transparent GIF; the CSS shows the placeholder as a grey box.
      '#image_src' => 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Writes the user selection into the stored params JSON. The view mode and
   * content type are injected from the bundle (not the form), and per-type
   * extras are added by massageEntitySpecificParams().
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $formSelectors = $this->viewsBasicManager->getFormSelectors($form_state);
    $selection = $form['group_user_selection'];

    $terms_include = $form_state->getValue($formSelectors['massage_terms_include_array']) ?? NULL;
    $terms_exclude = $form_state->getValue($formSelectors['massage_terms_exclude_array']) ?? NULL;

    foreach ($values as &$value) {
      $paramData = [
        'view_mode' => $this->getViewMode(),
        'filters' => [
          'types' => [$this->getContentType()],
          'terms_include' => $terms_include,
          'terms_exclude' => $terms_exclude,
        ],
        'field_options' => $selection['entity_and_view_mode']['field_options']['#value'],
        'event_field_options' => [],
        'post_field_options' => [],
        'exposed_filter_options' => $selection['entity_and_view_mode']['exposed_filter_options']['#value'],
        'category_filter_label' => $selection['entity_and_view_mode']['category_filter_label']['#value'],
        'category_included_terms' => $selection['entity_and_view_mode']['category_included_terms']['#value'],
        'custom_vocab_included_terms' => $selection['entity_and_view_mode']['custom_vocab_included_terms']['#value'],
        'operator' => $selection['filter_and_sort']['term_operator']['#value'],
        'sort_by' => $form_state->getValue($formSelectors['sort_by_array']),
        'display' => $form_state->getValue($formSelectors['display_array']),
        'limit' => (int) $form_state->getValue($formSelectors['limit_array']),
        'offset' => (int) $form_state->getValue($formSelectors['offset_array']),
        'show_current_entity' => $selection['options']['show_current_entity']['#value'],
        'pinned_to_top' => $form_state->getValue($formSelectors['pinned_to_top_array']),
        'pin_label' => $form_state->getValue($formSelectors['pin_label_array']),
      ];
      $this->massageEntitySpecificParams($paramData, $form, $form_state);
      $value['params'] = json_encode($paramData);
    }
    return $values;
  }

  /**
   * Adds content-type-specific keys to the stored params.
   *
   * Override in per-type widgets that store extra params (e.g. PostViewWidget
   * adds post_field_options; EventViewWidget adds event_field_options and the
   * event time period). The default adds nothing.
   *
   * @param array $paramData
   *   The params array being assembled, passed by reference.
   * @param array $form
   *   The submitted form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  protected function massageEntitySpecificParams(array &$paramData, array $form, FormStateInterface $form_state): void {
  }

  /**
   * Returns the exposed filter checkbox options for this content type.
   *
   * The base set applies to every listing. PostViewWidget overrides this to
   * add the post-only "Show Year" filter.
   *
   * @return array
   *   An options map keyed by exposed-filter machine name.
   */
  protected function getExposedFilterOptions(): array {
    return [
      'show_search_filter' => $this->t('Show Search (results based on the content title only)'),
      'show_category_filter' => $this->t('Show Category'),
      'show_custom_vocab_filter' => $this->t('Show @vocab', ['@vocab' => $this->customVocabularyLabel()]),
      'show_audience_filter' => $this->t('Show Audience'),
    ];
  }

  /**
   * Builds the user-selection container and its grouping sub-containers.
   *
   * @param array $form
   *   The form array, passed by reference.
   */
  protected function initSelectionContainers(array &$form): void {
    $form['group_user_selection'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['views-basic--group-user-selection'],
        'data-drupal-ck-style-fence' => '',
      ],
      '#weight' => 10,
    ];

    // Sectioned, collapsible groups give the form a scannable structure
    // (#1316/#1317). The container keys are unchanged so the form-selector
    // paths and stored-JSON reads stay valid; only the rendered presentation
    // changes from a flat column to titled groups. entity_specific stays a
    // plain container so it is invisible when a content type (post, page,
    // profile) adds nothing to it.
    $details = [
      'entity_and_view_mode' => [
        'title' => $this->t('Field display & filters'),
        'open' => TRUE,
        'classes' => ['views-basic--entity-view-mode'],
      ],
      'filter_and_sort' => [
        'title' => $this->t('Tags, sorting & pinned'),
        'open' => TRUE,
        'classes' => [],
      ],
      'options' => [
        'title' => $this->t('Display options'),
        'open' => FALSE,
        'classes' => [],
      ],
    ];
    foreach ($details as $name => $group) {
      $form['group_user_selection'][$name] = [
        '#type' => 'details',
        '#title' => $group['title'],
        '#open' => $group['open'],
        '#attributes' => ['class' => array_merge(['grouped-items'], $group['classes'])],
      ];
    }

    // entity_specific holds optional per-type controls (e.g. the event time
    // period); keep it a plain container so it does not render an empty box.
    $form['group_user_selection']['entity_specific'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['grouped-items']],
    ];
  }

  /**
   * Builds the field-display options (categories, tags, teaser image).
   *
   * The teaser-image checkbox is offered only when the bundle's capability
   * flag allows it (card and list_item), read from the definition instead of a
   * #states condition on the view mode.
   *
   * @param array $form
   *   The form array, passed by reference.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The field delta.
   */
  protected function buildFieldDisplayOptions(array &$form, FieldItemListInterface $items, int $delta): void {
    $options = [
      'show_categories' => $this->buildCategoryLabel(),
      'show_tags' => $this->t('Show Tags'),
    ];
    if (ViewsBasicManager::bundleSupportsThumbnail($this->getBundle())) {
      $options['show_thumbnail'] = $this->t('Show Teaser Image');
    }
    // Title for the field-display group of options (#1317).
    $field_options_title = $this->t('Show on each result');

    // Default to the saved field options, falling back to showing the teaser
    // image on a brand-new block where that option is offered.
    $saved = $items[$delta]->params
      ? $this->viewsBasicManager->getDefaultParamValue('field_options', $items[$delta]->params)
      : [];
    if (empty($saved) && isset($options['show_thumbnail'])) {
      $saved = ['show_thumbnail'];
    }

    $form['group_user_selection']['entity_and_view_mode']['field_options'] = [
      '#type' => 'checkboxes',
      '#options' => $options,
      '#title' => $field_options_title,
      '#tree' => TRUE,
      '#default_value' => is_array($saved) ? $saved : [],
    ];
  }

  /**
   * Builds the exposed filter checkboxes and their term-filter selects.
   *
   * @param array $form
   *   The form array, passed by reference.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The field delta.
   * @param array $formSelectors
   *   The resolved form selectors for #states.
   */
  protected function buildExposedFilterControls(array &$form, FieldItemListInterface $items, int $delta, array $formSelectors): void {
    $params = $items[$delta]->params;
    $custom_vocab_label = $this->customVocabularyLabel();

    $form['group_user_selection']['entity_and_view_mode']['exposed_filter_options'] = [
      '#type' => 'checkboxes',
      '#options' => $this->getExposedFilterOptions(),
      '#title' => $this->t('Exposed Filter Options'),
      '#tree' => TRUE,
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('exposed_filter_options', $params) : [],
    ];

    $form['group_user_selection']['entity_and_view_mode']['category_filter_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Category Filter Label'),
      '#description' => $this->t("Enter a custom label for the <strong>Category Filter</strong>. This label will be displayed to users as the filter's name. If left blank, the default label <strong>Category</strong> will be used."),
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('category_filter_label', $params) : NULL,
      '#states' => ['visible' => [$formSelectors['show_category_filter_selector'] => ['checked' => TRUE]]],
    ];

    $form['group_user_selection']['entity_and_view_mode']['category_included_terms'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter by Category Parent Term'),
      '#description' => $this->t("Select a parent term to show content tagged with that term's sub-items. This ignores content tagged as the parent term and any other parent terms in the vocabulary."),
      '#options' => $this->viewsBasicManager->getTaxonomyParents($this->getCategoryVocabulary()),
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('category_included_terms', $params) : NULL,
      '#validated' => 'true',
      '#prefix' => '<div id="edit-category-included-terms">',
      '#suffix' => '</div>',
      '#states' => ['visible' => [$formSelectors['show_category_filter_selector'] => ['checked' => TRUE]]],
    ];

    $form['group_user_selection']['entity_and_view_mode']['custom_vocab_included_terms'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter by @vocab Parent Term', ['@vocab' => $custom_vocab_label]),
      '#description' => $this->t("Select a parent term to show content tagged with that term's sub-items. This ignores content tagged as the parent term and any other parent terms in the vocabulary."),
      '#options' => $this->viewsBasicManager->getTaxonomyParents('custom_vocab'),
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('custom_vocab_included_terms', $params) : NULL,
      '#validated' => 'true',
      '#prefix' => '<div id="edit-custom-vocab-included-terms">',
      '#suffix' => '</div>',
      '#states' => ['visible' => [$formSelectors['show_custom_vocab_filter_selector'] => ['checked' => TRUE]]],
    ];
  }

  /**
   * Builds the include/exclude term selects and the term operator.
   *
   * @param array $form
   *   The form array, passed by reference.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The field delta.
   */
  protected function buildTermIncludeExclude(array &$form, FieldItemListInterface $items, int $delta): void {
    $params = $items[$delta]->params;
    $tags = $this->viewsBasicManager->getAllTags();

    $form['group_user_selection']['filter_and_sort']['terms_include'] = [
      '#title' => $this->t('Only show content tagged'),
      '#description' => $this->t('Limit results to content using any of these tags or categories.'),
      '#type' => 'select',
      '#options' => $tags,
      '#chosen' => TRUE,
      '#multiple' => TRUE,
      '#tags' => TRUE,
      '#target_type' => 'taxonomy_term',
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('terms_include', $params) : [],
    ];
    $form['group_user_selection']['filter_and_sort']['terms_exclude'] = [
      '#title' => $this->t('Hide content tagged'),
      '#description' => $this->t('Remove results using any of these tags or categories.'),
      '#type' => 'select',
      '#options' => $tags,
      '#chosen' => TRUE,
      '#multiple' => TRUE,
      '#tags' => TRUE,
      '#target_type' => 'taxonomy_term',
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('terms_exclude', $params) : [],
    ];
    $form['group_user_selection']['filter_and_sort']['term_operator'] = [
      '#type' => 'radios',
      '#title' => $this->t('Match content tagged with'),
      '#description' => $this->t('"Any" shows content with at least one selected term; "All" requires every selected term.'),
      // "+" is OR and "," is AND.
      '#options' => [
        '+' => $this->t('Any of the selected terms'),
        ',' => $this->t('All of the selected terms'),
      ],
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('operator', $params) : '+',
      '#attributes' => ['class' => ['term-operator-item']],
    ];
  }

  /**
   * Builds the sort-by select for this content type.
   *
   * @param array $form
   *   The form array, passed by reference.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The field delta.
   */
  protected function buildSortControl(array &$form, FieldItemListInterface $items, int $delta): void {
    $params = $items[$delta]->params;
    $form['group_user_selection']['filter_and_sort']['sort_by'] = [
      '#type' => 'select',
      '#options' => $this->viewsBasicManager->sortByList($this->getContentType()),
      '#title' => $this->t('Sorting by'),
      '#tree' => TRUE,
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('sort_by', $params) : NULL,
      '#validated' => 'true',
      '#prefix' => '<div id="edit-sort-by">',
      '#suffix' => '</div>',
    ];
  }

  /**
   * Builds the pinned-to-top checkbox and its conditional label field.
   *
   * @param array $form
   *   The form array, passed by reference.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The field delta.
   * @param array $formSelectors
   *   The resolved form selectors for #states.
   */
  protected function buildPinnedControls(array &$form, FieldItemListInterface $items, int $delta, array $formSelectors): void {
    $params = $items[$delta]->params;
    $form['group_user_selection']['filter_and_sort']['pinned_to_top'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Highlight pinned items'),
      '#description' => $this->t('Show a small label on items an editor has pinned to the top of this list.'),
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('pinned_to_top', $params) : FALSE,
    ];
    $pin_label = ($params ? $this->viewsBasicManager->getDefaultParamValue('pin_label', $params) : ViewsBasicManager::DEFAULT_PIN_LABEL) ?? ViewsBasicManager::DEFAULT_PIN_LABEL;
    $form['group_user_selection']['filter_and_sort']['pin_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pinned-item label'),
      '#description' => $this->t('Text shown on each pinned item (for example "Featured").'),
      '#default_value' => $pin_label,
      '#states' => [
        'visible' => [$formSelectors['pinned_to_top_selector'] => ['checked' => TRUE]],
        'required' => [$formSelectors['pinned_to_top_selector'] => ['checked' => TRUE]],
      ],
    ];
  }

  /**
   * Builds the display/limit/offset and "include current entity" controls.
   *
   * @param array $form
   *   The form array, passed by reference.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The field delta.
   */
  protected function buildDisplayControls(array &$form, FieldItemListInterface $items, int $delta): void {
    $params = $items[$delta]->params;
    $displayValue = $params ? $this->viewsBasicManager->getDefaultParamValue('display', $params) : 'all';

    $form['group_user_selection']['options']['display'] = [
      '#type' => 'select',
      '#title' => $this->t('How many items to show'),
      '#description' => $this->t('Choose all matching items, a fixed number, or a paginated list.'),
      '#default_value' => $displayValue,
      '#options' => [
        'all' => $this->t('Display all items'),
        'limit' => $this->t('Limit to'),
        'pager' => $this->t('Pagination after'),
      ],
    ];
    $form['group_user_selection']['options']['limit'] = [
      '#title' => $displayValue === 'pager' ? $this->t('Items per Page') : $this->t('Items'),
      '#type' => 'number',
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('limit', $params) : 10,
      '#min' => 1,
      '#required' => TRUE,
      '#prefix' => '<div id="edit-limit">',
      '#suffix' => '</div>',
    ];
    $form['group_user_selection']['options']['offset'] = [
      '#title' => $this->t('Skip the first results'),
      '#description' => $this->t('Hide the first results that match — for example, enter 1 to omit the single newest item.'),
      '#type' => 'number',
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('offset', $params) : 0,
      '#min' => 0,
      '#attributes' => ['placeholder' => 0],
    ];
    $form['group_user_selection']['options']['show_current_entity'] = [
      '#title' => $this->t('Include the current page'),
      '#description' => $this->t('When this block is placed on a content page, include that page in the results instead of excluding it.'),
      '#type' => 'checkbox',
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('show_current_entity', $params) : 0,
    ];
  }

  /**
   * Builds the hidden, auto-populated params textarea.
   *
   * @param array $element
   *   The widget element, passed by reference.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items.
   * @param int $delta
   *   The field delta.
   */
  protected function buildHiddenParamsField(array &$element, FieldItemListInterface $items, int $delta): void {
    $element['group_params']['params'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Params'),
      '#default_value' => $items[$delta]->params ?? NULL,
      '#empty_value' => '',
      '#attributes' => ['class' => ['views-basic--params']],
    ];
  }

  /**
   * Returns the human-readable label of the custom_vocab vocabulary.
   *
   * Memoized so a single form build (which reads it for both the filter
   * checkbox and the term-select label) loads the vocabulary only once.
   *
   * @return string
   *   The vocabulary label, or "Custom Vocab" if the vocabulary is missing.
   */
  protected function customVocabularyLabel(): string {
    if ($this->customVocabularyLabel === NULL) {
      $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load('custom_vocab');
      $this->customVocabularyLabel = $vocabulary ? $vocabulary->label() : (string) $this->t('Custom Vocab');
    }
    return $this->customVocabularyLabel;
  }

}
