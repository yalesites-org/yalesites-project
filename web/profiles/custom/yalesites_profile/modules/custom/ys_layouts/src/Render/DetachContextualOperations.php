<?php

declare(strict_types=1);

namespace Drupal\ys_layouts\Render;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Registers the detach action in Layout Builder's contextual link metadata.
 *
 * Core stamps an "operations" list into the metadata of every
 * layout_builder_block contextual link, and that metadata becomes part of the
 * link's contextual id:
 * @code
 * // Add metadata about the current operations available in contextual links.
 * // This will invalidate the client-side cache of links that were cached
 * // before the 'move' link was added.
 * 'metadata' => ['operations' => 'move:update:remove'],
 * @endcode
 * (\Drupal\layout_builder\Element\LayoutBuilder::buildAdministrativeSection()).
 *
 * That indirection matters because the contextual module caches each id's
 * rendered links in the browser's sessionStorage and, on a cache hit, renders
 * from it without contacting the server at all - invalidating only when the
 * user's permissions hash changes (core/modules/contextual/js/contextual.js).
 * So adding "Make non-reusable" to core's group without extending the
 * operations list leaves the id byte-identical to the pre-feature id, and every
 * browser that already opened that block's contextual menu keeps replaying the
 * old three-link menu indefinitely. No amount of server-side cache clearing
 * reaches it.
 *
 * Appending our operation changes the id exactly once, which is the same
 * mechanism core used when it introduced its own "move" link.
 */
class DetachContextualOperations implements TrustedCallbackInterface {

  /**
   * The operation name appended to core's contextual link metadata.
   */
  protected const DETACH_OPERATION = 'detach';

  /**
   * The contextual link group core uses for placed blocks.
   */
  protected const CONTEXTUAL_GROUP = 'layout_builder_block';

  /**
   * Registers the detach operation on every placed block in the layout.
   *
   * Runs as a #pre_render on the layout_builder element (added in
   * ys_layouts_element_info_alter()), so it sees the built sections before the
   * theme layer turns #contextual_links into a contextual id.
   *
   * @param array $element
   *   The layout_builder render element.
   *
   * @return array
   *   The element with the detach operation registered.
   */
  public static function addDetachOperation(array $element): array {
    static::registerRecursively($element);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['addDetachOperation'];
  }

  /**
   * Adds the operation to this element and every child that carries the group.
   *
   * The contextual links live on components nested under section and region
   * keys, so the whole subtree is walked rather than a fixed depth assumed.
   *
   * @param array $element
   *   The render array to process, by reference.
   */
  protected static function registerRecursively(array &$element): void {
    $group = static::CONTEXTUAL_GROUP;
    // Read through a copy: taking a reference to a nested key would create the
    // whole path on elements that carry no contextual links at all.
    $metadata = $element['#contextual_links'][$group]['metadata'] ?? NULL;
    if (isset($metadata['operations'])) {
      $operations = explode(':', $metadata['operations']);
      if (!in_array(static::DETACH_OPERATION, $operations, TRUE)) {
        $operations[] = static::DETACH_OPERATION;
        $metadata['operations'] = implode(':', $operations);
        $element['#contextual_links'][$group]['metadata'] = $metadata;
      }
    }

    // Walked directly rather than through Element::children(), which throws on
    // a non-array child and re-sorts its argument in place. Neither is wanted
    // here: this walks the whole built tree, including subtrees the renderer
    // itself never validates, so one malformed child would take down the entire
    // Layout Builder UI instead of just omitting a link.
    foreach ($element as $key => &$child) {
      if (is_array($child) && (is_int($key) || $key === '' || $key[0] !== '#')) {
        static::registerRecursively($child);
      }
    }
  }

}
