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
   * Page Meta Section.
   *
   * @var Drupal\layout_builder\Section
   */
  protected $pageMetaSection;

  /**
   * Post Meta Section.
   *
   * @var Drupal\layout_builder\Section
   */
  protected $postMetaSection;

  /**
   * Event Meta Section.
   *
   * @var Drupal\layout_builder\Section
   */
  protected $eventMetaSection;

  /**
   * Updates Page Meta for existing nodes.
   */
  public function updateExistingPageMeta() {

    // Gets the main page meta section to clone.
    $entityTypeManager = \Drupal::service('entity_type.manager');

    if ($pageViewDisplay = $entityTypeManager->getStorage('entity_view_display')->load('node.page.default')) {
      if ($pageViewDisplay->isLayoutBuilderEnabled()) {
        $pageSections = $pageViewDisplay->getSections();
        foreach ($pageSections as $section) {
          if ($section->getLayoutId() == 'ys_layout_page_meta') {
            $this->pageMetaSection = $section;
          }
        }
      }
    }

    if ($this->pageMetaSection instanceof Section) {

      // Find all page nodes to update existing.
      $nids = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('type', 'page')->execute();

      foreach ($nids as $nid) {
        $node = Node::load($nid);
        $layout = $node->get('layout_builder__layout');
        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        $sections = $layout->getSections();

        // If there are no sections, this nid has the default layout, remove it.
        if (count($sections) === 0) {
          unset($nids[array_search($nid, $nids)]);
        }
        else {
          foreach ($sections as $section) {
            // If an overridden layout already contains an Page Meta section,
            // remove it from the update list.
            if ($section->getLayoutSettings()['label'] == 'Title and Metadata') {
              unset($nids[array_search($nid, $nids)]);
            }
          }
        }
      }

      foreach ($nids as $nid) {
        $node = Node::load($nid);
        $layout = $node->get('layout_builder__layout');

        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        // For existing pages, remove the old title and breadcrumb block first.
        $layout->removeSection(1);
        $layout->insertSection(1, $this->pageMetaSection);

        $this->updateTempStore(function (&$stored_data) {

          $section_storage = $stored_data->data['section_storage'];
          if (
            !empty($section_storage->getSections()[1])
            && $section_storage->getSections()[1]->getLayoutSettings()['label'] == 'Title Section'
            && $stored_data->data['section_storage']->getContext('entity')->getContextData()->getEntity()->bundle() == 'page') {

            $section_storage = $stored_data->data['section_storage'];
            $section_storage->removeSection(1);
            $section_storage->insertSection(1, $this->pageMetaSection);
          }
        });

        $node->save();
      }
    }
  }

  /**
   * Update existing pages to allow adding two column layouts.
   */
  public function updateExistingPageLock() {
    // Find all page nodes to update existing.
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'page')->execute();

    foreach ($nids as $nid) {
      $node = Node::load($nid);
      $layout = $node->get('layout_builder__layout');

      /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
      $sections = $layout->getSections();
      foreach ($sections as $section) {
        if ($section->getLayoutSettings()['label'] == 'Content Section') {
          $section->unsetThirdPartySetting('layout_builder_lock', 'lock');

          $this->updateTempStore(function (&$stored_data) {
            if ($stored_data->data['section_storage']->getContext('entity')->getContextData()->getEntity()->bundle() == 'page') {
              $section_storage = $stored_data->data['section_storage'];
              $sections = $section_storage->getSections();
              foreach ($sections as $key => $section) {
                if ($section->getLayoutSettings()['label'] == 'Content Section') {
                  $sectionToUpdate = $key;
                }
              }
              $section_storage->getSection($sectionToUpdate)->unsetThirdPartySetting('layout_builder_lock', 'lock');
            }
          });

          $node->save();
        }
      }
    }
  }

  /**
   * Update existing events to disable adding more sections.
   */
  public function updateExistingEventsLock() {
    // Find all event nodes to update existing.
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->execute();

    foreach ($nids as $nid) {
      $node = Node::load($nid);
      $layout = $node->get('layout_builder__layout');

      /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
      $sections = $layout->getSections();
      foreach ($sections as $section) {
        if ($section->getLayoutSettings()['label'] != 'Title and Metadata') {
          $section->setThirdPartySetting(
            'layout_builder_lock',
            'lock',
            [
              6 => 6,
              7 => 7,
            ]
          );

          $this->updateTempStore(function (&$stored_data) {
            if ($stored_data->data['section_storage']->getContext('entity')->getContextData()->getEntity()->bundle() == 'event') {
              $section_storage = $stored_data->data['section_storage'];
              $sections = $section_storage->getSections();
              foreach ($sections as $key => $section) {
                if ($section->getLayoutSettings()['label'] != 'Title and Metadata') {
                  $sectionToUpdate = $key;
                }
              }
              if (isset($sectionToUpdate)) {
                $section_storage
                  ->getSection($sectionToUpdate)
                  ->setThirdPartySetting(
                    'layout_builder_lock',
                    'lock',
                    [
                      6 => 6,
                      7 => 7,
                    ]
                  );
              }
            }
          });

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

    if ($postViewDisplay = $entityTypeManager->getStorage('entity_view_display')->load('node.post.default')) {
      if ($postViewDisplay->isLayoutBuilderEnabled()) {
        $postSections = $postViewDisplay->getSections();
        foreach ($postSections as $section) {
          if ($section->getLayoutSettings()['label'] == 'Title and Metadata') {
            $this->postMetaSection = $section;
          }
        }
      }
    }

    if ($this->postMetaSection instanceof Section) {

      // Find all post nodes to update existing.
      $nids = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('type', 'post')->execute();

      foreach ($nids as $nid) {
        $node = Node::load($nid);
        $layout = $node->get('layout_builder__layout');
        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        $sections = $layout->getSections();

        // If there are no sections, this nid has the default layout, remove it.
        if (count($sections) === 0) {
          unset($nids[array_search($nid, $nids)]);
        }
        else {
          foreach ($sections as $section) {
            // If an overridden layout already contains an Page Meta section,
            // remove it from the update list.
            if ($section->getLayoutSettings()['label'] == 'Title and Metadata') {
              unset($nids[array_search($nid, $nids)]);
            }
          }
        }
      }

      foreach ($nids as $nid) {
        $node = Node::load($nid);
        $layout = $node->get('layout_builder__layout');

        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        // For existing pages, remove the old title and breadcrumb block first.
        $layout->removeSection(0);
        $layout->insertSection(0, $this->postMetaSection);

        $this->updateTempStore(function (&$stored_data) {

          if ($stored_data->data['section_storage']->getContext('entity')->getContextData()->getEntity()->bundle() == 'post') {
            $section_storage = $stored_data->data['section_storage'];
            $section_storage->removeSection(0);
            $section_storage->insertSection(0, $this->postMetaSection);
          }
        });

        $node->save();
      }
    }
  }

  /**
   * Updates Event Meta for existing nodes.
   */
  public function updateExistingEventMeta() {

    // Gets the main event meta section to clone.
    $entityTypeManager = \Drupal::service('entity_type.manager');

    if ($eventViewDisplay = $entityTypeManager->getStorage('entity_view_display')->load('node.event.default')) {
      if ($eventViewDisplay->isLayoutBuilderEnabled()) {
        $eventSections = $eventViewDisplay->getSections();
        foreach ($eventSections as $section) {
          if ($section->getLayoutSettings()['label'] == 'Title and Metadata') {
            $this->eventMetaSection = $section;
          }
        }
      }
    }

    if ($this->eventMetaSection instanceof Section) {

      // Find all event nodes to update existing.
      $nids = \Drupal::entityQuery('node')
        ->accessCheck(FALSE)
        ->condition('type', 'event')->execute();

      foreach ($nids as $nid) {
        $node = Node::load($nid);
        $layout = $node->get('layout_builder__layout');

        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        $sections = $layout->getSections();

        // If there are no sections, this nid has the default layout, remove it.
        if (count($sections) === 0) {
          unset($nids[array_search($nid, $nids)]);
        }
        else {
          foreach ($sections as $section) {

            // If an overridden layout already contains an Event Meta section,
            // remove it from the update list.
            if ($section->getLayoutSettings()['label'] == 'Title and Metadata') {
              unset($nids[array_search($nid, $nids)]);
            }
          }
        }
      }

      foreach ($nids as $nid) {
        $node = Node::load($nid);
        $layout = $node->get('layout_builder__layout');

        /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $layout */
        $layout->insertSection(0, $this->eventMetaSection);

        $this->updateTempStore(function (&$stored_data) {
          if ($stored_data->data['section_storage']->getContext('entity')->getContextData()->getEntity()->bundle() == 'event') {
            $section_storage = $stored_data->data['section_storage'];
            $section_storage->insertSection(0, $this->eventMetaSection);
          }
        });

        $node->save();
      }
    }
  }

  /**
   * Updates temp store.
   */
  public function updateTempStore(callable $process) {

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
        call_user_func_array($process, [&$stored_data]);
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
