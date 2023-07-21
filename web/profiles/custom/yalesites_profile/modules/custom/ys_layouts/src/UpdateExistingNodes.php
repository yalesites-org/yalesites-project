<?php

namespace Drupal\ys_layouts;

/**
 * @file
 * Update existing nodes via hook_deploy.
 */

use Drupal\node\Entity\Node;
use Drupal\layout_builder\LayoutEntityHelperTrait;
use Drupal\layout_builder\Section;

/**
 * Updates existing nodes.
 */
class UpdateExistingNodes {

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

  /**
   * Updates Event Meta for existing nodes.
   */
  public function updateExistingPageMeta() {

    // Gets the main page meta section to clone.
    $entityTypeManager = \Drupal::service('entity_type.manager');
    $pageMetaSection = NULL;

    if ($pageViewDisplay = $entityTypeManager->getStorage('entity_view_display')->load('node.page.default')) {
      if ($pageViewDisplay->isLayoutBuilderEnabled()) {
        $pageSections = $pageViewDisplay->getSections();
        foreach ($pageSections as $section) {
          if ($section->getLayoutSettings()['label'] == 'Title and Metadata') {
            $pageMetaSection = $section;
          }
        }
      }
    }

    if ($pageMetaSection instanceof Section) {

      // Find all page nodes to update existing.
      $nids = \Drupal::entityQuery('node')->condition('type', 'page')->execute();

      foreach ($nids as $nid) {
        $node = Node::load($nid);
        $layout = $node->get('layout_builder__layout');
        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        $sections = $layout->getSections();

        foreach ($sections as $section) {
          // If an overridden layout already contains an Page Meta section,
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
        // For existing pages, remove the old title and breadcrumb block first.
        $layout->removeSection(1);
        $layout->insertSection(1, $pageMetaSection);
        $tempStore->set($section_storage);
        $node->save();
      }
    }
  }

  /**
   * Update existing pages to allow adding two column layouts.
   */
  public function updateExistingPageLock() {
    // Find all page nodes to update existing.
    $nids = \Drupal::entityQuery('node')->condition('type', 'page')->execute();

    foreach ($nids as $nid) {
      $node = Node::load($nid);
      $layout = $node->get('layout_builder__layout');

      /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
      $sections = $layout->getSections();
      foreach ($sections as $section) {
        if ($section->getLayoutSettings()['label'] == 'Content Section') {
          $section->unsetThirdPartySetting('layout_builder_lock', 'lock');
          $section_storage = $this->getSectionStorageForEntity($node);
          $tempStore = \Drupal::service('layout_builder.tempstore_repository');
          $tempStore->set($section_storage);
          $node->save();
        }
      }
    }
  }

  /**
   * Updates Post Meta for existing nodes.
   */
  public function updateExistingPostMeta() {

    // Gets the main page meta section to clone.
    $entityTypeManager = \Drupal::service('entity_type.manager');
    $postMetaSection = NULL;

    if ($postViewDisplay = $entityTypeManager->getStorage('entity_view_display')->load('node.post.default')) {
      if ($postViewDisplay->isLayoutBuilderEnabled()) {
        $postSections = $postViewDisplay->getSections();
        foreach ($postSections as $section) {
          if ($section->getLayoutSettings()['label'] == 'Title and Metadata') {
            $postMetaSection = $section;
          }
        }
      }
    }

    if ($postMetaSection instanceof Section) {

      // Find all post nodes to update existing.
      $nids = \Drupal::entityQuery('node')->condition('type', 'post')->execute();

      foreach ($nids as $nid) {
        $node = Node::load($nid);
        $layout = $node->get('layout_builder__layout');
        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        $sections = $layout->getSections();

        foreach ($sections as $section) {
          // If an overridden layout already contains an Page Meta section,
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
        // For existing pages, remove the old title and breadcrumb block first.
        $layout->removeSection(0);
        $layout->insertSection(0, $postMetaSection);
        $tempStore->set($section_storage);
        $node->save();
      }
    }
  }

}
