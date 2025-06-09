<?php

namespace Drupal\ys_core;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Service for managing taxonomy vocabulary operations.
 */
class TaxonomyVocabularyManager {

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
   * Constructs a new TaxonomyVocabularyManager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * Gets the YaleSites vocabulary IDs.
   *
   * @return array
   *   Array of YaleSites vocabulary machine names.
   */
  public function getYaleSitesVocabularyIds() {
    return [
      'event_category',
      'profile_affiliation',
      'affiliation',
      'audience',
      'custom_vocab',
      'post_category',
      'page_category',
      'tags',
    ];
  }

  /**
   * Groups vocabularies into YaleSites and Localist categories.
   *
   * @param array $vocabularies
   *   Array of vocabulary entities.
   *
   * @return array
   *   Associative array with 'yalesites' and 'localist' groups.
   */
  public function groupVocabularies(array $vocabularies) {
    $yalesites_vocabs = $this->getYaleSitesVocabularyIds();
    $grouped_vocabs = [
      'yalesites' => [],
      'localist' => [],
    ];

    foreach ($vocabularies as $vocabulary) {
      $vocab_id = $vocabulary->id();
      $group = in_array($vocab_id, $yalesites_vocabs) ? 'yalesites' : 'localist';
      $grouped_vocabs[$group][$vocab_id] = $vocabulary;
    }

    return $grouped_vocabs;
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
  public function getAssociatedContentTypes(EntityInterface $vocabulary) {
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

    return array_unique($content_types);
  }

}
