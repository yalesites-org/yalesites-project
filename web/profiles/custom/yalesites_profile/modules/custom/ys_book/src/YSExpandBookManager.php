<?php

namespace Drupal\ys_book;

use Drupal\Core\Url;
use Drupal\Core\Template\Attribute;
use Drupal\node\NodeInterface;
use Drupal\custom_book_block\ExpandBookManager;
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

/**
 * Extends ExpandBookManager to include CAS-protected content in navigation.
 */
class YSExpandBookManager extends ExpandBookManager {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs an YSExpandBookManager object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, TranslationInterface $translation, ConfigFactoryInterface $config_factory, BookOutlineStorageInterface $book_outline_storage, RendererInterface $renderer, LanguageManagerInterface $language_manager, EntityRepositoryInterface $entity_repository, CacheBackendInterface $backend_chained_cache, CacheBackendInterface $memory_cache, RouteMatchInterface $route_match) {
    parent::__construct($entity_type_manager, $translation, $config_factory, $book_outline_storage, $renderer, $language_manager, $entity_repository, $backend_chained_cache, $memory_cache, $route_match);
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public function bookLinkTranslate(&$link) {
    // Load the node to check access.
    $node = $this->entityTypeManager->getStorage('node')->load($link['nid']);

    // Override default behavior: include all pages but flag CAS-protected ones.
    $link['access'] = TRUE;
    $link['is_cas'] = $node && !$node->access('view');

    // Set the localized title.
    if ($node) {
      $node = $this->entityRepository->getTranslationFromContext($node);
      $link['title'] = $node->label();
    }

    $link['options'] = [];

    return $link;
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

      // IMPORTANT: Pass through the is_cas flag we set in bookLinkTranslate.
      if (isset($data['link']['is_cas']) && $data['link']['is_cas']) {
        $element['is_cas'] = TRUE;
      }

      // Allow book-specific theme overrides.
      $element['attributes'] = new Attribute();
      $element['title'] = $data['link']['title'];

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

}
