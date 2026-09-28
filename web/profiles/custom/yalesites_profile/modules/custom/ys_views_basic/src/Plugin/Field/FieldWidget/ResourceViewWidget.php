<?php

namespace Drupal\ys_views_basic\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_views_basic\ViewsBasicManager;

/**
 * Resource listing widget.
 *
 * Serves the resource_card, resource_portrait_grid, resource_list_item and
 * resource_condensed bundles (#1723), replacing the widget of the separate
 * ys_views_content_resources module. Everything that widget offered stays
 * selectable under the same label: the singular "Show Category", the six
 * resource details (teaser text, discipline, journal name and issue, authors,
 * publish date), five extra exposed filters, and the choice of fields the
 * search runs across. The details are offered only on the designs whose
 * templates render them, which is every design but condensed.
 *
 * @FieldWidget(
 *   id = "resource_view_widget",
 *   label = @Translation("Resource listing widget"),
 *   field_types = {
 *     "views_basic_params"
 *   }
 * )
 */
class ResourceViewWidget extends ViewsBasicWidgetBase {

  /**
   * Resource view modes whose node templates render the resource details.
   *
   * The condensed template renders only the category (plus the publish date
   * and authors, which the old widget never offered there), so the old widget
   * hid every other option in that design; this keeps that.
   */
  const RESOURCE_FIELD_VIEW_MODES = ['card', 'portrait_grid', 'list_item'];

  /**
   * {@inheritdoc}
   *
   * The search-field picker configures the search filter, so it goes in that
   * filter's row.
   */
  protected const EXPOSED_FILTER_SETTINGS = parent::EXPOSED_FILTER_SETTINGS + [
    'show_search_filter' => ['search_fields'],
  ];

  /**
   * {@inheritdoc}
   */
  protected function getContentType(): ?string {
    return ViewsBasicManager::CONTENT_TYPE_RESOURCE;
  }

  /**
   * {@inheritdoc}
   *
   * Resources keep the singular label the old widget used.
   */
  protected function buildCategoryLabel() {
    return $this->t('Show Category');
  }

  /**
   * {@inheritdoc}
   *
   * The vocabularies the content_resources view filters on, as the old
   * manager's ALLOWED_TAG_VOCABULARIES bounded its tag list.
   */
  protected function getTagVocabularies(): array {
    return array_merge(parent::getTagVocabularies(), [
      'academic_years',
      'areas_of_study',
      'discipline',
      'geographic_areas',
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * Keeps the old widget's "Show Teaser Image" label, and drops "Show Tags"
   * from condensed, whose template does not render tags.
   */
  protected function buildFieldDisplayOptions(array &$form, FieldItemListInterface $items, int $delta): void {
    parent::buildFieldDisplayOptions($form, $items, $delta);
    $options = &$form['group_user_selection']['entity_and_view_mode']['field_options']['#options'];
    if (isset($options['show_thumbnail'])) {
      $options['show_thumbnail'] = $this->t('Show Teaser Image');
    }
    if (!in_array($this->getViewMode(), self::RESOURCE_FIELD_VIEW_MODES, TRUE)) {
      unset($options['show_tags']);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Adds the resource details and the search-field picker.
   */
  protected function buildEntitySpecificOptions(array &$form, FieldItemListInterface $items, int $delta): void {
    $params = $items[$delta]->params;

    if (in_array($this->getViewMode(), self::RESOURCE_FIELD_VIEW_MODES, TRUE)) {
      $saved = $params ? $this->viewsBasicManager->getDefaultParamValue('resource_field_options', $params) : [];
      // A new block shows the teaser text, as the old widget's did.
      if (empty($saved) && $this->isCreateForm($items)) {
        $saved = ['show_teaser_text'];
      }
      $form['group_user_selection']['entity_and_view_mode']['resource_field_options'] = [
        '#type' => 'checkboxes',
        // Styling hook (#1481) — see
        // EventViewWidget::buildEntitySpecificOptions() for why this needs the
        // fieldset's between-groups gap and #prefix/#suffix.
        '#prefix' => '<div class="vb-result-content__subsection">',
        '#suffix' => '</div>',
        '#options' => [
          'show_teaser_text' => $this->t('Show Teaser Text'),
          'show_discipline' => $this->t('Show Discipline'),
          'show_journal_name' => $this->t('Show Journal/Publication Name'),
          'show_journal_issue' => $this->t('Show Journal/Publication Issue'),
          'show_authors' => $this->t('Show Authors'),
          'show_publish_date' => $this->t('Show Publish Date'),
        ],
        '#title' => $this->t('Resource options'),
        '#tree' => TRUE,
        '#default_value' => $saved,
      ];
    }

    $search_fields = $this->viewsBasicManager->getDefaultParamValue('search_fields', $params ?? '');
    $form['group_user_selection']['entity_and_view_mode']['search_fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Search Fields'),
      '#description' => $this->t('Select which fields the search filter will search across.'),
      '#options' => [
        'title' => $this->t('Title'),
        'field_teaser_text' => $this->t('Teaser Text'),
        'field_teaser_title' => $this->t('Teaser Title'),
        'field_journal_publication_name' => $this->t('Journal/Publication Name'),
      ],
      '#tree' => TRUE,
      '#default_value' => array_values(array_filter($search_fields)),
      '#element_validate' => [[static::class, 'validateSearchFields']],
      // Disabled rather than hidden while search is off, like the other
      // filters' settings — see ::buildExposedFilterControls().
      '#states' => [
        'disabled' => [($form['#form_selectors']['show_search_filter_selector'] ?? '') => ['checked' => FALSE]],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The shared set plus the five resource-only filters, in the old widget's
   * order.
   */
  protected function getExposedFilterOptions(): array {
    $shared = parent::getExposedFilterOptions();
    return [
      'show_search_filter' => $shared['show_search_filter'],
      'show_year_filter' => $this->t('Show Year'),
      'show_category_filter' => $shared['show_category_filter'],
      'show_custom_vocab_filter' => $shared['show_custom_vocab_filter'],
      'show_audience_filter' => $shared['show_audience_filter'],
      'show_academic_year_filter' => $this->t('Show Academic Year'),
      'show_discipline_filter' => $this->t('Show Discipline'),
      'show_areas_of_study_filter' => $this->t('Show Areas of Study'),
      'show_geographic_areas_filter' => $this->t('Show Geographic Areas'),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The shared "titles only" help does not apply: resource search runs across
   * whichever fields the search-field picker selects.
   */
  protected function getExposedFilterDescriptions(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function massageEntitySpecificParams(array &$paramData, array $form, FormStateInterface $form_state): void {
    // Looked up by key: the #after_build passes move both elements.
    $built = static::flattenBuiltElements($form['group_user_selection']['entity_and_view_mode'] ?? []);
    $paramData['resource_field_options'] = $built['resource_field_options']['#value'] ?? [];
    $paramData['search_fields'] = $built['search_fields']['#value'] ?? [];
  }

  /**
   * Validates that at least one search field is selected when search is on.
   */
  public static function validateSearchFields(array &$element, FormStateInterface $form_state): void {
    $parents = array_slice($element['#parents'], 0, -1);
    $search_enabled = $form_state->getValue(array_merge($parents, ['exposed_filter_options', 'show_search_filter']));
    if ($search_enabled && !array_filter((array) $element['#value'])) {
      $form_state->setError($element, new TranslatableMarkup('At least one search field must be selected when search is enabled.'));
    }
  }

}
