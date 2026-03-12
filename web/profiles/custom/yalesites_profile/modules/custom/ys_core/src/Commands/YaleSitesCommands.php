<?php

namespace Drupal\ys_core\Commands;

use Drupal\taxonomy\Entity\Term;
use Drush\Commands\DrushCommands;

/**
 * A Drush commandfile for YaleSites operations.
 */
class YaleSitesCommands extends DrushCommands {

  /**
   * Populates the Academic Years vocabulary with terms from 2026-2027 to 2000-2001.
   *
   * @command ys:populate-academic-years
   * @aliases ys-populate-ay
   * @usage ys:populate-academic-years
   *   Populates Academic Years vocabulary with terms.
   */
  public function populateAcademicYears() {
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $vocabulary_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary');

    // Check if academic_years vocabulary exists.
    if (!$vocabulary_storage->load('academic_years')) {
      $this->logger->error('Academic Years vocabulary does not exist.');
      return;
    }

    // Get existing terms to avoid duplicates.
    $existing_terms = $term_storage->loadByProperties(['vid' => 'academic_years']);
    $existing_names = [];
    foreach ($existing_terms as $term) {
      $existing_names[] = $term->getName();
    }

    $this->logger->notice('Found ' . count($existing_names) . ' existing academic year terms.');

    // Generate academic year terms from 2026-2027 back to 2000-2001.
    $weight = 0;
    $created = 0;
    for ($start_year = 2026; $start_year >= 2000; $start_year--) {
      $end_year = $start_year + 1;
      $term_name = $start_year . '-' . $end_year;

      // Skip if term already exists.
      if (in_array($term_name, $existing_names)) {
        continue;
      }

      Term::create([
        'vid' => 'academic_years',
        'name' => $term_name,
        'weight' => $weight,
      ])->save();

      $this->logger->info('Created term: ' . $term_name);
      $created++;
      $weight++;
    }

    if ($created > 0) {
      $this->logger->success('Successfully created ' . $created . ' academic year terms.');
    }
    else {
      $this->logger->notice('No new terms created. All terms already exist.');
    }
  }

}
