<?php

namespace Drupal\ys_node_access\Menu;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Menu\DefaultMenuLinkTreeManipulators;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\node\NodeInterface;

/**
 * Custom menu link tree manipulator to override access checks.
 */
class YsNodeAccessLinkTreeManipulator extends DefaultMenuLinkTreeManipulators {

  /**
   * Override the menu link access check.
   *
   * @param \Drupal\Core\Menu\MenuLinkInterface $instance
   *   The menu link instance.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  protected function menuLinkCheckAccess(MenuLinkInterface $instance) {
    /*
     * Allows anonymous users to see CAS only links.
     */
    $access_result = parent::menuLinkCheckAccess($instance);

    $menuName = $instance->getMenuName();
    if (in_array($menuName, _ys_node_access_cas_menus())) {
      $nodeId = $instance->getRouteParameters()['node'] ?? NULL;

      if ($nodeId) {
        $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
        if ($node && $this->isCasRestricted($node)) {
          $metadata = $instance->getMetaData();
          $menu_link_content_storage = $this->entityTypeManager->getStorage('menu_link_content');
          $menu_entity = $menu_link_content_storage->load($metadata['entity_id']);

          // Adds a property to be read by ys_node_access.module for styling.
          $menu_entity->data_restricted = TRUE;
          return AccessResult::allowed();
        }
      }
    }

    return $access_result;
  }

  /**
   * Checks if the node is CAS restricted.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to check.
   *
   * @return bool
   *   True if the node is CAS restricted, false otherwise.
   */
  protected function isCasRestricted(NodeInterface $node) {
    return (
      $node->hasField('field_login_required') &&
      $node->get('field_login_required')->value == 1
    );
  }

}
