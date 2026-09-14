<?php

declare(strict_types=1);

namespace Drupal\ys_layouts;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Adds a "View form submissions" contextual link to Pre-Built Form blocks.
 *
 * The Webform module attaches its own contextual group (Test / Results / Build
 * / Settings) to every rendered submission form, which leaked a link to
 * submission data onto the live page; ys_core strips it (issue #929). That left
 * editors with no in-context route to their submissions at all, so this
 * restores the one link they actually need - the form's results - in Layout
 * Builder, where the platform already keeps its editing affordances.
 *
 * Core writes the shared `layout_builder_block` group (Configure / Move /
 * Remove) onto every component in
 * \Drupal\layout_builder\Element\LayoutBuilder::buildAdministrativeSection().
 * A separate group attached to the same element merges into the same pencil and
 * sorts with it by weight, which is how the "Clone" action is added - so this
 * follows that pattern rather than altering core's group, whose route
 * parameters describe a layout component and cannot address a webform route.
 *
 * The webform is read off the block content entity Layout Builder has already
 * put in the component's render array, rather than reloaded from the section
 * storage. That is both cheaper (no entity load per placed block, and a block
 * revision load is not served from the entity cache) and more correct: the
 * rendered entity is the pending one, so a block placed or repointed at another
 * form during this editing session links to the form the editor is looking at
 * rather than to the last saved revision.
 *
 * Access is not checked here: ContextualLinkManager access-checks every link's
 * route, and entity.webform.results_submissions requires the
 * webform.submission_view_any operation (the "view any webform submission"
 * permission), so the link appears exactly for the users allowed to follow it.
 *
 * @see ys_layouts_element_info_alter()
 * @see \Drupal\ys_layouts\CloneBlockLinkBuilder
 * @see \Drupal\ys_core\WebformContextualLinksSuppressor
 */
class WebformResultsLinkBuilder implements TrustedCallbackInterface {

  /**
   * Pre-render callback: link each form block to its own submissions.
   *
   * @param array $element
   *   The built `layout_builder` render element.
   *
   * @return array
   *   The element with results links attached to Pre-Built Form blocks.
   */
  public static function preRender(array $element): array {
    if (isset($element['layout_builder'])) {
      static::attachResultsLinks($element['layout_builder']);
    }
    return $element;
  }

  /**
   * Recursively attaches the results link to form-block render arrays.
   *
   * The contextual links live on components nested under section and region
   * keys, so the whole subtree is walked rather than a fixed depth assumed.
   *
   * @param array $build
   *   A render array to walk.
   */
  protected static function attachResultsLinks(array &$build): void {
    foreach (Element::children($build) as $key) {
      if (isset($build[$key]['#contextual_links']['layout_builder_block'])) {
        $webform_id = static::webformId($build[$key]['content'] ?? []);
        if ($webform_id !== NULL) {
          $build[$key]['#contextual_links']['ys_layouts_webform_results'] = [
            'route_parameters' => ['webform' => $webform_id],
          ];
        }
      }
      static::attachResultsLinks($build[$key]);
    }
  }

  /**
   * Reads the webform a component's block content references, if any.
   *
   * The entity view builder puts the entity being rendered in the build under a
   * key named for its entity type, which is also how _ys_layouts_add_layout_
   * section() reaches a placed block's content.
   *
   * Matched on the field's type rather than a hardcoded field or bundle name,
   * so the link keeps working for any block type that references a webform.
   *
   * @param array $content
   *   The component's block content render array.
   *
   * @return string|null
   *   The referenced webform id, or NULL when the component renders no webform.
   */
  protected static function webformId(array $content): ?string {
    $block_content = $content['#block_content'] ?? NULL;
    if (!$block_content instanceof FieldableEntityInterface) {
      return NULL;
    }

    foreach ($block_content->getFields() as $items) {
      if ($items->getFieldDefinition()->getType() !== 'webform') {
        continue;
      }
      $target_id = $items->getValue()[0]['target_id'] ?? NULL;
      if ($target_id !== NULL && $target_id !== '') {
        return (string) $target_id;
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRender'];
  }

}
