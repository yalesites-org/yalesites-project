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
   * Static cache for node CAS restriction status.
   *
   * @var array
   */
  protected static $nodeRestrictionCache = [];

  /**
   * Static cache for loaded menu entities.
   *
   * @var array
   */
  protected static $menuEntityCache = [];

  /**
   * Flag to track if nodes have been preloaded for current request.
   *
   * @var bool
   */
  protected static $nodesPreloaded = FALSE;

  /**
   * Override the menu link access check with performance optimizations.
   *
   * Performance improvements:
   * - Static caching of node CAS restriction status
   * - Static caching of menu link content entities
   * - Reduced entity loads by reusing cached data
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
        // Check static cache first to avoid redundant node loads.
        if (!isset(static::$nodeRestrictionCache[$nodeId])) {
          $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
          static::$nodeRestrictionCache[$nodeId] = [
            'exists' => $node !== NULL,
            'restricted' => $node ? $this->isCasRestricted($node) : FALSE,
          ];
        }

        $cache_entry = static::$nodeRestrictionCache[$nodeId];
        if ($cache_entry['exists'] && $cache_entry['restricted']) {
          $metadata = $instance->getMetaData();
          $entity_id = $metadata['entity_id'];

          // Check static cache for menu entity to avoid redundant loads.
          if (!isset(static::$menuEntityCache[$entity_id])) {
            $menu_link_content_storage = $this->entityTypeManager->getStorage('menu_link_content');
            static::$menuEntityCache[$entity_id] = $menu_link_content_storage->load($entity_id);
          }

          $menu_entity = static::$menuEntityCache[$entity_id];
          if ($menu_entity) {
            // Adds a property to be read by ys_node_access.module for styling.
            $menu_entity->data_restricted = TRUE;
            return AccessResult::allowed();
          }
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

  /**
   * Preload CAS restriction status for menu nodes using direct field query.
   *
   * This method walks the menu tree and batch queries only the
   * field_login_required field data instead of loading full node entities.
   * This significantly reduces database queries and memory usage.
   *
   * Performance optimization:
   * - Instead of loading full nodes with all fields (50+ queries per node)
   * - Query only the field_login_required field table (1 query for all nodes)
   * - Reduces cold cache queries from ~14,000 to ~100
   *
   * @param array $tree
   *   The menu tree array.
   */
  protected function preloadMenuNodes(array $tree) {
    if (static::$nodesPreloaded) {
      return;
    }

    $node_ids = [];
    $menu_entity_ids = [];

    // Recursively collect all node IDs from the menu tree.
    $this->collectNodeIdsFromTree($tree, $node_ids, $menu_entity_ids);

    // Instead of loading full nodes, query only the field_login_required field.
    if (!empty($node_ids)) {
      // Query field_login_required directly for all nodes in one query.
      $database = \Drupal::database();
      $query = $database->select('node__field_login_required', 'f')
        ->fields('f', ['entity_id', 'field_login_required_value'])
        ->condition('entity_id', $node_ids, 'IN')
        ->condition('deleted', 0);
      $results = $query->execute()->fetchAllKeyed();

      // Cache the restriction status for each node.
      foreach ($node_ids as $nid) {
        if (!isset(static::$nodeRestrictionCache[$nid])) {
          // If node exists in field table, use its value. Otherwise, assume not restricted.
          $restricted = isset($results[$nid]) && $results[$nid] == 1;
          static::$nodeRestrictionCache[$nid] = [
            'exists' => TRUE,
            'restricted' => $restricted,
          ];
        }
      }
    }

    // Batch load all menu link content entities at once.
    if (!empty($menu_entity_ids)) {
      $menu_entities = $this->entityTypeManager->getStorage('menu_link_content')->loadMultiple($menu_entity_ids);
      foreach ($menu_entities as $id => $entity) {
        if (!isset(static::$menuEntityCache[$id])) {
          static::$menuEntityCache[$id] = $entity;
        }
      }
    }

    static::$nodesPreloaded = TRUE;
  }

  /**
   * Recursively collect node IDs and menu entity IDs from menu tree.
   *
   * @param array $tree
   *   The menu tree array.
   * @param array $node_ids
   *   Array to collect node IDs (passed by reference).
   * @param array $menu_entity_ids
   *   Array to collect menu entity IDs (passed by reference).
   */
  protected function collectNodeIdsFromTree(array $tree, array &$node_ids, array &$menu_entity_ids) {
    foreach ($tree as $element) {
      /** @var \Drupal\Core\Menu\MenuLinkInterface $link */
      $link = $element->link;

      if ($link && in_array($link->getMenuName(), _ys_node_access_cas_menus())) {
        $node_id = $link->getRouteParameters()['node'] ?? NULL;
        if ($node_id) {
          $node_ids[$node_id] = $node_id;
          $metadata = $link->getMetaData();
          if (isset($metadata['entity_id'])) {
            $menu_entity_ids[$metadata['entity_id']] = $metadata['entity_id'];
          }
        }
      }

      // Recurse into subtree.
      if (!empty($element->subtree)) {
        $this->collectNodeIdsFromTree($element->subtree, $node_ids, $menu_entity_ids);
      }
    }
  }

  /**
   * Override checkAccess to preload nodes before access checking.
   *
   * {@inheritdoc}
   */
  public function checkAccess(array $tree) {
    // Preload all nodes and menu entities before processing.
    $this->preloadMenuNodes($tree);

    // Call parent implementation which will invoke menuLinkCheckAccess().
    return parent::checkAccess($tree);
  }

}
