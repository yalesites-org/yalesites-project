<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
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
   * Settings that belong in each exposed filter's accordion body.
   *
   * Keyed by the filter's checkbox key, listing the sibling elements built by
   * ::buildExposedFilterControls() that configure that filter.
   * ::buildExposedFilterAccordion() uses this to pair them into one row. A
   * filter absent from this map (search, audience, year) has no settings, so it
   * renders as a header-only row.
   */
  protected const EXPOSED_FILTER_SETTINGS = [
    'show_category_filter' => ['category_filter_label', 'category_included_terms'],
    'show_custom_vocab_filter' => ['custom_vocab_included_terms'],
  ];

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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    ViewsBasicManager $views_basic_manager,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
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
    $this->configFactory = $config_factory;
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
      $container->get('entity_type.manager'),
      $container->get('config.factory')
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
    $this->buildDisplayControls($form, $items, $delta, $formSelectors);
    $this->buildHiddenParamsField($element, $items, $delta);

    $form['#attached']['library'][] = 'ys_views_basic/ys_views_basic';
    $form['#attached']['library'][] = 'ys_views_basic/term_operator';
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
      // Mirrors the site's configured heading typeface (Site Settings > Font
      // Pairing) so the mockup reads like the live front-end render. The
      // webfonts themselves load via the ys_views_basic_preview library's
      // dependency on ys_core/font_preview.
      '#font_pairing' => $this->configFactory->get('ys_core.site')->get('font_pairing') ?? 'yalenew',
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

    // The exposed filters, their settings and the per-result field options were
    // all relocated by the #after_build passes, so look them up by key rather
    // than at a fixed depth.
    $built = static::flattenBuiltElements($selection['entity_and_view_mode'] ?? []);

    // The include/exclude operator radios are similarly one level deeper than
    // their #value: buildTermOperator() nests each inside a #type container
    // used purely for layout/CSS, so look these up by key too. This reads
    // the actual submitted value, not a stored default: buildTermOperator()
    // disables an empty operator via a plain #attributes flag rather than
    // Form API's #disabled, specifically so a value picked while the
    // JS-enabled control was live still gets saved (see that method's
    // docblock for why #disabled itself would have silently discarded it).
    $filter_and_sort = static::flattenBuiltElements($selection['filter_and_sort'] ?? []);

    // Rebuild the #type checkboxes contract these params used to carry: only
    // enabled filters present, each keyed by itself. This matters beyond
    // tidiness — ViewsBasicManager tests several of these with isset() rather
    // than truthiness, so a disabled filter has to be absent, not 0, or its
    // filter would never be removed from the view.
    $exposed_filter_options = [];
    foreach (array_keys($this->getExposedFilterOptions()) as $option_key) {
      if (!empty($built[$option_key]['#value'])) {
        $exposed_filter_options[$option_key] = $option_key;
      }
    }

    foreach ($values as &$value) {
      $paramData = [
        'view_mode' => $this->getViewMode(),
        'filters' => [
          'types' => [$this->getContentType()],
          'terms_include' => $terms_include,
          'terms_exclude' => $terms_exclude,
        ],
        'field_options' => $built['field_options']['#value'],
        'event_field_options' => [],
        'post_field_options' => [],
        'exposed_filter_options' => $exposed_filter_options,
        'category_filter_label' => $built['category_filter_label']['#value'] ?? NULL,
        'category_included_terms' => $built['category_included_terms']['#value'] ?? NULL,
        'custom_vocab_included_terms' => $built['custom_vocab_included_terms']['#value'] ?? NULL,
        // Split include/exclude operators (#1316): a shared operator applied
        // both, so "All" made includes stricter and excludes *looser* at the
        // same time (exclude only hid a node tagged with every excluded
        // term, not any). No legacy 'operator' key is written; blocks saved
        // before this change keep working via getDefaultParamValue()'s
        // fallback on read.
        'include_operator' => $filter_and_sort['include_operator']['#value'],
        'exclude_operator' => $filter_and_sort['exclude_operator']['#value'],
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
      'show_search_filter' => $this->t('Show Search'),
      'show_category_filter' => $this->t('Show Category'),
      'show_custom_vocab_filter' => $this->t('Show @vocab', ['@vocab' => $this->customVocabularyLabel()]),
      'show_audience_filter' => $this->t('Show Audience'),
    ];
  }

  /**
   * Returns per-option help text for the exposed filter checkboxes.
   *
   * Keyed the same as ::getExposedFilterOptions(). A filter absent from this
   * map renders with no #description, matching the previous behavior for
   * every option except search (#1481: the search option's explanation used
   * to be crammed into its label instead of a proper #description).
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Help text keyed by exposed-filter machine name.
   */
  protected function getExposedFilterDescriptions(): array {
    return [
      'show_search_filter' => $this->t('Matches titles only — not body text, tags, or categories.'),
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
        // Renamed from "Field display & filters" (#1481): "Field display"
        // and "Display options" (the next tab) read as the same tab twice.
        'title' => $this->t('Results & filters'),
        'open' => TRUE,
        'classes' => ['views-basic--entity-view-mode'],
      ],
      'filter_and_sort' => [
        'title' => $this->t('Tags, sorting & pinned'),
        'open' => TRUE,
        'classes' => [],
      ],
      'options' => [
        // Reverted back to "Display options" (#1481): "How many items"
        // undersold what's actually here (Event Time Period, skip-the-first,
        // include-current-page) — the collision this was meant to fix is
        // gone now that the other tab is "Results & filters" instead of
        // "Field display & filters".
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

    // Gather the exposed filters into per-filter rows once the group's subtree
    // is fully built — see ::buildExposedFilterAccordion() for why this cannot
    // be a #process.
    $form['group_user_selection']['entity_and_view_mode']['#after_build'][] = [
      static::class,
      'buildExposedFilterAccordion',
    ];
    $form['group_user_selection']['entity_and_view_mode']['#after_build'][] = [
      static::class,
      'groupFieldDisplayRow',
    ];

    // Pair each include/exclude term select with its own operator control
    // into one padded unit (#1481) — see ::groupTermOperatorRows() for why.
    $form['group_user_selection']['filter_and_sort']['#after_build'][] = [
      static::class,
      'groupTermOperatorRows',
    ];
    // Give "Highlight pinned items" the same toggle/disclosure row as the
    // exposed filters (#1481) — see ::groupPinnedRow() for why.
    $form['group_user_selection']['filter_and_sort']['#after_build'][] = [
      static::class,
      'groupPinnedRow',
    ];

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
      $options['show_thumbnail'] = $this->t('Show thumbnail image');
    }
    // Title for the field-display group of options (#1317). Kept for the
    // accessible name but rendered invisible (#1481): the wrapping "Result
    // content" fieldset legend (::groupFieldDisplayRow()) already says this,
    // so a visible title here duplicated it as two headings 32px apart.
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
      '#title_display' => 'invisible',
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

    // Individual checkboxes rather than one #type checkboxes: each filter has
    // to own the settings that configure it so buildExposedFilterAccordion()
    // can pair the two into a single row (#1337). The child keys are the same
    // option keys as before, so the generated input names — and therefore the
    // #states selectors getFormSelectors() builds from them — are unchanged.
    $enabled = array_values(array_filter(
      (array) ($params ? $this->viewsBasicManager->getDefaultParamValue('exposed_filter_options', $params) : [])
    ));

    $form['group_user_selection']['entity_and_view_mode']['exposed_filter_options'] = [
      '#type' => 'fieldset',
      // Renamed from "Exposed Filter Options" (#1481) — "Exposed" is Views'
      // own internal term, not editor-facing vocabulary.
      '#title' => $this->t('Filters for visitors'),
      '#tree' => TRUE,
      '#attributes' => ['class' => ['vb-filter-rows']],
      // gin_lb ignores #description_display: 'before' on a fieldset (always
      // renders it after every filter row), so the intro is a #markup child
      // with a negative #weight instead — the same pattern
      // ::buildTermIncludeExclude() uses for tag_filters_intro (#1481).
      'exposed_filter_options_intro' => [
        '#weight' => -10,
        '#markup' => '<p class="vb-fieldset-intro">' . $this->t('Add filter controls that visitors can use on the published page to narrow the results themselves.') . '</p>',
      ],
    ];
    $descriptions = $this->getExposedFilterDescriptions();
    foreach ($this->getExposedFilterOptions() as $option_key => $option_label) {
      $form['group_user_selection']['entity_and_view_mode']['exposed_filter_options'][$option_key] = [
        '#type' => 'checkbox',
        '#title' => $option_label,
        '#description' => $descriptions[$option_key] ?? NULL,
        // Stored as a [key => key] map, but tolerate a plain list too.
        '#default_value' => in_array($option_key, $enabled, TRUE),
      ];
    }

    // The settings below are disabled while their filter is switched off
    // (#1337) rather than hidden by #states. The accordion collapses the body
    // in CSS off the same checkbox, so the disabled state is what stops a
    // collapsed row from still submitting a value.
    $form['group_user_selection']['entity_and_view_mode']['category_filter_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Category Filter Label'),
      '#description' => $this->t("Enter a custom label for the <strong>Category Filter</strong>. This label will be displayed to users as the filter's name. If left blank, the default label <strong>Category</strong> will be used."),
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('category_filter_label', $params) : NULL,
      '#states' => ['disabled' => [$formSelectors['show_category_filter_selector'] => ['checked' => FALSE]]],
    ];

    $form['group_user_selection']['entity_and_view_mode']['category_included_terms'] = [
      '#type' => 'select',
      // Renamed from "Filter by Category Parent Term" (#1481) — "Parent
      // Term" is taxonomy-internal vocabulary.
      '#title' => $this->t('Limit choices to'),
      '#description' => $this->t("Select a parent term to show content tagged with that term's sub-items. This ignores content tagged as the parent term and any other parent terms in the vocabulary."),
      '#options' => $this->viewsBasicManager->getTaxonomyParents($this->getCategoryVocabulary(), (string) $this->t('All categories')),
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('category_included_terms', $params) : NULL,
      '#validated' => 'true',
      '#prefix' => '<div id="edit-category-included-terms">',
      '#suffix' => '</div>',
      '#states' => ['disabled' => [$formSelectors['show_category_filter_selector'] => ['checked' => FALSE]]],
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
      '#states' => ['disabled' => [$formSelectors['show_custom_vocab_filter_selector'] => ['checked' => FALSE]]],
    ];
  }

  /**
   * Pairs each exposed filter checkbox with its settings into one row.
   *
   * Runs as an #after_build on the "Field display & filters" group, which is
   * the only point where the move is safe: FormBuilder assigns a child's
   * #parents while recursing into it, so relocating an element in a #process
   * would rename its input, whereas by #after_build every #parents is already
   * fixed and survives the move. Submitted values, and the #states selectors
   * that address these inputs by name, are therefore unaffected — only the
   * render tree changes.
   *
   * Filters with no settings (search, audience, year) become a header-only
   * row; the rest get a body their header can disclose.
   *
   * @param array $element
   *   The entity_and_view_mode group, after its subtree has been built.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The group with each filter gathered into its own row.
   */
  public static function buildExposedFilterAccordion(array $element, FormStateInterface $form_state): array {
    if (!isset($element['exposed_filter_options'])) {
      return $element;
    }

    $weight = 0;
    foreach (Element::children($element['exposed_filter_options']) as $filter_key) {
      $checkbox = $element['exposed_filter_options'][$filter_key];
      // Anything already wrapped is a row from a previous pass, not a filter.
      if (($checkbox['#type'] ?? NULL) !== 'checkbox') {
        continue;
      }

      // The header must sort above the body, and the settings elements carry
      // weights from where they were originally built, so both get an explicit
      // one here rather than relying on array order.
      $checkbox['#weight'] = 0;
      $row = [
        '#type' => 'container',
        '#attributes' => ['class' => ['vb-filter-row']],
        '#weight' => $weight++,
        $filter_key => $checkbox,
      ];
      unset($element['exposed_filter_options'][$filter_key]);

      $body = [];
      $body_weight = 0;
      foreach (static::EXPOSED_FILTER_SETTINGS[$filter_key] ?? [] as $setting_key) {
        if (isset($element[$setting_key])) {
          $body[$setting_key] = $element[$setting_key];
          $body[$setting_key]['#weight'] = $body_weight++;
          unset($element[$setting_key]);
        }
      }
      if ($body) {
        $row['settings'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['vb-filter-row__body']],
          '#weight' => 1,
        ] + $body;
        $row['#attributes']['class'][] = 'vb-filter-row--has-settings';
      }

      $element['exposed_filter_options'][$filter_key . '__row'] = $row;
    }

    return $element;
  }

  /**
   * Puts the per-result field options and the preview on one grid row.
   *
   * Splits the tab into two rows (#1337): the field-display checkboxes beside
   * the preview, then the exposed filters full width below. Keeping the preview
   * next to a row whose height is stable is what stops it stretching — the
   * exposed filters grow as their settings are disclosed, and they are no
   * longer in the same row.
   *
   * The field_options, event_field_options and post_field_options groups (each
   * built separately — the last two only by the per-type widgets) are gathered
   * into one "Result content" fieldset here rather than at build time, so the
   * per-type widgets keep writing their sibling groups exactly as before; only
   * the render tree changes. flattenBuiltElements() already descends through
   * any structural wrapper, so massageFormValues() and
   * massageEntitySpecificParams() find these values whether they sit at the
   * top level or inside this fieldset.
   *
   * Runs as an #after_build for the same reason as
   * ::buildExposedFilterAccordion(): every #parents is fixed by this point, so
   * the move cannot rename an input.
   *
   * @param array $element
   *   The entity_and_view_mode group, after its subtree has been built.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The group with a display row wrapping the field options and preview.
   */
  public static function groupFieldDisplayRow(array $element, FormStateInterface $form_state): array {
    $field_option_keys = ['field_options', 'event_field_options', 'post_field_options'];
    $field_options = [];
    foreach ($field_option_keys as $key) {
      if (isset($element[$key])) {
        $field_options[$key] = $element[$key];
        unset($element[$key]);
      }
    }

    $row = [];
    if ($field_options) {
      $row['result_content'] = [
        '#type' => 'fieldset',
        '#title' => t('Result content'),
        '#attributes' => ['class' => ['vb-result-content']],
        // gin_lb ignores #description_display: 'before' on a fieldset
        // (always renders it after field_options/event_field_options/
        // post_field_options instead of introducing them), so the intro is a
        // #markup child with a negative #weight instead — the same pattern
        // ViewsBasicWidgetBase::buildTermIncludeExclude() uses for
        // tag_filters_intro (#1481).
        'result_content_intro' => [
          '#weight' => -10,
          '#markup' => '<p class="vb-fieldset-intro">' . t("Choose which details appear on each result. These settings don't change which content the list pulls in.") . '</p>',
        ],
      ] + $field_options;
    }
    if (isset($element['preview'])) {
      $row['preview'] = $element['preview'];
      unset($element['preview']);
    }
    if (!$row) {
      return $element;
    }

    $element['display_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['vb-display-row']],
      '#weight' => -10,
    ] + $row;

    return $element;
  }

  /**
   * Pairs each include/exclude term select with its own operator control.
   *
   * The "Any/All" operator control (::buildTermOperator()) already groups its
   * own prefix/suffix text and radios into one flex row, but that row was
   * still a sibling of the term select it modifies, spaced from it (and from
   * the next, unrelated field) by plain margin — margin that doesn't collapse
   * inside this form's flex/grid containers (#1481, the same root cause
   * ::buildDisplayControls() and the general one-sided-margin CSS rule work
   * around elsewhere), so the gap above and below read as arbitrarily large
   * rather than as "this operator belongs to the select above it." Wrapping
   * the pair in one container and padding *that* — rather than margining the
   * two pieces apart — is what makes them read as one settled unit instead of
   * two independently-spaced fields that happen to be near each other.
   *
   * Runs as an #after_build on "Tags, sorting & pinned" for the same reason
   * as ::buildExposedFilterAccordion()/::groupFieldDisplayRow(): every
   * #parents is already fixed by this point, so gathering these into a
   * wrapping container does not rename their inputs.
   *
   * unset() + reassign moves include_group/exclude_group to the end of
   * $element's array, same as the other row-grouping methods — which is why
   * they (and every other top-level child of this group: tag_filters_intro,
   * ::buildSortControl()'s sort_by, ::groupPinnedRow()'s pinned_to_top_row)
   * carry an explicit #weight rather than relying on array order to hold
   * after the reshuffle.
   *
   * @param array $element
   *   The filter_and_sort group, after its subtree has been built.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The group with each select+operator pair wrapped in one container.
   */
  public static function groupTermOperatorRows(array $element, FormStateInterface $form_state): array {
    $pairs = [
      'include_group' => ['terms_include', 'include_operator_wrapper', 0],
      'exclude_group' => ['terms_exclude', 'exclude_operator_wrapper', 1],
    ];
    foreach ($pairs as $group_key => $pair) {
      [$select_key, $operator_key, $weight] = $pair;
      $group = [
        '#type' => 'container',
        '#weight' => $weight,
        '#attributes' => ['class' => ['vb-term-group']],
      ];
      foreach ([$select_key, $operator_key] as $child_key) {
        if (isset($element[$child_key])) {
          $group[$child_key] = $element[$child_key];
          unset($element[$child_key]);
        }
      }
      if (Element::children($group)) {
        $element[$group_key] = $group;
      }
    }
    return $element;
  }

  /**
   * Wraps "Highlight pinned items" and its label field into one filter row.
   *
   * Gives this checkbox and its one dependent field the same bordered,
   * disclosure-on-check row treatment as the exposed filters
   * (::buildExposedFilterAccordion(), Show Category/Show Custom Vocab)
   * rather than a plain checkbox with a #states-toggled sibling field —
   * "toggle turns on, row expands" is the established shape in this form for
   * a checkbox with a dependent field, and this is the one other place that
   * shape occurs (#1481). ::buildPinnedControls() pairs pin_label's own
   * #states with this: 'disabled' (not 'visible'/'required'), matching
   * category_filter_label — the row's CSS collapses the body via :has() the
   * same way, so 'disabled' is what stops a collapsed row from still
   * submitting a value; see ::buildExposedFilterControls()'s docblock for why
   * that has to be a plain #attributes flag done at the field level, not
   * #states plumbed through here.
   *
   * Runs as an #after_build on "Tags, sorting & pinned" for the same reason
   * as ::buildExposedFilterAccordion(): every #parents is already fixed by
   * this point, so wrapping these does not rename their inputs.
   *
   * @param array $element
   *   The filter_and_sort group, after its subtree has been built.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The group with pinned_to_top and pin_label wrapped in one row.
   */
  public static function groupPinnedRow(array $element, FormStateInterface $form_state): array {
    if (!isset($element['pinned_to_top'])) {
      return $element;
    }
    // The header must sort above the body: pin_label carries whatever weight
    // it had from where it was originally built (::buildPinnedControls()),
    // so both children get an explicit weight here rather than relying on
    // array order — the exact mistake this method's own first version made,
    // which put "Pinned-item label" above "Highlight pinned items" instead
    // of below it. Matches ::buildExposedFilterAccordion()'s technique.
    $checkbox = $element['pinned_to_top'];
    $checkbox['#weight'] = 0;
    $row = [
      '#type' => 'container',
      // After sort_by (weight 2) — see ::groupTermOperatorRows()'s docblock
      // for why every top-level child of this group needs an explicit weight.
      '#weight' => 3,
      // vb-filter-row--pinned (#1481): a dedicated styling hook on the row
      // container itself, not the checkbox's own gin_lb-rendered item —
      // overriding gin_lb's internal checkbox-toggle padding directly proved
      // unreliable (still no visible effect after !important at higher
      // specificity than confirmed-working rules elsewhere in this file), so
      // extra top spacing is added to this container, which this module
      // fully controls, instead.
      '#attributes' => ['class' => ['vb-filter-row', 'vb-filter-row--pinned']],
      'pinned_to_top' => $checkbox,
    ];
    unset($element['pinned_to_top']);
    if (isset($element['pin_label'])) {
      $pin_label = $element['pin_label'];
      $pin_label['#weight'] = 0;
      $row['settings'] = [
        '#type' => 'container',
        '#weight' => 1,
        '#attributes' => ['class' => ['vb-filter-row__body']],
        'pin_label' => $pin_label,
      ];
      $row['#attributes']['class'][] = 'vb-filter-row--has-settings';
      unset($element['pin_label']);
    }
    $element['pinned_to_top_row'] = $row;
    return $element;
  }

  /**
   * Flattens a built subtree to a key => element map of its value elements.
   *
   * MassageFormValues() reads submitted values off the built elements, so it
   * has to find them wherever ::buildExposedFilterAccordion(),
   * ::groupFieldDisplayRow(), ::groupTermOperatorRows() and
   * ::groupPinnedRow() moved them.
   *
   * @param array $element
   *   A built form element.
   *
   * @return array
   *   Every value element in the subtree, keyed by its own element key.
   */
  protected static function flattenBuiltElements(array $element): array {
    $flat = [];
    foreach (Element::children($element) as $key) {
      $child = $element[$key];
      // Descend through structural wrappers (container, fieldset, details) but
      // stop at anything carrying #input, so a value element that builds child
      // elements of its own is returned whole rather than descended into:
      // field_options is a #type checkboxes whose per-option children would
      // otherwise shadow it, and its own #value is what gets stored.
      if (empty($child['#input']) && Element::children($child)) {
        $flat += static::flattenBuiltElements($child);
        continue;
      }
      $flat[$key] = $child;
    }
    return $flat;
  }

  /**
   * Returns the vocabularies whose terms populate this widget's tag selects.
   *
   * Scoped per content type (#1316 spec) rather than every vocabulary on the
   * site: this widget's own category vocabulary (::getCategoryVocabulary(),
   * already profile-aware — "affiliation" rather than "profile_category"),
   * plus the three vocabularies every content type shares.
   *
   * @return string[]
   *   Vocabulary machine names, in the order they should group in the select.
   */
  protected function getTagVocabularies(): array {
    return [
      $this->getCategoryVocabulary(),
      'tags',
      'audience',
      'custom_vocab',
    ];
  }

  /**
   * Builds the include/exclude term selects and their per-list operators.
   *
   * Each multi-select gets its own operator control directly beneath it
   * (#1316), rather than the one shared "Match content tagged with" control
   * this replaces. A shared operator was a correctness bug, not just an
   * unclear label: ViewsBasicManager::setupView() joins both lists with the
   * same operator, so choosing "All" made includes stricter and excludes
   * *looser* at the same time — a node was only hidden if it carried every
   * excluded term, not any one of them. Splitting the operator is what makes
   * "any vs. all" answerable per list; the widget's own disable-on-empty
   * behavior (views-basic-term-operator.js) then keeps each one disabled
   * until its list has something to apply it to.
   *
   * The include and exclude lists work identically (any vs. all), so the
   * worked example lives once, in a shared intro above both, rather than
   * repeated near-verbatim in each select's own #description.
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
    $tags = $this->viewsBasicManager->getTagsForVocabularies($this->getTagVocabularies());

    $form['group_user_selection']['filter_and_sort']['tag_filters_intro'] = [
      '#type' => 'container',
      // Explicit weight (#1481): ::groupTermOperatorRows() reshuffles this
      // group's array order (its unset()+reassign of include_group/
      // exclude_group appends them at the end), so every top-level child of
      // this group now needs an explicit weight rather than relying on
      // array-insertion order to hold after that reshuffle.
      '#weight' => -10,
      '#attributes' => ['class' => ['vb-tag-filters-intro']],
      'heading' => [
        '#markup' => '<h3 class="vb-tag-filters-intro__heading">' . $this->t('Tag filters') . '</h3>',
      ],
      'help' => [
        '#markup' => '<p class="vb-tag-filters-intro__help">' . $this->t('Choose which tags narrow this list. Example: with Undergraduate and Admissions selected, "any" matches content tagged with either one; "all" matches only content tagged with both.') . '</p>',
      ],
    ];

    $include_terms = $params ? $this->viewsBasicManager->getDefaultParamValue('terms_include', $params) : [];
    $form['group_user_selection']['filter_and_sort']['terms_include'] = [
      '#title' => $this->t('Only show content tagged'),
      '#type' => 'select',
      '#options' => $tags,
      '#chosen' => TRUE,
      '#multiple' => TRUE,
      '#default_value' => $include_terms,
    ];
    $form['group_user_selection']['filter_and_sort']['include_operator_wrapper'] = $this->buildTermOperator(
      'include',
      $this->t('Show content tagged with'),
      $this->t('of these'),
      $params ? $this->viewsBasicManager->getDefaultParamValue('include_operator', $params) : '+',
      empty($include_terms),
    );

    $exclude_terms = $params ? $this->viewsBasicManager->getDefaultParamValue('terms_exclude', $params) : [];
    $form['group_user_selection']['filter_and_sort']['terms_exclude'] = [
      '#title' => $this->t('Hide content tagged'),
      '#type' => 'select',
      '#options' => $tags,
      '#chosen' => TRUE,
      '#multiple' => TRUE,
      '#default_value' => $exclude_terms,
    ];
    $form['group_user_selection']['filter_and_sort']['exclude_operator_wrapper'] = $this->buildTermOperator(
      'exclude',
      $this->t('Hide content tagged with'),
      $this->t('of these'),
      $params ? $this->viewsBasicManager->getDefaultParamValue('exclude_operator', $params) : '+',
      empty($exclude_terms),
    );
  }

  /**
   * Builds one include/exclude operator control: a segmented Any/All pair.
   *
   * Rendered as a two-segment button pair via CSS on top of plain #type
   * radios — not <button> elements or a JS-driven widget. <button> would
   * submit the form; real radios keep native arrow-key navigation between
   * the two segments and are announced by screen readers as a radio group
   * for free, and the control still degrades to plain radios without JS.
   * views-basic-term-operator.css visually hides the native input and
   * styles its sibling <label> as one half of the joined control; it also
   * opts this group out of the icon-card treatment the shared
   * .glb-form-type--radio styling (views-basic-form-tabs.css) applies to
   * every other radio group in this tab context, since "any vs. all" is a
   * logical relationship an icon cannot represent.
   *
   * The control is always rendered — never hidden — and disabled instead
   * when its paired multi-select is empty, since the setting is genuinely
   * meaningless against an empty list. That disabling is done via a plain
   * `disabled` HTML attribute in #attributes, deliberately NOT the Form API
   * #disabled property, on FormBuilder::doBuildForm()'s own documented
   * advice: "#disabled [...] results in user input being ignored regardless
   * of [...] whether JavaScript is used to change the control's attributes
   * [...] If one still decides to use JavaScript to affect when a control is
   * enabled, then it is best [...] for the control to be enabled in the
   * HTML, and disabled by JavaScript." #disabled would have been correct for
   * a control that should stay inert forever; this one is enabled by
   * views-basic-term-operator.js the moment its paired select gets a value.
   * A brand-new, unsaved block has no stored terms yet, so this method
   * recomputes $disabled as TRUE on every rebuild including the one at final
   * submit — if that had used #disabled, Drupal would silently discard
   * whatever the user actually picked in the JS-enabled control and fall
   * back to #default_value on every single new-block save, independent of
   * what was clicked. The plain #attributes disabled flag only affects
   * rendering, not submission, so the real submitted value is what
   * massageFormValues() reads.
   *
   * The sentence text ("Show content tagged with [Any|All] of these")
   * wraps the segmented control rather than sitting in a separate title or
   * description, so #title is kept but visually hidden — the accessible
   * name screen readers need still has to exist even though nothing
   * renders for it.
   *
   * @param string $variant
   *   Either 'include' or 'exclude', for the CSS/JS pairing hook and the
   *   massageFormValues() key.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $prefix
   *   The sentence fragment shown (and used as the accessible name) before
   *   the segmented control, e.g. "Show content tagged with".
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $suffix
   *   The sentence fragment shown after the segmented control, e.g.
   *   "of these".
   * @param string $default_value
   *   The stored or default operator value ("+" or ",").
   * @param bool $disabled
   *   TRUE when the paired multi-select currently has no value.
   *
   * @return array
   *   The container render array wrapping the operator radios.
   */
  protected function buildTermOperator(string $variant, $prefix, $suffix, string $default_value, bool $disabled): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ["vb-term-operator", "vb-term-operator--$variant"],
      ],
      "{$variant}_operator" => [
        '#type' => 'radios',
        '#title' => $prefix,
        '#title_display' => 'invisible',
        // "+" is OR and "," is AND.
        '#options' => [
          '+' => $this->t('Any'),
          ',' => $this->t('All'),
        ],
        '#default_value' => $default_value,
        '#attributes' => $disabled ? ['disabled' => 'disabled'] : [],
        '#prefix' => '<span class="vb-term-operator__text vb-term-operator__text--prefix">' . $prefix . '</span>',
        '#suffix' => '<span class="vb-term-operator__text vb-term-operator__text--suffix">' . $suffix . '</span>',
      ],
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
      // Explicit weight (#1481) — see ::groupTermOperatorRows()'s docblock.
      '#weight' => 2,
      '#options' => $this->viewsBasicManager->sortByList($this->getContentType()),
      '#title' => $this->t('Sort by'),
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
      // Disabled (not visible/required) while its checkbox is off (#1481),
      // matching category_filter_label: ::groupPinnedRow() wraps this pair
      // into the same bordered/disclosure row as the exposed filters, whose
      // CSS collapses the body via :has() off the checkbox rather than
      // #states visibility, so 'disabled' is what stops a collapsed row from
      // still submitting a value — see that method's docblock.
      '#states' => [
        'disabled' => [$formSelectors['pinned_to_top_selector'] => ['checked' => FALSE]],
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
   * @param array $formSelectors
   *   The resolved form selectors for #states.
   */
  protected function buildDisplayControls(array &$form, FieldItemListInterface $items, int $delta, array $formSelectors): void {
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
    // The "Limit to [n] items" single-row composition from the #1481 spec is
    // NOT implemented here: views-basic.js live-swaps this field's title
    // between "Items" and "Items per Page" as the site builder changes
    // "display" client-side (no form rebuild), and doing that safely against
    // a #field_prefix/#field_suffix layout needs the real rendered DOM (gin_lb
    // markup isn't vendored in this checkout) to confirm the JS keeps that
    // toggle correct rather than leaving a stale "Limit to" prefix showing
    // under "Pagination after". Flagged as a follow-up needing browser QA;
    // the #required/#states fix below (the actual functional bug) is not
    // blocked on that.
    $form['group_user_selection']['options']['limit'] = [
      '#title' => $displayValue === 'pager' ? $this->t('Items per Page') : $this->t('Items'),
      '#type' => 'number',
      '#default_value' => $params ? $this->viewsBasicManager->getDefaultParamValue('limit', $params) : 10,
      '#min' => 1,
      // #required paired with #states 'visible'/'required' (#1481), matching
      // ::buildPinnedControls()'s pin_label pattern: this field used to carry
      // '#required' => TRUE unconditionally while views-basic.js hid it with
      // inline `display: none` whenever "display" was "all" — a required
      // field a site builder could never see or fill in.
      '#required' => TRUE,
      '#states' => [
        'visible' => [$formSelectors['display_ajax'] => ['!value' => 'all']],
        'required' => [$formSelectors['display_ajax'] => ['!value' => 'all']],
      ],
      '#prefix' => '<div id="edit-limit">',
      '#suffix' => '</div>',
    ];
    $form['group_user_selection']['options']['offset'] = [
      // The "Skip the first [n] results" single-row composition from the
      // #1481 spec was tried here via #field_prefix/#field_suffix and
      // reverted: gin_lb renders the prefix/suffix and the (invisible)
      // title stacked on separate lines rather than inline around the
      // input, confirmed live rather than guessed — the same class of
      // rendering risk flagged for the "limit" field below. Plain title,
      // matching the rest of this form's fields.
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
      // Fallback renamed from "Custom Vocab" (#1481) — editor-facing text
      // should not read as a Drupal machine name even when the vocabulary is
      // missing.
      $this->customVocabularyLabel = $vocabulary ? $vocabulary->label() : (string) $this->t('Custom tags');
    }
    return $this->customVocabularyLabel;
  }

}
