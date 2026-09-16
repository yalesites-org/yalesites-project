<?php

namespace Drupal\ys_core;

/**
 * Decides whether the SiteImprove script should load on a given page.
 *
 * SiteImprove analytics should run only on front-facing content, not on admin
 * or content-authoring pages. This helper centralises that route/path rule so
 * it is unit-testable in isolation from the render pipeline, mirroring
 * Editoria11yRouteSuppressor.
 */
class SiteimproveRouteChecker {

  /**
   * Administrative and content-authoring routes where SiteImprove must not run.
   */
  protected const EXCLUDED_ROUTES = [
    // Admin pages.
    'system.admin',
    'system.admin_content',
    'system.admin_structure',
    'system.admin_config',
    'system.admin_reports',
    'system.admin_help',
    'system.modules_list',
    'system.themes_page',

    // Content editing.
    'entity.node.edit_form',
    'node.add',
    'entity.node.delete_form',
    'entity.node.revision',
    'entity.node.version_history',
    'quickedit.field_form',
    'entity.node.content_translation_overview',

    // Layout Builder.
    'layout_builder.overrides.node.view',
    'layout_builder.overrides.node.save',
    'layout_builder.overrides.node.cancel',
    'layout_builder.overrides.node.revert',
    'layout_builder.move_block',
    'layout_builder.configure_block',
    'layout_builder.add_block',
    'layout_builder.remove_block',
    'layout_builder.configure_section',
    'layout_builder.add_section',
    'layout_builder.remove_section',

    // User management (keep public profiles).
    'entity.user.edit_form',
    'user.register',
    'user.pass',
    'user.login',
    'user.logout',
    'entity.user.delete_form',
    'user.admin_create',
    'user.admin_index',
    'user.multiple_cancel_confirm',
    'cas.bulk_add_cas_users',

    // Taxonomy editing.
    'entity.taxonomy_term.edit_form',
    'entity.taxonomy_term.add_form',
    'entity.taxonomy_term.delete_form',
    'entity.taxonomy_vocabulary.overview_form',
    'entity.taxonomy_vocabulary.collection',
    'entity.taxonomy_vocabulary.add_form',
    'entity.taxonomy_vocabulary.edit_form',

    // Block and media management.
    'entity.block_content.collection',
    'entity.block_content.add_form',
    'entity.block_content.edit_form',
    'entity.media.collection',
    'entity.media.add_form',
    'entity.media.edit_form',
    'view.media.media_page_list',

    // Configuration and development.
    'config.sync',
    'config.import_full',
    'config.export_full',
    'config.diff',
    'devel.admin_settings',
    'devel.switch',
    'system.performance_settings',
    'system.logging_settings',
    'system.cron_settings',
    'system.site_information_settings',

    // Reports and maintenance.
    'system.status',
    'dblog.overview',
    'update.status',
    'system.run_cron',
  ];

  /**
   * Path prefixes to exclude for routes not caught by name.
   */
  protected const EXCLUDED_PATH_PREFIXES = [
    '/admin',
    '/node/add',
    '/layout_builder',
    '/devel',
  ];

  /**
   * Determines whether SiteImprove should load on the current page.
   *
   * @param string|null $route_name
   *   The current route name, or NULL when no route matched.
   * @param string $current_path
   *   The current internal path.
   *
   * @return bool
   *   TRUE if SiteImprove should load (front-facing pages), FALSE on admin and
   *   content-authoring routes/paths.
   */
  public static function shouldLoad(?string $route_name, string $current_path): bool {
    if (in_array($route_name, self::EXCLUDED_ROUTES, TRUE)) {
      return FALSE;
    }

    // Editing paths (e.g. /node/5/edit, /term/3/delete).
    if (preg_match('/\/(edit|add|delete|layout)($|\/)/i', $current_path)) {
      return FALSE;
    }

    foreach (self::EXCLUDED_PATH_PREFIXES as $prefix) {
      if (str_starts_with($current_path, $prefix)) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
