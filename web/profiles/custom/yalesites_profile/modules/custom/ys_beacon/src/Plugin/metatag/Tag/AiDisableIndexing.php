<?php

namespace Drupal\ys_beacon\Plugin\metatag\Tag;

use Drupal\metatag\Plugin\metatag\Tag\MetaNameBase;

/**
 * Disables indexing for this page in an AI feed.
 *
 * The plugin ID intentionally matches the legacy ai_engine_metadata tag so
 * that values already stored in entity field_metatags data keep working
 * without migration. While both modules are installed, ys_beacon's
 * definition wins plugin discovery; the implementations are equivalent.
 *
 * @MetatagTag(
 *   id = "ai_disable_indexing",
 *   label = @Translation("Disable indexing for AI feeds."),
 *   description = @Translation("Remove this content from the AI index."),
 *   name = "ai_disable_indexing",
 *   group = "ys_beacon",
 *   weight = 2,
 *   type = "string",
 *   secure = FALSE,
 *   multiple = FALSE
 * )
 */
class AiDisableIndexing extends MetaNameBase {

  /**
   * {@inheritdoc}
   */
  public function form(array $element = []): array {
    // There should always be a value here.
    // But old entities might not have it set.
    // So we need to set it to the default value.
    if ($this->value == NULL) {
      $this->value = $this->getDefaultValueForEntityType();
    }

    $checked = $this->value === 'disabled';

    $form = [
      '#type' => 'checkbox',
      '#title' => $this->label(),
      '#description' => $this->description(),
      '#default_value' => 'disabled',
      '#required' => $element['#required'] ?? FALSE,
      '#element_validate' => [[get_class($this), 'validateTag']],
      '#attributes' => [
        'checked' => $checked ? 'checked' : NULL,
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getTestFormXpath(): array {
    return ["//input[@name='{$this->id}' and @type='checkbox']"];
  }

  /**
   * {@inheritdoc}
   */
  public function getTestOutputExistsXpath(): array {
    return ["//" . $this->htmlTag . "[@" . $this->htmlNameAttribute . "='{$this->name}' and @content='disabled']"];
  }

  /**
   * {@inheritdoc}
   */
  public function getTestOutputValuesXpath(array $values): array {
    return ["//" . $this->htmlTag . "[@" . $this->htmlNameAttribute . "='{$this->name}' and @content='disabled']"];
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value): void {
    if ($value == "1") {
      $value = 'disabled';
    }
    elseif ($value == "0") {
      $value = 'enabled';
    }

    parent::setValue($value);
  }

  /**
   * Returns the default indexing value for the entity type being edited.
   */
  protected function getDefaultValueForEntityType(): string {
    $entity_type_id = $this->request->attributes->get('entity_type_id') ?? '';

    // Media defaults to excluded from the AI index; everything else is
    // included until an editor opts out.
    return $entity_type_id === 'media' ? 'disabled' : 'enabled';
  }

}
