<?php

namespace Drupal\ys_node_access\Menu;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResult;
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
   */
  public function __construct(
    AccessManagerInterface $access_manager,
    AccountInterface $account,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler = NULL
  ) {
    parent::__construct($access_manager, $account, $entity_type_manager, $module_handler);
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
    $menusToCheck = [
      'main',
      'utility-navigation',
      'utility-drop-button-navigation',
      'footer',
    ];

    $menuName = $instance->getMenuName();
    if (in_array($menuName, $menusToCheck)) {
      dpm(get_class_methods($instance));
      dpm($instance->getMetaData());
      $metadata = $instance->getMetaData();
      // todo - set metadata on this link item, then possibly use preprocess to add the data attribute there?

      //If the user is anonymous, override access to always allow visibility.
      if ($this->account->isAnonymous()) {
        return AccessResult::allowed();
      }
    }

    $access_result = parent::menuLinkCheckAccess($instance);

    return $access_result;
  }

}
