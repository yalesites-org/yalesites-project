<?php

namespace Drupal\ys_core\Plugin\SingleContentSyncFieldProcessor;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\single_content_sync\SingleContentSyncFieldProcessorPluginBase;

/**
 * Single Content Sync Markup Handler.
 * Note: Could be replaced by 
 * class Markup extends SimpleField
 *
 * @SingleContentSyncFieldProcessor(
 *   id = "markup",
 *   title = @Translation("Markup"),
 *   field_type = "markup",
 * )
 */
class Markup extends SingleContentSyncFieldProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function exportFieldValue(FieldItemListInterface $field): array {
    return $field->getValue();
  }

  /**
   * {@inheritdoc}
   */
  public function importFieldValue(FieldableEntityInterface $entity, string $fieldName, array $value): void {
    $entity->set($fieldName, $value);
  }

}
