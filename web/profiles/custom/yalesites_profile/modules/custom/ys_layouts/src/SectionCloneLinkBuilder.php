<?php

namespace Drupal\ys_layouts;

use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Url;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Adds a "Clone" action to the Layout Builder section toolbar.
 *
 * Sections have no contextual-link group to join: core emits their Configure
 * and Remove actions as plain links from
 * LayoutBuilder::buildAdministrativeSection(), so unlike the block-level
 * "Clone" this cannot be declared in ys_layouts.links.contextual.yml. It is
 * injected into the built section render array instead, the same way
 * section_library adds its own section links (issue #1638).
 *
 * Identity is taken from the element's own section storage and from the
 * `data-layout-delta` attribute core stamps on the section body, rather than
 * reverse-engineered from a sibling action link. Sibling links are conditional:
 * core gates `configure` on the layout having a settings form, and
 * layout_builder_lock unsets `remove` on any locked section for users without
 * `remove sections with lock settings`. Keying off either would make this
 * action appear and disappear for reasons that have nothing to do with whether
 * cloning is allowed. Whether it is allowed is decided in one place — the
 * route's access check — and surfaced here through `#access`.
 *
 * @see ys_layouts_element_info_alter()
 * @see \Drupal\ys_layouts\Controller\CloneSectionController
 * @see \Drupal\ys_layouts\Access\CloneSectionAccessCheck
 * @see \Drupal\section_library\SectionLibraryRender::preRender()
 */
class SectionCloneLinkBuilder implements TrustedCallbackInterface {

  /**
   * Pre-render callback: add the clone link to every built section.
   *
   * @param array $element
   *   The built `layout_builder` render element.
   *
   * @return array
   *   The element with a clone link on each section the user may clone.
   */
  public static function preRender(array $element): array {
    $section_storage = $element['#section_storage'] ?? NULL;
    if (!isset($element['layout_builder']) || !$section_storage instanceof SectionStorageInterface) {
      return $element;
    }

    $element['#attached']['library'][] = 'ys_layouts/clone_section';

    foreach ($element['layout_builder'] as $key => $section) {
      if (!is_numeric($key)) {
        continue;
      }

      // Only a built section carries a body stamped with its delta; the "add
      // section" placeholders do not, and must not gain a clone action.
      $delta = $section['layout-builder__section']['#attributes']['data-layout-delta'] ?? NULL;
      if ($delta === NULL) {
        continue;
      }
      $delta = (int) $delta;

      $url = Url::fromRoute(
        'ys_layouts.clone_section',
        [
          'section_storage_type' => $section_storage->getStorageType(),
          'section_storage' => $section_storage->getStorageId(),
          'delta' => $delta,
        ],
        [
          'attributes' => [
            'class' => [
              // No data-dialog-* attributes: cloning needs no confirmation
              // step, so the response rebuilds the layout in place rather than
              // opening the off-canvas dialog Configure and Remove use.
              'use-ajax',
              'layout-builder__link',
              'layout-builder__link--clone',
            ],
          ],
        ]
      );

      // Computed once: it decides both whether the link renders and whether the
      // stylesheet shifts Configure aside to make room for it.
      $can_clone = $url->access();

      $link = [
        '#type' => 'link',
        // Named per section, matching the accessible names core gives the
        // Configure and Remove actions, so a screen reader can tell the
        // sections' actions apart. The label is placed off-screen by the
        // stylesheet, the same way core's Remove action hides its own.
        '#title' => t('Clone @section', [
          '@section' => static::sectionLabel($section, $delta),
        ]),
        '#url' => $url,
        // The route decides; the link only reflects it. This is what stops a
        // locked section advertising an action that would be refused.
        '#access' => $can_clone,
      ];

      $section = static::insertClone($section, $link);

      // The 20px slot the stylesheet clears for Clone must only be cleared on
      // the sections that actually get one, or a refused section would show an
      // empty gap where Configure used to sit.
      if ($can_clone) {
        $section['#attributes']['class'][] = 'ys-has-clone-action';
      }

      $element['layout_builder'][$key] = $section;
    }

    return $element;
  }

  /**
   * Returns the section's children with the clone link in toolbar order.
   *
   * The stylesheet lays the section actions out as Remove, Clone, Configure
   * across the top edge of the section, so the clone link is placed
   * immediately before `configure` rather than appended: a link that reads
   * second but receives focus third is a focus-order defect (WCAG 2.4.3), and
   * appending it after the whole set would produce exactly that.
   *
   * The `configure` child is not always there, so the section body is the
   * fallback anchor and the action stays ahead of the section's content either
   * way. Note it is contrib that removes it, not core: core always emits the
   * key and merely gates it with `#access`, but layout_builder_lock *unsets* it
   * on any section carrying LOCKED_SECTION_CONFIGURE, and its pre-render runs
   * before this one (positions 1 and 6 of the `layout_builder` element's
   * `#pre_render`). Every content-locked section this platform ships carries
   * that lock, so for an editor the fallback is the normal path on the Banner
   * and Title and Metadata sections rather than an edge case.
   *
   * @param array $section
   *   The built section render array.
   * @param array $link
   *   The clone link render array to insert.
   *
   * @return array
   *   The section render array with the clone link inserted.
   *
   * @see \Drupal\layout_builder\Element\LayoutBuilder::buildAdministrativeSection()
   */
  protected static function insertClone(array $section, array $link): array {
    $rebuilt = [];
    foreach ($section as $child_key => $child) {
      // Whichever of the two anchors appears first wins.
      if (!isset($rebuilt['clone']) && in_array($child_key, ['configure', 'layout-builder__section'], TRUE)) {
        $rebuilt['clone'] = $link;
      }
      $rebuilt[$child_key] = $child;
    }

    return $rebuilt;
  }

  /**
   * Resolves the label core used for a section.
   *
   * Core computes the label once — the layout's own `label` setting when the
   * editor has set one, otherwise "Section N" — and puts it on the section
   * container as its aria-label. Reading it back from there keeps the section
   * actions in agreement without re-deriving it, and without touching the
   * section storage: Section::getLayoutSettings() instantiates the layout
   * plugin just to return the settings, which is a container round-trip per
   * section for a string that is already in the render array.
   *
   * @param array $section
   *   The built section render array.
   * @param int $delta
   *   The section delta, used for the fallback label.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The section's label.
   *
   * @see \Drupal\layout_builder\Element\LayoutBuilder::buildAdministrativeSection()
   */
  protected static function sectionLabel(array $section, int $delta) {
    return $section['#attributes']['aria-label'] ?? t('Section @section', ['@section' => $delta + 1]);
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRender'];
  }

}
