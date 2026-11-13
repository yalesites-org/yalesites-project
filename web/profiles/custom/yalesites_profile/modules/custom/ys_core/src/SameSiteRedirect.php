<?php

namespace Drupal\ys_core;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Picks a safe place to send an admin back to after a GET action.
 */
final class SameSiteRedirect {

  /**
   * Returns the Referer if it is a page on this site, else the front page.
   *
   * Only an absolute URL on this site's host and base path is followed.
   * Anything else, including a missing Referer, goes to the front page, so the
   * calling route cannot be used as an open redirect.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return string
   *   The URL to redirect to.
   */
  public static function fromReferer(Request $request): string {
    $referer = (string) $request->server->get('HTTP_REFERER');
    $base_url = $request->getSchemeAndHttpHost() . $request->getBasePath() . '/';

    try {
      if (UrlHelper::isExternal($referer) && UrlHelper::externalIsLocal($referer, $base_url)) {
        return $referer;
      }
    }
    catch (\InvalidArgumentException) {
      // An absolute-looking Referer with no host, such as mailto:, is not a
      // page on this site.
    }

    return Url::fromRoute('<front>')->toString();
  }

}
