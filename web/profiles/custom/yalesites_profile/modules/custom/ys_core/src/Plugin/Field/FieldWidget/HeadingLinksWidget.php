<?php

namespace Drupal\ys_core\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
 * to reach that single link field: three clicks of ceremony around what is
 * really just "up to 3 link rows." This widget renders those rows directly,
 * one per delta, and hands back the underlying cta_link paragraphs (new or
 * updated, unsaved) from massageFormValues() so the data model doesn't
 * change, only the authoring experience does (#1318 item 1, option B).
 */
#[FieldWidget(
  id: 'heading_links_inline',
  label: new TranslatableMarkup('CTA links (inline)'),
  field_types: ['entity_reference_revisions'],
)]
class HeadingLinksWidget extends WidgetBase {

  /**
   * The paragraph entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $paragraphStorage;

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
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->paragraphStorage = $entity_type_manager->getStorage('paragraph');
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
   * another" button. This only has to build one row.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $field_state = static::getWidgetState($form['#parents'], $field_name, $form_state);
    $field_state['host_entity'] = $items->getEntity();
    static::setWidgetState($form['#parents'], $field_name, $form_state, $field_state);

    $existing = $items[$delta]->entity instanceof ParagraphInterface ? $items[$delta]->entity : NULL;
    $paragraph = $existing ?? $this->paragraphStorage->create(['type' => $this->getTargetBundle()]);

    // Carries the existing paragraph's id and revision (if any) through to
    // massageFormValues(), which has no direct access to $items and so
    // can't otherwise tell a row being edited from a row being created.
    $element['existing_paragraph_id'] = [
      '#type' => 'value',
      '#value' => $existing?->id(),
    ];
    $element['existing_paragraph_revision_id'] = [
      '#type' => 'value',
      '#value' => $existing?->getRevisionId(),
    ];

    $link_widget = $this->getLinkWidget($paragraph);
    if (!$link_widget) {
      // field_link is hidden on the cta_link form display, so there is
      // nothing for editors to edit. The row stays in the form (hidden) so
      // massageFormValues() keeps the existing reference untouched.
      $element['#access'] = FALSE;
      return $element;
    }

    $link_items = $paragraph->get('field_link');
    // A field with no value yet (a brand-new throwaway paragraph, or an
    // existing one saved without ever getting a link) starts as a genuinely
    // empty list: delta 0 doesn't exist until something appends it, and
    // LinkWidget::formElement() dereferences $items[0] unconditionally.
    if ($link_items->isEmpty()) {
      $link_items->appendItem();
    }

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
    $element['attributes'] = $sub_element['attributes'] ?? [];
    // LinkWidget's own #states wiring (making title conditionally required)
    // targets a field name/delta selector that assumes it owns the whole
    // field, which doesn't match this row's actual position in a composed
    // multi-row widget. Drop it in favor of validateRow() below, which
    // checks the same "both or neither" rule against elements this widget
    // actually controls.
    unset($element['uri']['#states'], $element['title']['#states']);

    return $element;
  }

  /**
   * Element validate callback: a row's URL and link text travel together.
   *
   * Mirrors the same "both or neither" rule LinkWidget enforces on a real
   * link field (this paragraph bundle's field_link has its title setting at
   * DRUPAL_REQUIRED). Needed explicitly here because the cta_link
   * paragraphs this widget creates are saved via the
   * entity_reference_revisions save cascade, not through their own entity
   * form, so nothing else would validate that link field's requiredness
   * before save.
   */
  public static function validateRow(array &$element, FormStateInterface $form_state, array $form) {
    $uri = $element['uri']['#value'] ?? '';
    $title = $element['title']['#value'] ?? '';
    if ($uri !== '' && $title === '') {
      $form_state->setError($element['title'], new TranslatableMarkup('Link text is required when a URL is entered.'));
    }
    elseif ($uri === '' && $title !== '') {
      $form_state->setError($element['uri'], new TranslatableMarkup('A URL is required when link text is entered.'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * Hands back an 'entity' value per filled row: the existing cta_link
   * paragraph updated in memory, or a new unsaved one. Nothing here saves
   * or deletes an entity. Drupal calls this during validation, AJAX
   * rebuilds and Layout Builder block validation, long before (or without
   * ever) saving the host, so any storage write here would leak into the
   * live revision. entity_reference_revisions saves needs-save paragraphs
   * as part of the host's own save, and a cleared row simply drops its
   * reference; the old revision keeps pointing at its paragraph and
   * entity_reference_revisions' orphan purger handles what no revision
   * references any more.
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $field_state = static::getWidgetState($form['#parents'], $field_name, $form_state);
    $host = $field_state['host_entity'] ?? NULL;
    $bundle = $this->getTargetBundle();

    foreach ($values as &$value) {
      $paragraph = $this->loadExistingParagraph($value);
      $link_widget = $this->getLinkWidget($paragraph ?? $this->paragraphStorage->create(['type' => $bundle]));

      if (!$link_widget) {
        // field_link is hidden on the form display, so the row was never
        // editable: keep whatever it already referenced, unchanged.
        $value = $paragraph ? ['entity' => $paragraph] : [];
        continue;
      }

      $uri = trim((string) ($value['uri'] ?? ''));
      $title = trim((string) ($value['title'] ?? ''));
      if ($uri === '' && $title === '') {
        // Row left blank: drop the reference only, and leave nothing behind
        // for this delta so filterEmptyItems() removes it.
        $value = [];
        continue;
      }

      if (!$paragraph) {
        $paragraph = $this->paragraphStorage->create(['type' => $bundle]);
        if ($host) {
          $paragraph->setParentEntity($host, $field_name);
        }
      }

      // Reuses the link widget's own submitted-value handling (Linkit's
      // autocomplete string to entity: URI resolution, url-decoding) rather
      // than reimplementing it.
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
   * Loads the paragraph a submitted row was built from, if any.
   *
   * Prefers the exact revision the host referenced, which also returns a
   * fresh object rather than the shared static-cache copy, so in-memory
   * edits during value extraction do not leak to other code in the request.
   *
   * @param array $value
   *   One submitted row.
   *
   * @return \Drupal\paragraphs\ParagraphInterface|null
   *   The existing paragraph, or NULL for a new row.
   */
  protected function loadExistingParagraph(array $value): ?ParagraphInterface {
    $revision_id = $value['existing_paragraph_revision_id'] ?? NULL;
    $id = $value['existing_paragraph_id'] ?? NULL;
    $paragraph = NULL;
    if ($revision_id) {
      $paragraph = $this->paragraphStorage->loadRevision($revision_id);
    }
    if (!$paragraph && $id) {
      $paragraph = $this->paragraphStorage->load($id);
    }
    return $paragraph instanceof ParagraphInterface ? $paragraph : NULL;
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
   * Returns the widget the paragraph's own form display uses for field_link.
   *
   * Editors see the same widget and settings here as they would editing the
   * cta_link paragraph directly, including any form display alters.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   An existing or throwaway paragraph of the target bundle.
   *
   * @return \Drupal\Core\Field\WidgetInterface|null
   *   The configured widget, or NULL when field_link is hidden.
   */
  protected function getLinkWidget(ParagraphInterface $paragraph): ?WidgetInterface {
    return $this->getFormDisplay($paragraph)->getRenderer('field_link');
  }

  /**
   * Collects the default form display for a paragraph.
   *
   * @param \Drupal\paragraphs\ParagraphInterface $paragraph
   *   The paragraph.
   *
   * @return \Drupal\Core\Entity\Display\EntityFormDisplayInterface
   *   The form display, after form mode and display alters.
   */
  protected function getFormDisplay(ParagraphInterface $paragraph): EntityFormDisplayInterface {
    return EntityFormDisplay::collectRenderDisplay($paragraph, 'default');
  }

}
