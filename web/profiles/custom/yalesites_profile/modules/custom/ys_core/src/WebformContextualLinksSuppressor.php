<?php

namespace Drupal\ys_core;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Decides when to strip the Webform module's contextual "edit" links.
 *
 * The Webform module unconditionally attaches a "webform" contextual-links
 * group (Test / Results / Build / Settings) to every rendered submission form.
 * On YaleSites that surfaces an edit icon — and a direct link to submission
 * data — to anyone with "access contextual links" who merely views a page
 * containing a Pre-Built Form block, outside of Layout Builder's "Edit Layout
 * and Content" mode. The rule for when to remove that group is centralised here
 * so it is unit-testable in isolation from the render pipeline.
 *
 * @see \_webform_form_webform_submission_form_after_build()
 * @see ys_core_form_alter()
 */
class WebformContextualLinksSuppressor implements TrustedCallbackInterface {

  /**
   * Determines whether the webform contextual links should be removed.
   *
   * They are kept only while editing in Layout Builder (its routes are prefixed
   * with "layout_builder."); on every view route — and defensively when no
   * route matched — they are removed so the edit icon cannot leak submission
   * data on the live page.
   *
   * @param string|null $route_name
   *   The current route name, or NULL when no route matched.
   *
   * @return bool
   *   TRUE if the webform contextual links should be removed.
   */
  public static function shouldSuppress(?string $route_name): bool {
    return $route_name === NULL || !str_starts_with($route_name, 'layout_builder.');
  }

  /**
   * Pre-render callback that strips the webform contextual-links group.
   *
   * Registered as a #pre_render so it runs after the Webform module's
   * #after_build has attached the group, but before the contextual placeholder
   * is built during preprocessing — so the edit icon is never rendered.
   *
   * @param array $element
   *   The webform submission form render array.
   *
   * @return array
   *   The render array without the webform contextual-links group.
   */
  public static function preRender(array $element): array {
    // 'webform' is the contextual-links group the Webform module attaches.
    unset($element['#contextual_links']['webform']);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRender'];
  }

}
