<?php

namespace Drupal\ys_layouts\UpdateExisting;

/**
 * @file
 * Update two-column via hook_deploy.
 */

use Drupal\node\Entity\Node;
use Drupal\layout_builder\LayoutEntityHelperTrait;
use Drupal\layout_builder\Section;

/**
 * Updates Event Meta for existing nodes.
 */
class UpdatePageTwoColumn {

  use LayoutEntityHelperTrait;

  /**
   * Updates Event Meta for existing nodes.
   */
  public function updateExistingPages() {

    // Find all event nodes to update existing.
    $nids = \Drupal::entityQuery('node')->condition('type', 'page')->execute();
//kint($nids);

    // $node = Node::load(99);
    // $layout = $node->get('layout_builder__layout');
    // $sections = $layout->getSections();
    // foreach ($sections as $section) {
    //   if ($section->getLayoutSettings()['label'] == 'Content Section') {
    //     kint($section->getThirdPartySettings('layout_builder_lock'));
    //   }
    // }


    $node = Node::load(59);

    // $section_storage = $this->getSectionStorageForEntity($node);
    // $tempStore = \Drupal::service('layout_builder.tempstore_repository');
    // $tempStore->delete($section_storage);

    $tempStore = \Drupal::service('tempstore.shared')->get('layout_builder.section_storage.overrides');
    kint($tempStore->get('node.59.default.en'));
    //$tempStore->delete('tempstore.shared.layout_builder.section_storage.overrides');

    $layout = $node->get('layout_builder__layout');
    $sections = $layout->getSections();
    foreach ($sections as $section) {
      if ($section->getLayoutSettings()['label'] == 'Content Section') {
        kint($section->getThirdPartySettings('layout_builder_lock'));
        $section->setThirdPartySetting('layout_builder_lock', 'lock', []);
      }
    }
    $node->save();



    // foreach ($nids as $nid) {
    //   $node = Node::load($nid);
    //   $layout = $node->get('layout_builder__layout');
    //   /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
    //   $sections = $layout->getSections();

    //   // foreach ($sections as $section) {
    //   //   // If an overridden layout already contains an Event Meta section,
    //   //   // remove it from the update list.
    //   //   if ($section->getLayoutSettings()['label'] == 'Event Meta') {
    //   //     unset($nids[array_search($nid, $nids)]);
    //   //   }
    //   // }
    // }

    // if ($eventMetaSection instanceof Section) {

    //   // Find all event nodes to update existing.
    //   $nids = \Drupal::entityQuery('node')->condition('type', 'event')->execute();

    //   foreach ($nids as $nid) {
    //     $node = Node::load($nid);
    //     $layout = $node->get('layout_builder__layout');
    //     /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
    //     $sections = $layout->getSections();

    //     foreach ($sections as $section) {
    //       // If an overridden layout already contains an Event Meta section,
    //       // remove it from the update list.
    //       if ($section->getLayoutSettings()['label'] == 'Event Meta') {
    //         unset($nids[array_search($nid, $nids)]);
    //       }
    //     }
    //   }

    //   foreach ($nids as $nid) {
    //     $node = Node::load($nid);
    //     $layout = $node->get('layout_builder__layout');

    //     $section_storage = $this->getSectionStorageForEntity($node);
    //     $tempStore = \Drupal::service('layout_builder.tempstore_repository');
    //     /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
    //     $layout->insertSection(0, $eventMetaSection);
    //     $tempStore->set($section_storage);
    //     $node->save();
    //   }
    // }
  }

}
