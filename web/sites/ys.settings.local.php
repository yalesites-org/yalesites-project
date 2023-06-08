<?php

// phpcs:ignoreFile

/**
 * @file
 * Local development override configuration feature.
 */

/**
 * Assertions.
 */
assert_options(ASSERT_ACTIVE, TRUE);
\Drupal\Component\Assertion\Handle::register();

/**
 * Enable local development services.
 */
$settings['container_yamls'][] = DRUPAL_ROOT . '/sites/theming.services.yml';

/**
 * Show all error messages, with backtrace information.
 */
$config['system.logging']['error_level'] = 'verbose';

/**
 * Disable CSS and JS aggregation.
 */
$config['system.performance']['css']['preprocess'] = FALSE;
$config['system.performance']['js']['preprocess'] = FALSE;

/**
 * Disable page cache.
 */
$config['system.performance']['cache']['page']['max_age'] = 0;

/**
 * Disable the render cache.
 */
$settings['cache']['bins']['render'] = 'cache.backend.null';

/**
 * Disable caching for migrations.
 */
$settings['cache']['bins']['discovery_migration'] = 'cache.backend.memory';

/**
 * Disable Internal Page Cache.
 */
$settings['cache']['bins']['page'] = 'cache.backend.null';

/**
 * Disable Dynamic Page Cache.
 */
$settings['cache']['bins']['dynamic_page_cache'] = 'cache.backend.null';

/**
 * Enable access to rebuild.php.
 */
$settings['rebuild_access'] = TRUE;

/**
 * Skip file system permissions hardening.
 */
$settings['skip_permissions_hardening'] = TRUE;

// Change kint depth_limit setting.
if (class_exists('Kint')) {
  // Set the depth_limit to prevent out-of-memory.
  \Kint::$depth_limit = 4;
}

// Enable Config Split locally only
$config['config_split.config_split.local_config']['status'] = TRUE;
