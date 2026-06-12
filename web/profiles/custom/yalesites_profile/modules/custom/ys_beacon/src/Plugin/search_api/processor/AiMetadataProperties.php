<?php

namespace Drupal\ys_beacon\Plugin\search_api\processor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\ys_beacon\Service\AiMetadataManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds the AI metadata tag values to the indexed data.
 *
 * Exposes the ai_description and ai_tags metatag values maintained by this
 * module's AI metadata tags so they can be indexed as contextual content
 * alongside each content chunk in the vector database.
 */
#[SearchApiProcessor(
  id: 'ys_beacon_ai_metadata',
  label: new TranslatableMarkup('Beacon AI metadata'),
  description: new TranslatableMarkup('Adds the AI description and AI tags metatag values to the indexed data.'),
  stages: [
    'add_properties' => 0,
  ],
)]
class AiMetadataProperties extends ProcessorPluginBase {

  /**
   * The AI metadata manager.
   *
   * @var \Drupal\ys_beacon\Service\AiMetadataManager
   */
  protected AiMetadataManager $aiMetadataManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $processor->aiMetadataManager = $container->get('ys_beacon.ai_metadata_manager');
    return $processor;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $properties['ys_beacon_ai_description'] = new ProcessorProperty([
        'label' => $this->t('AI Description'),
        'description' => $this->t('Additional content provided to the AI model for this item via the ai_description metatag.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ]);
      $properties['ys_beacon_ai_tags'] = new ProcessorProperty([
        'label' => $this->t('AI Tags'),
        'description' => $this->t('Additional tags provided to the AI model for this item via the ai_tags metatag.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ]);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $entity = $item->getOriginalObject()?->getValue();
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }

    $metadata = $this->aiMetadataManager->getAiMetadata($entity);
    $fields = $item->getFields(FALSE);

    if (!empty($metadata['ai_description'])) {
      foreach ($this->getFieldsHelper()->filterForPropertyPath($fields, NULL, 'ys_beacon_ai_description') as $field) {
        $field->addValue($metadata['ai_description']);
      }
    }
    if (!empty($metadata['ai_tags'])) {
      foreach ($this->getFieldsHelper()->filterForPropertyPath($fields, NULL, 'ys_beacon_ai_tags') as $field) {
        $field->addValue($metadata['ai_tags']);
      }
    }
  }

}
