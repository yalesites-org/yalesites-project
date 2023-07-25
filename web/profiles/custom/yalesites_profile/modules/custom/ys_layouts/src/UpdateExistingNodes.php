<?php

namespace Drupal\ys_layouts;

/**
 * @file
 * Update existing nodes via hook_deploy.
 */

use Drupal\layout_builder\LayoutEntityHelperTrait;
use Drupal\layout_builder\Section;
use Drupal\node\Entity\Node;

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

        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        $layout->insertSection(0, $eventMetaSection);
        $this->updateEventTempStore($eventMetaSection);
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
          if ($section->getLayoutId() == 'ys_layout_page_meta') {
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

        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        // For existing pages, remove the old title and breadcrumb block first.
        $layout->removeSection(1);
        $layout->insertSection(1, $pageMetaSection);
        $this->updatePageTempStore($pageMetaSection);
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
          $this->updatePageTempStoreLock();
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

        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        // For existing pages, remove the old title and breadcrumb block first.
        $layout->removeSection(0);
        $layout->insertSection(0, $postMetaSection);
        $this->updatePostTempStore($postMetaSection);
        $node->save();
      }
    }
  }

  /**
   * Updates Page temp store with new section.
   */
  public function updatePageTempStore(Section $sectionToClone) {

    $db = \Drupal::database();
    if ($db->schema()->tableExists('key_value_expire')) {
      $query = $db->select('key_value_expire', 'kve');
      $query = $query->condition('collection', 'tempstore.shared.layout_builder.section_storage.overrides', '=')
        ->fields('kve');
      $results = $query->execute();
      foreach ($results as $record) {
        $stored_data = unserialize($record->value, [
          'allowed_classes' => TRUE,
        ]);
        /** @var \Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage $section_storage */
        $section_storage = $stored_data->data['section_storage'];

        if (
          $section_storage->getSections()[1]->getLayoutSettings()['label'] == 'Title Section'
          && $stored_data->data['section_storage']->getContext('entity')->getContextData()->getEntity()->bundle() == 'page') {
          $section_storage->removeSection(1);
          $section_storage->insertSection(1, $sectionToClone);

          $stored_data->data['section_storage'] = $section_storage;

          // Insert the updated tempstore.
          $db->update('key_value_expire')
            ->condition('name', $record->name, '=')
            ->condition('expire', $record->expire, '=')
            ->fields([
              'value' => serialize($stored_data),
            ])
            ->execute();
        }

      }

    }
  }

  /**
   * Updates Page temp store with new section.
   */
  public function updatePostTempStore(Section $sectionToClone) {

    $db = \Drupal::database();
    if ($db->schema()->tableExists('key_value_expire')) {
      $query = $db->select('key_value_expire', 'kve');
      $query = $query->condition('collection', 'tempstore.shared.layout_builder.section_storage.overrides', '=')
        ->fields('kve');
      $results = $query->execute();
      foreach ($results as $record) {
        $stored_data = unserialize($record->value, [
          'allowed_classes' => TRUE,
        ]);
        /** @var \Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage $section_storage */
        $section_storage = $stored_data->data['section_storage'];

        if (
          $section_storage->getSections()[1]->getLayoutSettings()['label'] == 'Title Section'
          && $stored_data->data['section_storage']->getContext('entity')->getContextData()->getEntity()->bundle() == 'page') {
          $section_storage->removeSection(0);
          $section_storage->insertSection(0, $sectionToClone);

          $stored_data->data['section_storage'] = $section_storage;

          // Insert the updated tempstore.
          $db->update('key_value_expire')
            ->condition('name', $record->name, '=')
            ->condition('expire', $record->expire, '=')
            ->fields([
              'value' => serialize($stored_data),
            ])
            ->execute();
        }

      }

    }
  }

  /**
   * Updates Event temp store with new section.
   */
  public function updateEventTempStore(Section $sectionToClone) {

    $db = \Drupal::database();
    if ($db->schema()->tableExists('key_value_expire')) {
      $query = $db->select('key_value_expire', 'kve');
      $query = $query->condition('collection', 'tempstore.shared.layout_builder.section_storage.overrides', '=')
        ->fields('kve');
      $results = $query->execute();
      foreach ($results as $record) {
        $stored_data = unserialize($record->value, [
          'allowed_classes' => TRUE,
        ]);
        /** @var \Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage $section_storage */
        $section_storage = $stored_data->data['section_storage'];
        if ($stored_data->data['section_storage']->getContext('entity')->getContextData()->getEntity()->bundle() == 'event') {
          $section_storage->insertSection(0, $sectionToClone);

          $stored_data->data['section_storage'] = $section_storage;

          // Insert the updated tempstore.
          $db->update('key_value_expire')
            ->condition('name', $record->name, '=')
            ->condition('expire', $record->expire, '=')
            ->fields([
              'value' => serialize($stored_data),
            ])
            ->execute();
        }
      }
    }
  }

  /**
   * Updates Event temp store with new section.
   */
  public function updatePageTempStoreLock() {

    $db = \Drupal::database();
    if ($db->schema()->tableExists('key_value_expire')) {
      $query = $db->select('key_value_expire', 'kve');
      $query = $query->condition('collection', 'tempstore.shared.layout_builder.section_storage.overrides', '=')
        ->fields('kve');
      $results = $query->execute();
      foreach ($results as $record) {
        $stored_data = unserialize($record->value, [
          'allowed_classes' => TRUE,
        ]);
        /** @var \Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage $section_storage */
        $section_storage = $stored_data->data['section_storage'];
        if ($stored_data->data['section_storage']->getContext('entity')->getContextData()->getEntity()->bundle() == 'page') {
          $sections = $section_storage->getSections();
          foreach ($sections as $key => $section) {
            if ($section->getLayoutSettings()['label'] == 'Content Section') {
              $sectionToUpdate = $key;
            }
          }
          $section_storage->getSection($sectionToUpdate)->unsetThirdPartySetting('layout_builder_lock', 'lock');

          $stored_data->data['section_storage'] = $section_storage;

          // Insert the updated tempstore.
          $db->update('key_value_expire')
            ->condition('name', $record->name, '=')
            ->condition('expire', $record->expire, '=')
            ->fields([
              'value' => serialize($stored_data),
            ])
            ->execute();
        }
      }
    }
  }

}
