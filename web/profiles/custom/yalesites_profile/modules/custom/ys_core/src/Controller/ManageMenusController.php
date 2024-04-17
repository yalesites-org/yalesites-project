<?php

namespace Drupal\ys_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the content menu.
 *
 * Overrides the node/add type look and feel with the templated versions.
 */
class ManageMenusController extends ControllerBase {
  use StringTranslationTrait;

  /**
   * The menu link tree service.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $linkTree;

  /**
   * {@inheritdoc}
   */
  public function __construct(ContainerInterface $container) {
    $this->linkTree = $container->get('menu.link_tree');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container);
  }

  /**
   * Creates the menu page for users to drill down to the menu items.
   */
  public function menusPage() {
    $submenuItems = $this->getMenuItems('admin', ['system.admin', 'system.admin_content', 'ys_core.menu_menus']);
    $links = [];
    foreach ($submenuItems as $menu_link) {
      $link = $menu_link;
      $links[] = [
        'label' => $link['title'],
        'add_link' => Link::createFromRoute($link['title'], $link['url']->getRouteName(), $link['url']->getRouteParameters()),
        'description' => $link['url']->getOptions()['title'] ?? '',
      ];
    }

    // Set up the content.
    $content = [
      '#theme' => 'entity_add_list',
      '#title' => 'Manage menus',
      '#description' => $this->t('Manage different menus for YaleSites'),
      '#bundles' => $links,
    ];

    return $content;
  }

  /**
   * Get the menu items for a given menu name and submenu IDs.
   *
   * @param string $menu_name
   *   The machine name of the menu.
   * @param array $submenu_ids
   *   An array of menu link IDs that represent the submenu location.
   *
   * @return array
   *   An array of menu items.
   */
  protected function getMenuItems($menu_name, array $submenu_ids) {
    // Load the menu tree.
    $parameters = new MenuTreeParameters();
    $tree = $this->linkTree->load($menu_name, $parameters);

    // Apply manipulators to the tree.
    $manipulators = [
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ];
    $tree = $this->linkTree->transform($tree, $manipulators);

    // Build the menu links.
    $menu_links = $this->linkTree->build($tree);

    $current_menu_links = $menu_links['#items'];

    // Find the submenu items inside of menu_links.
    foreach ($submenu_ids as $id) {
      if (isset($current_menu_links[$id])) {
        $current_menu_links = $current_menu_links[$id]['below'];
      }
      else {
        // Submenu ID not found, return empty array.
        return [];
      }
    }

    return $current_menu_links;
  }

}
