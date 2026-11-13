<?php

namespace Drupal\ys_migrate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Shared wiring for the CSV import services.
 *
 * Holds only what the importers genuinely have in common: the same four
 * injected dependencies and the row-scoped error message they both produce.
 *
 * The per-row loops in processImport() and previewImport() stay in the
 * implementations: they read as parallel but have diverged for real reasons,
 * and there are only two of them. A third importer wanting the same loop is
 * the point to extract it.
 */
abstract class CsvImportServiceBase implements CsvImportServiceInterface {

  use StringTranslationTrait;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The taxonomy resolver service.
   *
   * @var \Drupal\ys_migrate\Service\TaxonomyResolverService
   */
  protected $taxonomyResolver;

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
   * Constructs a CSV import service.
   *
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\ys_migrate\Service\TaxonomyResolverService $taxonomy_resolver
   *   The taxonomy resolver service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    AccountInterface $current_user,
    TaxonomyResolverService $taxonomy_resolver,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->currentUser = $current_user;
    $this->taxonomyResolver = $taxonomy_resolver;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Formats a row-scoped error message.
   *
   * @param array $row
   *   The CSV row, which may carry the true line number from the validator.
   * @param int $index
   *   The array offset, used when the row has no line number.
   * @param \Exception $e
   *   The exception raised while handling the row.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The message to show the editor.
   */
  protected function rowError(array $row, $index, \Exception $e) {
    return $this->t('Row @row: @error', [
      // Prefer the true CSV line threaded through by the validator; blank rows
      // it skipped mean the array offset is not the line the editor sees.
      '@row' => $row['_row_number'] ?? ($index + 2),
      '@error' => $e->getMessage(),
    ]);
  }

}
