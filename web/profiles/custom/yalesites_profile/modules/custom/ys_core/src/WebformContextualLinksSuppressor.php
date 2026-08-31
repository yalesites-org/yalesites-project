<?php

namespace Drupal\ys_core;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Strips the Webform module's contextual "edit" links.
 *
 * The Webform module unconditionally attaches a "webform" contextual-links
 * group (Test / Results / Build / Settings) to every rendered submission form.
 * On YaleSites that surfaces an edit icon — and a direct link to submission
 * data — to anyone with "access contextual links" who merely views a page
 * containing a Pre-Built Form block (issue #929).
 *
 * The group is removed everywhere. It used to be kept on Layout Builder routes,
 * but that surfaced nothing: the group is attached deep inside the submission
 * form rather than to the block, so it never reaches the block's contextual
 * pencil. Editors reach their submissions through the pencil's
 * "View form submissions" link instead, which offers only that one link and is
 * access-checked on the webform's own results route
 * (yalesites-org/YaleSites-Internal#1575).
 *
 * @see \_webform_form_webform_submission_form_after_build()
 * @see ys_core_form_alter()
 * @see \Drupal\ys_layouts\WebformResultsLinkBuilder
 */
class WebformContextualLinksSuppressor implements TrustedCallbackInterface {

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
