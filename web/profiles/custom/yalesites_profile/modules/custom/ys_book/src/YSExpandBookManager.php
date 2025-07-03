<?php

namespace Drupal\ys_book;

use Drupal\book\BookManager;
use Drupal\book\BookOutlineStorageInterface;
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

/**
 * Overrides class for BookManager service.
 */
class YSExpandBookManager extends BookManager {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

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
    parent::__construct($entity_type_manager, $translation, $config_factory, $book_outline_storage, $renderer, $language_manager, $entity_repository, $backend_chained_cache, $memory_cache);
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public function bookTreeAllDataBreak($bid, $link = NULL, $max_depth = NULL, $start_level = NULL, $always_expand = 0) {

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

      // Generally we only deal with visible links, but just in case.
      if (!$data['link']['access']) {
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
   * {@inheritdoc}
   */
  public function bookLinkTranslate(&$link) {
    // Check access via the api, since the query node_access tag doesn't check
    // for unpublished nodes.
    // @todo load the nodes en-mass rather than individually.
    // @see https://www.drupal.org/project/drupal/issues/2470896
    $node = $this->entityTypeManager->getStorage('node')->load($link['nid']);

    // This is the custom check that we are overriding. By default, content that
    // fails an access check will not be included in the book tree. We want to
    // include it, and add a flag so that the template can add a lock icon to
    // the menu item. Access will still be checked when the user attempts to
    // view the node.
    if ($this->configFactory->get('ys_core.header_settings')->get('enable_cas_menu_links')) {
      $link['access'] = $node && TRUE;
      $link['is_cas'] = $node && !$node->access('view');
    }
    else {
      $link['access'] = $node && $node->access('view');
    }

    // For performance, don't localize a link the user can't access.
    if ($link['access']) {
      // The node label will be the value for the current language.
      $node = $this->entityRepository->getTranslationFromContext($node);
      $link['title'] = $node->label();
      $link['options'] = [];
    }
    return $link;
  }

}
