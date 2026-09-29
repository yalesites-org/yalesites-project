<?php

namespace Drupal\ys_core\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\linkit\Plugin\Field\FieldWidget\LinkitWidget;
use Drupal\paragraphs\ParagraphInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A flat "up to N links" widget for a single-bundle CTA-link paragraph field.
 *
 * The field this targets, field_heading_links (used on up to 15
 * block_content bundles), is an entity_reference_revisions field with a
 * fixed, small cardinality whose only allowed target bundle (cta_link)
 * carries exactly one field of its own (field_link). The stock paragraphs
 * widget makes editors add a
 * paragraph, pick its type from a list of one, then open/collapse/drag it
 * to reach that single link field — three clicks of ceremony around what is
 * really just "up to 3 link rows." This widget renders those rows directly,
 * one per delta, and creates/updates/deletes the underlying cta_link
 * paragraphs itself in massageFormValues() so the data model doesn't
 * change, only the authoring experience does (#1318 item 1, option B).
 *
 * @FieldWidget(
 *   id = "heading_links_inline",
 *   label = @Translation("CTA links (inline)"),
 *   field_types = {
 *     "entity_reference_revisions"
 *   }
 * )
 */
class HeadingLinksWidget extends WidgetBase {

  /**
   * The paragraph entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $paragraphStorage;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The field widget plugin manager.
   *
   * @var \Drupal\Core\Field\WidgetPluginManager
   */
  protected WidgetPluginManager $widgetPluginManager;

  /**
   * Constructs a HeadingLinksWidget.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    WidgetPluginManager $widget_plugin_manager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->paragraphStorage = $entity_type_manager->getStorage('paragraph');
    $this->entityFieldManager = $entity_field_manager;
    $this->widgetPluginManager = $widget_plugin_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.widget'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Scoped to fields shaped exactly like field_heading_links — a single
   * allowed paragraph bundle, fixed (non-unlimited) cardinality — rather
   * than every entity_reference_revisions field, so this doesn't clutter
   * the widget picker for unrelated paragraph fields it can't actually
   * handle (it hardcodes the target bundle's one field, field_link).
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    if ($field_definition->getSetting('target_type') !== 'paragraph') {
      return FALSE;
    }
    $handler_settings = $field_definition->getSetting('handler_settings') ?? [];
    $target_bundles = array_filter($handler_settings['target_bundles'] ?? []);
    if (count($target_bundles) !== 1 || !isset($target_bundles['cta_link'])) {
      return FALSE;
    }
    $cardinality = $field_definition->getFieldStorageDefinition()->getCardinality();
    return $cardinality > 0;
  }

  /**
   * {@inheritdoc}
   *
   * Cardinality is fixed and small (3), so WidgetBase's own multi-value
   * handling already renders exactly that many deltas with no "add
   * another" button — this only has to build one row.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $field_state = static::getWidgetState($form['#parents'], $field_name, $form_state);
    $field_state['host_entity'] = $items->getEntity();
    static::setWidgetState($form['#parents'], $field_name, $form_state, $field_state);

    $paragraph = $items[$delta]->entity instanceof ParagraphInterface ? $items[$delta]->entity : NULL;
    $link_items = $paragraph
      ? $paragraph->get('field_link')
      : $this->paragraphStorage->create(['type' => $this->getTargetBundle()])->get('field_link');
    // A field with no value yet (a brand-new throwaway paragraph, or an
    // existing one saved without ever getting a link) starts as a genuinely
    // empty list — delta 0 doesn't exist until something appends it, and
    // LinkWidget::formElement() dereferences $items[0] unconditionally.
    if ($link_items->isEmpty()) {
      $link_items->appendItem();
    }

    $link_widget = $this->getLinkWidget($link_items->getFieldDefinition());
    // LinkWidget::formElement() reads #required and #field_parents directly
    // off the element it's handed, so both have to be seeded here or PHP
    // warns on the missing keys. #required is deliberately FALSE: a blank
    // row is valid (it just means "no link @number"), so nothing here
    // should force a value the way a real required link field would.
    $sub_element = $link_widget->formElement($link_items, 0, [
      '#required' => FALSE,
      '#field_parents' => $element['#field_parents'] ?? [],
    ], $form, $form_state);

    $element += [
      '#type' => 'fieldset',
      '#title' => $this->t('Link @number', ['@number' => $delta + 1]),
      '#attributes' => ['class' => ['heading-links-widget__row']],
      '#element_validate' => [[static::class, 'validateRow']],
    ];
    $element['uri'] = $sub_element['uri'];
    $element['title'] = $sub_element['title'];
    $element['attributes'] = $sub_element['attributes'];
    // LinkWidget's own #states wiring (making title conditionally required)
    // targets a field name/delta selector that assumes it owns the whole
    // field, which doesn't match this row's actual position in a composed
    // multi-row widget — drop it in favor of validateRow() below, which
    // checks the same "both or neither" rule against elements this widget
    // actually controls.
    unset($element['uri']['#states'], $element['title']['#states']);
    // Carries the existing paragraph's id (if any) through to
    // massageFormValues(), which has no direct access to $items and so
    // can't otherwise tell a row being edited from a row being created.
    $element['existing_paragraph_id'] = [
      '#type' => 'value',
      '#value' => $paragraph?->id(),
    ];

    return $element;
  }

  /**
   * Element validate callback: a row's URL and link text travel together.
   *
   * Mirrors the same "both or neither" rule LinkWidget enforces on a real
   * link field (this paragraph bundle's field_link has its title setting at
   * DRUPAL_REQUIRED) — needed explicitly here because the cta_link
   * paragraphs this widget creates are saved via the
   * entity_reference_revisions save cascade, not through their own entity
   * form, so nothing else would validate that link field's requiredness
   * before save.
   */
  public static function validateRow(array &$element, FormStateInterface $form_state, array $form) {
    $uri = $element['uri']['#value'] ?? '';
    $title = $element['title']['#value'] ?? '';
    if ($uri !== '' && $title === '') {
      $form_state->setError($element['title'], t('Link text is required when a URL is entered.'));
    }
    elseif ($uri === '' && $title !== '') {
      $form_state->setError($element['uri'], t('A URL is required when link text is entered.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * Creates, updates or deletes the cta_link paragraph behind each row and
   * hands back an 'entity' value per delta — entity_reference_revisions
   * saves any referenced entity itself (via EntityNeedsSaveInterface) as
   * part of the host block's own save, so nothing here calls
   * $paragraph->save() directly.
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $field_state = static::getWidgetState($form['#parents'], $field_name, $form_state);
    $host = $field_state['host_entity'] ?? NULL;
    $bundle = $this->getTargetBundle();
    $link_field_definition = $this->entityFieldManager->getFieldDefinitions('paragraph', $bundle)['field_link'];
    $link_widget = $this->getLinkWidget($link_field_definition);

    foreach ($values as &$value) {
      $uri = trim((string) ($value['uri'] ?? ''));
      $title = trim((string) ($value['title'] ?? ''));
      $existing_id = $value['existing_paragraph_id'] ?? NULL;

      if ($uri === '' && $title === '') {
        // Row left blank: drop any paragraph it used to point to, and leave
        // nothing behind for this delta so filterEmptyItems() removes it.
        if ($existing_id && $paragraph = $this->paragraphStorage->load($existing_id)) {
          $paragraph->delete();
        }
        $value = [];
        continue;
      }

      $paragraph = $existing_id ? $this->paragraphStorage->load($existing_id) : NULL;
      if (!$paragraph) {
        $paragraph = $this->paragraphStorage->create(['type' => $bundle]);
        if ($host) {
          $paragraph->setParentEntity($host, $field_name);
        }
      }

      // Reuses LinkitWidget's own submitted-value handling (autocomplete
      // string -> entity: URI resolution, url-decoding) rather than
      // reimplementing it.
      $link_value = [
        'uri' => $value['uri'] ?? '',
        'title' => $value['title'] ?? '',
        'attributes' => $value['attributes'] ?? [],
      ];
      $massaged = $link_widget->massageFormValues([$link_value], $form, $form_state);
      $paragraph->set('field_link', reset($massaged));
      $paragraph->setNeedsSave(TRUE);

      $value = ['entity' => $paragraph];
    }

    return array_values(array_filter($values));
  }

  /**
   * Returns the single paragraph bundle this field is scoped to.
   *
   * @return string
   *   The target paragraph bundle machine name.
   */
  protected function getTargetBundle(): string {
    $handler_settings = $this->fieldDefinition->getSetting('handler_settings') ?? [];
    $target_bundles = array_filter($handler_settings['target_bundles'] ?? []);
    return (string) key($target_bundles);
  }

  /**
   * Builds a configured Linkit widget for the paragraph's link field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $link_field_definition
   *   The cta_link paragraph bundle's field_link field definition.
   *
   * @return \Drupal\linkit\Plugin\Field\FieldWidget\LinkitWidget
   *   A Linkit widget instance, matching the settings the cta_link
   *   paragraph's own form display already uses so editors see the same
   *   autocomplete behavior here as they would editing a paragraph directly.
   */
  protected function getLinkWidget(FieldDefinitionInterface $link_field_definition): LinkitWidget {
    return $this->widgetPluginManager->getInstance([
      'field_definition' => $link_field_definition,
      'configuration' => [
        'type' => 'linkit',
        'settings' => [
          'placeholder_url' => '',
          'placeholder_title' => '',
          'linkit_profile' => 'default',
          'linkit_auto_link_text' => FALSE,
        ],
        'third_party_settings' => [],
      ],
    ]);
  }

}
