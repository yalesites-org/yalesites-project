<?php

namespace Drupal\ys_core;

/**
 * Decides whether the Editoria11y checker should run on a given route.
 *
 * Editoria11y attaches its library on every page, including authoring forms
 * where its overlay adds no value. This helper centralises the rule for which
 * routes it should be removed from, so the decision is unit-testable in
 * isolation from the render pipeline.
 */
class Editoria11yRouteSuppressor {

  /**
   * Routes where Editoria11y must keep running despite living under /admin.
   *
   * These are Editoria11y's own pages (the results dashboard and the demo),
   * which need the library to function.
   */
  protected const ALLOWED_ROUTES = [
    'editoria11y.reports_dashboard',
    'editoria11y.demo',
  ];

  /**
   * Determines whether Editoria11y should be suppressed on a route.
   *
   * @param string|null $route_name
   *   The current route name, or NULL when no route matched.
   * @param bool $is_admin_route
   *   Whether the current route is an administrative route.
   *
   * @return bool
   *   TRUE if Editoria11y should be removed (admin/authoring routes), FALSE if
   *   it should be left in place (front-facing pages and Editoria11y's own
   *   pages).
   */
  public static function shouldSuppress(?string $route_name, bool $is_admin_route): bool {
    if ($route_name === NULL) {
      return FALSE;
    }

    // Keep Editoria11y on its own pages, even though they live under /admin.
    if (in_array($route_name, self::ALLOWED_ROUTES, TRUE)) {
      return FALSE;
    }

    // Suppress on admin routes (e.g. node add/edit when the admin theme is used
    // for editing, plus /admin/* forms such as block, media, and term add).
    if ($is_admin_route) {
      return TRUE;
    }

    // Also suppress on entity authoring forms that are not flagged as admin
    // routes (e.g. taxonomy term edit at /taxonomy/term/{id}/edit).
    return (bool) preg_match('/\.(add|add_form|edit_form|delete_form)$/', $route_name)
      || str_ends_with($route_name, '_create');
  }

}
