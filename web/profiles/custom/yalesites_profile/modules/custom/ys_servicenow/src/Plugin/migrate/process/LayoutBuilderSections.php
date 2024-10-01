<?php

namespace Drupal\ys_servicenow\Plugin\migrate\process;

use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Process plugin to migrate a source field into a Layout Builder Section.
 *
 * Inspiration/references:
 *   https://github.com/dsasser/d8-migrate-paragraphs-layout-builder/blob/master/web/modules/custom/d8_migrations/src/LayoutBase.php
 *   https://github.com/chrisfromredfin/dcon21-milb/blob/main/web/modules/custom/my_migrations/src/Plugin/migrate/process/LayoutBuilderSectionsPages.php.
 *
 * @MigrateProcessPlugin(
 *   id = "layout_builder_sections",
 * )
 */
class LayoutBuilderSections extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {

    if ($value == NULL) {
      return NULL;
    }

    // Setup some variables we'll need:
    // - components holds all the components to be written into our section
    // - generator connects to the uuid generator service.
    $components = [];
    $generator = \Drupal::service('uuid');
    $entityTypeManager = \Drupal::entityTypeManager();
    $entityQuery = $entityTypeManager->getStorage('block_content')->getQuery();
    $entityQuery->condition('info', $value)
      ->accessCheck(FALSE);
    $ids = $entityQuery->execute();

    $block_content = NULL;
    if (!empty($ids)) {
      $block_content = $entityTypeManager->getStorage('block_content')->load(reset($ids));
    }

    if (is_null($block_content)) {
      \Drupal::messenger()->addError("Could not load " . $value . ' ???', 'status', TRUE);
      return NULL;
    }

    $config = [
      'id' => 'inline_block:text',
      'label' => $block_content->label(),
      'provider' => 'layout_builder',
      'label_display' => FALSE,
      'view_mode' => 'full',
      'block_revision_id' => $block_content->getRevisionId(),
      'block_serialized' => serialize($block_content),
      'context_mapping' => [],
    ];

    $components[] = new SectionComponent($generator->generate(), 'content', $config);

    // If you were doing multiple sections, you'd want this to be an array
    // somehow. @TODO figure out how to do that ;)
    // PARAMS: $layout_id, $layout_settings, $components.
    $sections = new Section('layout_onecol', [], $components);

    return $sections;
  }

  /**
   * {@inheritdoc}
   */
  public function multiple() {
    // Perhaps if multiple() returned TRUE this would help allow
    // multiple Sections. ;)
    return FALSE;
  }

}
