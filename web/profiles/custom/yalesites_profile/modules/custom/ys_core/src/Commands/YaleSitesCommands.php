<?php

namespace Drupal\ys_core\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile for YaleSites operations.
 */
class YaleSitesCommands extends DrushCommands {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a YaleSitesCommands object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Populates the Academic Years vocab with terms from 2026-2027 to 2000-2001.
   *
   * @command ys:populate-academic-years
   * @aliases ys-populate-ay
   * @usage ys:populate-academic-years
   *   Populates Academic Years vocabulary with terms.
   */
  public function populateAcademicYears() {
    // Delegate to the shared helper in ys_core.install so the Drush command
    // and ys_core_update_10010() use identical logic.
    $this->moduleHandler->loadInclude('ys_core', 'install');

    $vocabulary_storage = $this->entityTypeManager->getStorage('taxonomy_vocabulary');
    if (!$vocabulary_storage->load('academic_years')) {
      $this->logger->error('Academic Years vocabulary does not exist.');
      return;
    }

    $created = ys_core_populate_academic_years_terms();

    if ($created > 0) {
      $this->logger->success('Successfully created ' . $created . ' academic year terms.');
    }
    else {
      $this->logger->notice('No new terms created. All terms already exist.');
    }
  }

}
