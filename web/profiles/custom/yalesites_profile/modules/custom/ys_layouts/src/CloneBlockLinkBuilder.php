<?php

namespace Drupal\ys_layouts;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\layout_builder\Plugin\Block\InlineBlock;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Adds a "Clone" contextual link to inline blocks in Layout Builder.
 *
 * Core attaches the shared `layout_builder_block` contextual group (Configure /
 * Move / Remove) to every block. Cloning must be offered only on inline blocks
 * — reusable blocks are excluded (issue #190) — so rather than adding the link
 * to that shared group, this pre-render attaches a separate
 * `layout_builder_block_clone` group only to the components whose plugin is an
 * InlineBlock. Registered on the `layout_builder` render element, where the
 * loaded section storage is available to resolve each component's plugin.
 *
 * The contextual module caches each menu, CSRF token included, in
 * sessionStorage by contextual id. The group's metadata carries a short one-way
 * digest of the session's CSRF token, so the id changes whenever a cached
 * token would no longer validate: once for menus cached before the route
 * needed a token, and again when a new session gets a new seed. The element
 * varies by session so no one else's digest is ever served.
 *
 * @see ys_layouts_element_info_alter()
 * @see \Drupal\ys_layouts\Controller\CloneBlockController
 * @see \Drupal\ys_layouts\Render\DetachContextualOperations
 */
class CloneBlockLinkBuilder implements TrustedCallbackInterface {

  /**
   * The CSRF token value the session digest is derived from.
   *
   * Any fixed value works: the token's per-session part is the seed, and this
   * only has to stay the same for every block in the session.
   */
  protected const SESSION_DIGEST_VALUE = 'ys_layouts_clone_block_contextual';

  /**
   * Pre-render callback: attach the clone link to inline-block components.
   *
   * @param array $element
   *   The built `layout_builder` render element.
   *
   * @return array
   *   The element with clone contextual links attached to inline blocks.
   */
  public static function preRender(array $element): array {
    $section_storage = $element['#section_storage'] ?? NULL;
    if ($section_storage instanceof SectionStorageInterface && isset($element['layout_builder'])) {
      static::attachCloneLinks($element['layout_builder'], $section_storage, static::sessionDigest());
      $element['#cache']['contexts'][] = 'session';
    }
    return $element;
  }

  /**
   * A short one-way digest that changes whenever the session's CSRF seed does.
   *
   * @return string
   *   Eight URL-safe characters.
   */
  protected static function sessionDigest(): string {
    return substr(Crypt::hashBase64(\Drupal::csrfToken()->get(static::SESSION_DIGEST_VALUE)), 0, 8);
  }

  /**
   * Recursively attaches the clone link to inline-block render arrays.
   *
   * @param array $build
   *   A render array to walk.
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage backing the layout, used to resolve block plugins.
   * @param string $session_digest
   *   The session digest stamped into each clone group's metadata.
   */
  protected static function attachCloneLinks(array &$build, SectionStorageInterface $section_storage, string $session_digest): void {
    foreach (Element::children($build) as $key) {
      $params = $build[$key]['#contextual_links']['layout_builder_block']['route_parameters'] ?? NULL;
      if ($params !== NULL && static::isInlineBlock($section_storage, (int) $params['delta'], $params['uuid'])) {
        // Reuse the same route parameters the core group already supplies.
        $build[$key]['#contextual_links']['layout_builder_block_clone'] = [
          'route_parameters' => $params,
          // Part of the contextual id, so a menu cached with another session's
          // token, or with none, is fetched again. See the class docblock.
          'metadata' => ['csrf_session' => $session_digest],
        ];
      }
      static::attachCloneLinks($build[$key], $section_storage, $session_digest);
    }
  }

  /**
   * Determines whether a component is an inline block.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   * @param int $delta
   *   The section delta.
   * @param string $uuid
   *   The component UUID.
   *
   * @return bool
   *   TRUE if the component's plugin is an InlineBlock, FALSE otherwise
   *   (including reusable blocks and when the component cannot be resolved).
   */
  protected static function isInlineBlock(SectionStorageInterface $section_storage, int $delta, string $uuid): bool {
    try {
      $component = $section_storage->getSection($delta)->getComponent($uuid);
      return $component->getPlugin() instanceof InlineBlock;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRender'];
  }

}
