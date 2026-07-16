<?php

namespace Drupal\ys_core;

use Drupal\taxonomy\VocabularyListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a listing of taxonomy vocabularies.
 */
class YaleSitesVocabularyListBuilder extends VocabularyListBuilder {

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
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('messenger'),
      $container->get('entity_field.manager'),
      $container->get('ys_core.taxonomy_vocabulary_manager')
    );
  }

  /**
   * Constructs a new VocabularyListBuilder object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\ys_core\TaxonomyVocabularyManager $taxonomy_vocabulary_manager
   *   The taxonomy vocabulary manager.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    AccountInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer,
    MessengerInterface $messenger,
    EntityFieldManagerInterface $entity_field_manager,
    TaxonomyVocabularyManager $taxonomy_vocabulary_manager,
  ) {
    parent::__construct($entity_type, $current_user, $entity_type_manager, $renderer, $messenger);
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

}
