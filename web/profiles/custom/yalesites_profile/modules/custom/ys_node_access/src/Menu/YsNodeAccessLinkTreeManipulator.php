<?php

namespace Drupal\ys_node_access\Menu;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Database\Connection;
use Drupal\Core\Menu\DefaultMenuLinkTreeManipulators;
use Drupal\Core\Menu\MenuLinkInterface;

/**
 * Custom menu link tree manipulator to override access checks.
 */
class YsNodeAccessLinkTreeManipulator extends DefaultMenuLinkTreeManipulators {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  public function __construct($access_manager, $account, $entity_type_manager, $module_handler, Connection $database) {
    parent::__construct($access_manager, $account, $entity_type_manager, $module_handler);
    $this->database = $database;
  }

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
    static $casRestrictedNodes = [];

    $access_result = parent::menuLinkCheckAccess($instance);

    $menuName = $instance->getMenuName();
    if (in_array($menuName, _ys_node_access_cas_menus())) {
      $nodeId = $instance->getRouteParameters()['node'] ?? NULL;

      if ($nodeId) {
        if (!isset($casRestrictedNodes[$nodeId])) {
          $query = $this->database->select('node__field_login_required', 'lr')
            ->fields('lr', ['field_login_required_value'])
            ->condition('lr.entity_id', $nodeId)
            ->condition('lr.deleted', 0)
            ->execute();

          $casRestrictedNodes[$nodeId] = ($query->fetchField() == 1);
        }

        if ($casRestrictedNodes[$nodeId]) {
          $metadata = $instance->getMetaData();
          $menu_link_content_storage = $this->entityTypeManager->getStorage('menu_link_content');
          $menu_entity = $menu_link_content_storage->load($metadata['entity_id']);

          $menu_entity->data_restricted = TRUE;
          return AccessResult::allowed();
        }
      }
    }

    return $access_result;
  }

}
