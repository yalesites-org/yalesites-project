<?php

namespace Drupal\ys_book;

use Drupal\custom_book_block\ExpandBookManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\book\BookOutlineStorageInterface;

/**
 * Overrides class for custom_book_block ExpandBookManager service.
 *
 * Extends the custom_book_block ExpandBookManager to add CAS functionality
 * while maintaining compatibility with the custom_book_navigation block.
 */
class YsExpandBookManager extends ExpandBookManager {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Static cache for preloaded book nodes.
   *
   * @var array
   */
  protected static $bookNodesCache = [];

  /**
   * Flag to track if book nodes have been preloaded.
   *
   * @var array
   */
  protected static $bookNodesPreloaded = [];

  /**
   * Constructs an ExpandBookManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\book\BookOutlineStorageInterface $book_outline_storage
   *   The book outline storage.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $backend_chained_cache
   *   The book chained backend cache service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $memory_cache
   *   The book memory cache service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, TranslationInterface $translation, ConfigFactoryInterface $config_factory, BookOutlineStorageInterface $book_outline_storage, RendererInterface $renderer, LanguageManagerInterface $language_manager, EntityRepositoryInterface $entity_repository, CacheBackendInterface $backend_chained_cache, CacheBackendInterface $memory_cache, RouteMatchInterface $route_match) {
    parent::__construct($entity_type_manager, $translation, $config_factory, $book_outline_storage, $renderer, $language_manager, $entity_repository, $backend_chained_cache, $memory_cache, $route_match);
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public function bookTreeAllData($bid, $link = NULL, $max_depth = NULL, $start_level = NULL, $always_expand = 0) {

    $tree = &drupal_static(__METHOD__, []);
    $language_interface = $this->languageManager->getCurrentLanguage();

    // Use $nid as a flag for whether the data being loaded is for the whole
    // tree.
    $nid = $link['nid'] ?? 0;

    // Generate a cache ID (cid) specific for this $bid, $link, $language, and
    // depth.
    $cid = 'book-links:' . $bid . ':all:' . $nid . ':' . $language_interface->getId() . ':' . (int) $max_depth;

    if (!isset($tree[$cid])) {
      // If the tree data was not in the static cache, build $tree_parameters.
      $tree_parameters = [
        'min_depth' => $start_level ?? 1,
        'max_depth' => $max_depth,
      ];

      if ($nid) {
        $active_trail = $this->getActiveTrailIds((string) $bid, $link);

        // Setting the 'expanded' value to $active_trail would be same as core.
        if ($always_expand) {
          $tree_parameters['expanded'] = [];
        }
        else {
          $tree_parameters['expanded'] = $active_trail;
        }
        $tree_parameters['active_trail'] = $active_trail;
        $tree_parameters['active_trail'][] = $nid;
      }

      if ($start_level && $start_level > 1) {
        $book_link = $this->loadBookLink($nid);
        if (!empty($book_link['p' . $start_level]) && $book_link['p' . $start_level] > 0) {
          $tree_parameters['conditions']['p' . $start_level] = $book_link['p' . $start_level];
        }
      }

      // Build the tree using the parameters; the resulting tree will be cached.
      $tree[$cid] = $this->bookTreeBuild($bid, $tree_parameters);
    }

    return $tree[$cid];
  }

  /**
   * {@inheritdoc}
   */
  protected function buildItems(array $tree) {

    $items = [];
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $node = $this->routeMatch->getParameter('node');

    foreach ($tree as $data) {
      $element = [];

      // Check if link data exists and is accessible.
      if (!isset($data['link']) || !$data['link']['access']) {
        continue;
      }

      // Set a class for the <li> tag. Since $data['below'] may contain local
      // tasks, only set 'expanded' to true if the link also has children within
      // the current book.
      $element['is_expanded'] = FALSE;
      $element['is_collapsed'] = FALSE;
      if ($data['link']['has_children'] && $data['below']) {
        $element['is_expanded'] = TRUE;
      }
      elseif ($data['link']['has_children']) {
        $element['is_collapsed'] = TRUE;
      }

      // Set a helper variable to indicate whether the link is in the active
      // trail.
      $element['in_active_trail'] = FALSE;
      if ($data['link']['in_active_trail']) {
        $element['in_active_trail'] = TRUE;
      }

      // Set a helper variable to indicate whether the link is the active link.
      $element['is_active'] = FALSE;
      if (($node instanceof NodeInterface) && $data['link']['nid'] === $node->id()) {
        $element['is_active'] = TRUE;
      }

      // Allow book-specific theme overrides.
      $element['attributes'] = new Attribute();
      $element['title'] = $data['link']['title'];

      if (isset($data['link']['is_cas']) && $data['link']['is_cas']) {
        $element['is_cas'] = TRUE;
      }

      $element['url'] = Url::fromUri('entity:node/' . $data['link']['nid'], [
        'langcode' => $langcode,
      ]);

      $element['localized_options'] = !empty($data['link']['localized_options']) ? $data['link']['localized_options'] : [];
      $element['localized_options']['set_active_class'] = TRUE;
      $element['below'] = $data['below'] ? $this->buildItems($data['below']) : [];
      $element['original_link'] = $data['link'];

      // Index using the link's unique nid.
      $items[$data['link']['nid']] = $element;
    }

    return $items;
  }

  /**
   * Override bookTreeBuild to bypass access filtering.
   *
   * This allows CAS-protected pages to be included in the book tree,
   * where they will be flagged with is_cas by bookLinkTranslate().
   */
  protected function bookTreeBuild($bid, array $parameters = []) {
    // Build the book tree.
    $data = $this->doBookTreeBuild($bid, $parameters);
    // Translate links but skip access filtering that removes CAS-protected
    // nodes. Pass $bid to enable batch loading.
    $this->doBookTreeTranslateLinks($data['tree'], $bid);
    return $data['tree'];
  }

  /**
   * Preload all book nodes from tree to avoid N+1 queries.
   *
   * Walks the tree and batch loads all nodes at once.
   *
   * @param array $tree
   *   The book tree.
   * @param int $bid
   *   The book ID for cache keying.
   */
  protected function preloadBookNodes(array &$tree, $bid) {
    // Check if we've already preloaded nodes for this book.
    if (isset(static::$bookNodesPreloaded[$bid])) {
      return;
    }

    // Collect all node IDs from the tree.
    $nids = [];
    $this->collectNodeIds($tree, $nids);

    // Batch load all nodes at once.
    if (!empty($nids)) {
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
      foreach ($nodes as $nid => $node) {
        static::$bookNodesCache[$nid] = $node;
      }
    }

    static::$bookNodesPreloaded[$bid] = TRUE;
  }

  /**
   * Recursively collect node IDs from book tree.
   *
   * @param array $tree
   *   The book tree.
   * @param array $nids
   *   Array to collect node IDs (passed by reference).
   */
  protected function collectNodeIds(array $tree, array &$nids) {
    foreach ($tree as $item) {
      if (isset($item['link']['nid'])) {
        $nids[$item['link']['nid']] = $item['link']['nid'];
      }
      if (!empty($item['below'])) {
        $this->collectNodeIds($item['below'], $nids);
      }
    }
  }

  /**
   * Translate book links without access filtering.
   *
   * Similar to doBookTreeCheckAccess but doesn't filter out inaccessible items.
   * Now preloads all nodes before translation to avoid N+1 queries.
   */
  protected function doBookTreeTranslateLinks(&$tree, $bid = NULL) {
    // Preload all nodes in the tree before translation.
    if ($bid !== NULL) {
      $this->preloadBookNodes($tree, $bid);
    }

    $new_tree = [];
    foreach ($tree as $key => $v) {
      $item = &$tree[$key]['link'];
      $this->bookLinkTranslate($item);
      // Always include the item (don't check $item['access']).
      if ($tree[$key]['below']) {
        $this->doBookTreeTranslateLinks($tree[$key]['below']);
      }
      // The weights are made a uniform 5 digits by adding 50000 as an offset.
      // After calling bookLinkTranslate(), $item['title'] has the translated
      // title.
      // Adding the nid to the end of the index ensures that it is unique.
      $new_tree[(50000 + $item['weight']) . ' ' . $item['title'] . ' ' . $item['nid']] = $tree[$key];
    }
    // Sort siblings in the tree based on the weights and localized titles.
    ksort($new_tree);
    $tree = $new_tree;
  }

  /**
   * {@inheritdoc}
   *
   * Performance optimization: Uses preloaded nodes from static cache instead
   * of loading individually. Resolves N+1 query problem in book navigation.
   */
  public function bookLinkTranslate(&$link) {
    // Check access via the api, since the query node_access tag doesn't check
    // for unpublished nodes.
    // Performance improvement: Check static cache first before loading.
    $nid = $link['nid'];
    if (isset(static::$bookNodesCache[$nid])) {
      $node = static::$bookNodesCache[$nid];
    }
    else {
      // Fallback to individual load if not in cache (shouldn't happen after
      // preload, but provides safety).
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if ($node) {
        static::$bookNodesCache[$nid] = $node;
      }
    }

    // Override default access check behavior. By default, content that
    // fails an access check will not be included in the book tree. We want to
    // include it, and add a flag so that the template can add a lock icon to
    // the menu item. Access will still be checked when the user attempts to
    // view the node.
    $link['access'] = TRUE;
    // Check the field_login_required field instead of access check to avoid
    // cache-related issues between environments.
    $link['is_cas'] = $node && $node->hasField('field_login_required') && (bool) $node->get('field_login_required')->value;

    // Localize the link since we always set access to TRUE.
    // The node label will be the value for the current language.
    $node = $this->entityRepository->getTranslationFromContext($node);
    $link['title'] = $node->label();
    $link['options'] = [];
    return $link;
  }

}
