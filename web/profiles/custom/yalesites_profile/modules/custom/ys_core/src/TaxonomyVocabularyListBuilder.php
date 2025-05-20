<?php

namespace Drupal\ys_core;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a listing of taxonomy vocabularies.
 */
class TaxonomyVocabularyListBuilder extends ConfigEntityListBuilder {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;


  /**
   * The taxonomy vocabulary manager.
   *
   * @var \Drupal\ys_core\TaxonomyVocabularyManager
   */
  protected $taxonomyVocabularyManager;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('ys_core.taxonomy_vocabulary_manager')
    );
  }

  /**
   * Constructs a new EntityListBuilder object.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    TaxonomyVocabularyManager $taxonomy_vocabulary_manager,
  ) {
    parent::__construct($entity_type, $storage);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->taxonomyVocabularyManager = $taxonomy_vocabulary_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Vocabulary name');
    $header['content_types'] = $this->t('Content Types');
    $header['operations'] = $this->t('Operations');
    return $header;
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row['label'] = $entity->label();
    $row['content_types'] = ['data' => ['#markup' => implode(', ', $this->taxonomyVocabularyManager->getAssociatedContentTypes($entity))]];
    $row['operations']['data'] = $this->buildOperations($entity);
    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = [];

    $yalesites_vocabs = $this->taxonomyVocabularyManager->getYaleSitesVocabularyIds();

    // Add common table attributes.
    $table_attributes = [
      '#type' => 'table',
      '#header' => $this->buildHeader(),
      '#rows' => [],
      '#empty' => $this->t('No vocabularies available.'),
      '#attributes' => [
        'class' => ['taxonomy-vocabulary-listing'],
      ],
    ];

    // Group vocabularies.
    $entities = $this->load();
    $grouped_vocabs = $this->taxonomyVocabularyManager->groupVocabularies($entities);

    foreach ($entities as $entity) {
      $vocab_id = $entity->id();
      $group = in_array($vocab_id, $yalesites_vocabs) ? 'yalesites' : 'localist';
      $grouped_vocabs[$group][$vocab_id] = $entity;
    }

    // Build YaleSites vocabularies table.
    if (!empty($grouped_vocabs['yalesites'])) {
      $build['yalesites_header'] = [
        '#markup' => '<h2>' . $this->t('YaleSites Vocabularies') . '</h2>',
      ];
      $build['yalesites_table'] = $table_attributes;
      foreach ($grouped_vocabs['yalesites'] as $entity) {
        $build['yalesites_table']['#rows'][$entity->id()] = $this->buildRow($entity);
      }
    }

    // Build Localist vocabularies table.
    if (!empty($grouped_vocabs['localist'])) {
      $build['localist_header'] = [
        '#markup' => '<h2>' . $this->t('Localist Vocabularies') . '</h2>',
      ];
      $build['localist_table'] = $table_attributes;
      foreach ($grouped_vocabs['localist'] as $entity) {
        $build['localist_table']['#rows'][$entity->id()] = $this->buildRow($entity);
      }
    }

    // Attach custom CSS.
    $build['#attached']['library'][] = 'ys_core/taxonomy_form';

    return $build;
  }

  /**
   * Gets the content types associated with a vocabulary.
   *
   * @param \Drupal\Core\Entity\EntityInterface $vocabulary
   *   The vocabulary entity.
   *
   * @return array
   *   An array of content type labels.
   */
  protected function getAssociatedContentTypes(EntityInterface $vocabulary) {
    $content_types = [];
    $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();

    foreach ($node_types as $node_type) {
      $fields = $this->entityFieldManager->getFieldDefinitions('node', $node_type->id());
      foreach ($fields as $field) {
        if ($field->getType() === 'entity_reference' &&
            $field->getSetting('target_type') === 'taxonomy_term') {
          // Get handler settings and check if this vocabulary is referenced.
          $handler_settings = $field->getSetting('handler_settings');
          $target_bundles = $handler_settings['target_bundles'] ?? [];

          if (!empty($target_bundles) && array_key_exists($vocabulary->id(), $target_bundles)) {
            $content_types[] = $node_type->label();
            break;
          }
        }
      }
    }

    return $content_types;
  }

}
