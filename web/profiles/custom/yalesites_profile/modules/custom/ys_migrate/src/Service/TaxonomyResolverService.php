<?php

namespace Drupal\ys_migrate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for resolving taxonomy terms by name.
 */
class TaxonomyResolverService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a TaxonomyResolverService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Resolves taxonomy terms by name, creating them if they don't exist.
   *
   * @param array $term_names
   *   Array of term names.
   * @param string $vocabulary
   *   The vocabulary machine name.
   *
   * @return array
   *   Array of term IDs.
   */
  public function resolveTerms(array $term_names, $vocabulary) {
    $term_ids = [];

    foreach ($term_names as $term_name) {
      $term = $this->findOrCreateTerm($term_name, $vocabulary);
      if ($term) {
        $term_ids[] = $term->id();
      }
    }

    return $term_ids;
  }

  /**
   * Finds or creates a taxonomy term.
   *
   * @param string $name
   *   The term name.
   * @param string $vocabulary
   *   The vocabulary machine name.
   *
   * @return \Drupal\taxonomy\TermInterface|null
   *   The term or null on failure.
   */
  public function findOrCreateTerm($name, $vocabulary) {
    // First, try to find existing term.
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->condition('vid', $vocabulary)
      ->condition('name', $name)
      ->range(0, 1)
      ->accessCheck(FALSE);

    $tids = $query->execute();

    if (!empty($tids)) {
      return $this->entityTypeManager->getStorage('taxonomy_term')->load(reset($tids));
    }

    // Create new term if it doesn't exist.
    try {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
        'vid' => $vocabulary,
        'name' => $name,
      ]);
      $term->save();
      return $term;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ys_migrate')->error('Failed to create taxonomy term @name in @vocabulary: @error', [
        '@name' => $name,
        '@vocabulary' => $vocabulary,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Parses comma-separated values into an array.
   *
   * @param string $value
   *   The comma-separated string.
   *
   * @return array
   *   Array of trimmed values.
   */
  public function parseCommaSeparatedValues($value) {
    if (empty($value)) {
      return [];
    }

    $values = explode(',', $value);
    return array_map('trim', array_filter($values));
  }

}
