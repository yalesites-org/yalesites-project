<?php

namespace Drupal\ys_layouts;

/**
 * @file
 * Update event meta via hook_update.
 */

use Drupal\node\Entity\Node;
use Drupal\layout_builder\LayoutEntityHelperTrait;
use Drupal\layout_builder\Section;

/**
 * Updates Event Meta for existing nodes.
 */
class UpdateEventMeta {

  use LayoutEntityHelperTrait;

  /**
   * Updates Event Meta for existing nodes.
   */
  public function updateExistingEventMeta() {

    // Gets the main event meta section to clone.
    $entityTypeManager = \Drupal::service('entity_type.manager');
    $eventMetaSection = NULL;

    if ($eventViewDisplay = $entityTypeManager->getStorage('entity_view_display')->load('node.event.default')) {
      if ($eventViewDisplay->isLayoutBuilderEnabled()) {
        $eventSections = $eventViewDisplay->getSections();
        foreach ($eventSections as $section) {
          if ($section->getLayoutSettings()['label'] == 'Title and Metadata') {
            $eventMetaSection = $section;
          }
        }
      }
    }

    if ($eventMetaSection instanceof Section) {

      // Find all event nodes to update existing.
      $nids = \Drupal::entityQuery('node')->condition('type', 'event')->execute();

      foreach ($nids as $nid) {
        $node = Node::load($nid);
        $layout = $node->get('layout_builder__layout');
        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        $sections = $layout->getSections();

        foreach ($sections as $section) {
          // If an overridden layout already contains an Event Meta section,
          // remove it from the update list.
          if ($section->getLayoutSettings()['label'] == 'Title and Metadata') {
            unset($nids[array_search($nid, $nids)]);
          }
        }
      }

      foreach ($nids as $nid) {
        $node = Node::load($nid);
        $layout = $node->get('layout_builder__layout');

        $section_storage = $this->getSectionStorageForEntity($node);
        $tempStore = \Drupal::service('layout_builder.tempstore_repository');
        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        $layout->insertSection(0, $eventMetaSection);
        $tempStore->set($section_storage);
        $node->save();
      }
    }
  }

}
