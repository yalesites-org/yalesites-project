<?php

namespace Drupal\ys_layouts;

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
 * @see ys_layouts_element_info_alter()
 * @see \Drupal\ys_layouts\Controller\CloneBlockController
 */
class CloneBlockLinkBuilder implements TrustedCallbackInterface {

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
      static::attachCloneLinks($element['layout_builder'], $section_storage);
    }
    return $element;
  }

  /**
   * Recursively attaches the clone link to inline-block render arrays.
   *
   * @param array $build
   *   A render array to walk.
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage backing the layout, used to resolve block plugins.
   */
  protected static function attachCloneLinks(array &$build, SectionStorageInterface $section_storage): void {
    foreach (Element::children($build) as $key) {
      $params = $build[$key]['#contextual_links']['layout_builder_block']['route_parameters'] ?? NULL;
      if ($params !== NULL && static::isInlineBlock($section_storage, (int) $params['delta'], $params['uuid'])) {
        // Reuse the same route parameters the core group already supplies.
        $build[$key]['#contextual_links']['layout_builder_block_clone'] = [
          'route_parameters' => $params,
        ];
      }
      static::attachCloneLinks($build[$key], $section_storage);
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
