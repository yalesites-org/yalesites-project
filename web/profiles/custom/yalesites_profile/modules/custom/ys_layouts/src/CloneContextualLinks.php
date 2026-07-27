<?php

namespace Drupal\ys_layouts;

use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Decides which Layout Builder blocks offer the "Clone block" contextual link.
 *
 * Contextual link plugins are registered per group, not per block, so the
 * Clone link is offered to every block in the layout_builder_block group and
 * then removed again for the blocks that cannot be cloned. The decision is
 * taken once per block while the Layout Builder element is pre-rendered (where
 * the unsaved section storage is already loaded) and recorded in the block's
 * contextual-links metadata; ys_layouts_contextual_links_view_alter() then
 * drops the link for any block that was not marked.
 *
 * Stamping the metadata does double duty: the metadata is part of the
 * client-side contextual id, so adding a key busts the browser's cached
 * contextual menus and editors actually see the new link.
 *
 * @see layout_builder_lock_contextual_links_view_alter()
 * @see \Drupal\layout_builder\Element\LayoutBuilder::buildAdministrativeSection()
 * @see _contextual_links_to_id()
 */
class CloneContextualLinks implements TrustedCallbackInterface {

  /**
   * The contextual-links metadata key marking a block as clonable.
   */
  const METADATA_KEY = 'ys_layouts_clone';

  /**
   * The id of this module's contextual link plugin.
   *
   * Implementations of hook_contextual_links_view_alter() receive $items keyed
   * by plugin id.
   *
   * @see ys_layouts.links.contextual.yml
   */
  const PLUGIN_ID = 'ys_layouts_clone_block';

  /**
   * The key the Clone link has in $element['#links'].
   *
   * ContextualLinks::preRenderLinks() runs the plugin id through
   * Html::getClass().
   */
  const LINK_KEY = 'ys-layouts-clone-block';

  /**
   * Pre-render callback that marks clonable blocks in the layout.
   *
   * Runs after layout_builder_lock's own pre-render (module hook order puts
   * "layout_builder_lock" before "ys_layouts"), so a region whose "Add block"
   * link has been removed by a lock is already recognisable here and is not
   * offered Clone either — otherwise cloning would be a way to add a block to
   * a locked region.
   *
   * @param array $element
   *   The Layout Builder render element.
   *
   * @return array
   *   The modified Layout Builder render element.
   */
  public static function preRender(array $element): array {
    $section_storage = $element['#section_storage'] ?? NULL;
    if (!$section_storage instanceof SectionStorageInterface
      || empty($element['layout_builder'])) {
      return $element;
    }

    $cloner = \Drupal::service('ys_layouts.block_cloner');

    foreach (Element::children($element['layout_builder']) as $index) {
      foreach (Element::children($element['layout_builder'][$index]) as $name) {
        $section = &$element['layout_builder'][$index][$name];
        // Only the layout render array holds regions full of blocks; its
        // siblings are the configure/remove section links.
        if (!isset($section['#layout'])) {
          continue;
        }

        foreach (Element::children($section) as $region) {
          // A region that no longer accepts new blocks must not accept clones.
          $region_is_open = isset($section[$region]['layout_builder_add_block']);

          foreach (Element::children($section[$region]) as $key) {
            $block = &$section[$region][$key];
            if (!isset($block['#contextual_links']['layout_builder_block'])) {
              continue;
            }

            $links = &$block['#contextual_links']['layout_builder_block'];
            $component = static::getComponent($section_storage, $links);
            if ($region_is_open
              && $component
              && $cloner->isClonable($component)) {
              $links['metadata'][static::METADATA_KEY] = '1';
            }
            unset($links);
          }
        }
        unset($section);
      }
    }

    return $element;
  }

  /**
   * Determines whether the Clone link should be kept for a block.
   *
   * @param array $item
   *   The ys_layouts_clone_block contextual-link item, as found in the $items
   *   passed to hook_contextual_links_view_alter(). Items are keyed by plugin
   *   id and each one carries the metadata of its group.
   *
   * @return bool
   *   TRUE if the block was marked clonable during pre-render.
   */
  public static function shouldKeepLink(array $item): bool {
    return !empty($item['metadata'][static::METADATA_KEY]);
  }

  /**
   * Loads the component a contextual-links item points at.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage being rendered.
   * @param array $links
   *   The layout_builder_block contextual-links definition, whose route
   *   parameters identify the section delta and the component UUID.
   *
   * @return \Drupal\layout_builder\SectionComponent|null
   *   The component, or NULL if it cannot be found.
   */
  protected static function getComponent(
    SectionStorageInterface $section_storage,
    array $links,
  ) {
    $parameters = $links['route_parameters'] ?? [];
    if (!isset($parameters['delta'], $parameters['uuid'])) {
      return NULL;
    }

    try {
      return $section_storage->getSection((int) $parameters['delta'])
        ->getComponent((string) $parameters['uuid']);
    }
    catch (\OutOfBoundsException | \InvalidArgumentException $e) {
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRender'];
  }

}
