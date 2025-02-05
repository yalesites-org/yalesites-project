<?php

namespace Drupal\ys_node_access\Menu;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Menu\DefaultMenuLinkTreeManipulators;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Custom menu link tree manipulator to override access checks.
 */
class YsNodeAccessLinkTreeManipulator extends DefaultMenuLinkTreeManipulators {

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $headerSettings;

  /**
   * Constructs a CustomMenuLinkTreeManipulator object.
   *
   * @param \Drupal\Core\Access\AccessManagerInterface $access_manager
   *   The access manager.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface|null $module_handler
   *   The module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function __construct(
    AccessManagerInterface $access_manager,
    AccountInterface $account,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler = NULL,
    ConfigFactoryInterface $config_factory
  ) {
    parent::__construct($access_manager, $account, $entity_type_manager, $module_handler);
    $this->headerSettings = $config_factory->get('ys_core.header_settings');
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
    /*
     * If set in header_settings config, allows anonymous users to see CAS
     * only links.
     */
    $access_result = parent::menuLinkCheckAccess($instance);
    if (!$this->headerSettings->get('enable_cas_menu_links')) {
      return $access_result;
    }

    $menuName = $instance->getMenuName();
    if (in_array($menuName, _ys_node_access_cas_menus())) {
      if ($this->account->isAnonymous()) {
        if (!$access_result->isAllowed()) {
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

}
