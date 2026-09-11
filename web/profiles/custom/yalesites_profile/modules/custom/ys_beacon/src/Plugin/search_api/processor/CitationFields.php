<?php

namespace Drupal\ys_beacon\Plugin\search_api\processor;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\ys_beacon\Service\EntityCitationResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds a retrievable citation title and URL to each indexed document.
 *
 * Storing the title and absolute URL on the document lets a site querying a
 * shared collection cite content whose Drupal entity does not exist locally
 * (see RagRetriever). The values match what RagRetriever derives from a live
 * entity because both delegate to EntityCitationResolver.
 */
#[SearchApiProcessor(
  id: 'ys_beacon_citation_fields',
  label: new TranslatableMarkup('Beacon citation fields'),
  description: new TranslatableMarkup('Adds a retrievable citation title and URL to each indexed document.'),
  stages: [
    'add_properties' => 0,
  ],
)]
class CitationFields extends ProcessorPluginBase {

  /**
   * The entity citation resolver.
   *
   * @var \Drupal\ys_beacon\Service\EntityCitationResolver
   */
  protected EntityCitationResolver $citationResolver;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $processor->citationResolver = $container->get('ys_beacon.entity_citation_resolver');
    return $processor;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $properties['ys_beacon_citation_title'] = new ProcessorProperty([
        'label' => $this->t('Citation title'),
        'description' => $this->t('The title used when citing this item, stored so a shared collection can be cited without a local entity.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
      ]);
      $properties['ys_beacon_citation_url'] = new ProcessorProperty([
        'label' => $this->t('Citation URL'),
        'description' => $this->t('The absolute URL used when citing this item, stored so a shared collection can be cited without a local entity.'),
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

    $fields = $item->getFields(FALSE);
    $this->addCitationValue($fields, 'ys_beacon_citation_title', $this->citationResolver->title($entity));
    $this->addCitationValue($fields, 'ys_beacon_citation_url', $this->citationResolver->url($entity));
  }

  /**
   * Fills the fields matching a citation property path with a value.
   *
   * @param \Drupal\search_api\Item\FieldInterface[] $fields
   *   The item's fields.
   * @param string $property_path
   *   The processor property path to fill.
   * @param string|null $value
   *   The value to add; nothing is added when it is NULL or empty.
   */
  protected function addCitationValue(array $fields, string $property_path, ?string $value): void {
    if ($value === NULL || $value === '') {
      return;
    }
    foreach ($this->getFieldsHelper()->filterForPropertyPath($fields, NULL, $property_path) as $field) {
      $field->addValue($value);
    }
  }

}
