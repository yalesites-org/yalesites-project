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
   * Resolves the listing bundle id from the widget's host block content entity.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field items whose entity is the block content being edited.
   *
   * @return string|null
   *   The bundle id, or NULL when no entity is available.
   */
  protected function resolveBundle(FieldItemListInterface $items): ?string {
    $entity = $items->getEntity();
    return $entity ? $entity->bundle() : NULL;
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

}
