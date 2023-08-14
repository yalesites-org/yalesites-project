<?php

namespace Drupal\ys_core\Plugin\SingleContentSyncFieldProcessor;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\single_content_sync\SingleContentSyncFieldProcessorPluginBase;

/**
 * Single Content Sync Markup Handler.
 *
 * @SingleContentSyncFieldProcessor(
 *   id = "views_basic_params",
 *   title = @Translation("Views Basic Params"),
 *   field_type = "views_basic_params",
 * )
 */
class ViewsBasicParams extends SingleContentSyncFieldProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function exportFieldValue(FieldItemListInterface $field): array {
    \Drupal::logger('ys_core')->notice('ViewsBasicParams exportFieldValue: ' . print_r($field->getValue(), TRUE));
    
    return $field->getValue();
  }

  /**
   * {@inheritdoc}
   */
  public function importFieldValue(FieldableEntityInterface $entity, string $fieldName, array $value): void {
    $entity->set($fieldName, $value);
  }

}

