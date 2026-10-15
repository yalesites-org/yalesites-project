<?php

namespace Drupal\ys_core\Search;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Update\UpdateKernel;
use Drupal\search_api\Utility\PostRequestIndexing;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Defers Search API's direct indexing when running under the update kernel.
 *
 * Our indexes keep index_directly on and carry a "Rendered HTML output"
 * (rendered_item) field, so saving a node renders the whole page to extract its
 * text. Search API performs that render when the process ends, which for
 * `drush updatedb` and `drush deploy` means inside the update kernel.
 *
 * That is the problem. \Drupal\Core\Theme\Registry::get() takes a deliberate
 * branch when the kernel is an UpdateKernel: it builds the registry from the
 * `system` module alone, because a module's theme hook may query a schema that
 * its own pending update has not created yet. The resulting registry has no
 * module-provided theme hooks in it, and because it is kept in the registry's
 * in-memory property (the cache entry it would have written is deleted again),
 * every later render in that same process inherits it. ThemeManager::render()
 * logs "Theme hook ... not found" and returns FALSE for each missing hook, so
 * the page comes back with its body largely stripped.
 *
 * Search API then records those items as successfully indexed, so cron never
 * revisits them: the thin entries sit in the index until the content is edited
 * again or someone runs a full reindex, degrading site search and Beacon
 * answers with nothing in the editorial UI to explain it.
 *
 * Deferring is safe because the two halves of tracking are independent and
 * happen in that order - Index::trackItemsInsertedOrUpdated() marks the item in
 * the tracker first and only then registers the direct-indexing operation. So
 * dropping the operation leaves the item marked as needing indexing, and the
 * next cron run indexes it under the normal kernel with a complete registry.
 * Config is untouched: index_directly stays enabled and ordinary web requests
 * keep indexing immediately.
 *
 * This is a stopgap over contrib, not a permanent home. Rendering entities
 * during update.php is Search API's problem to solve, and there is a narrower
 * core bug behind it: Registry::get() deletes the cache entry it poisons but
 * leaves the system-only registry in its in-memory property, so nothing
 * recovers even once every update has run. Both are worth reporting upstream.
 * Delete this class if Search API stops indexing under the update kernel.
 * Note the one thing subclassing cannot protect against: if Search API adds a
 * *required* constructor argument the kernel test fails loudly, but an
 * *optional* one would be dropped here silently.
 */
class UpdateKernelDeferredIndexing extends PostRequestIndexing {

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    // HttpKernelInterface is ambiguous - it also matches the http_kernel
    // middleware stack, which is never an UpdateKernel and would silently
    // disable the guard below. Name the service explicitly.
    #[Autowire(service: 'kernel')]
    protected HttpKernelInterface $kernel,
  ) {
    parent::__construct($entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function destruct() {
    if (!$this->kernel instanceof UpdateKernel) {
      parent::destruct();
      return;
    }

    // Filtered, not counted directly: removeFromIndexing() unsets item keys
    // without unsetting the index key, so an index can be left present but
    // empty and would otherwise be counted and named as if it had work.
    $pending = array_filter($this->operations);
    if ($pending) {
      $this->getLogger()->notice('Deferred indexing of @count item(s) on @indexes to cron. Rendering them under the update kernel would index empty page bodies; they stay marked as needing indexing.', [
        '@count' => array_sum(array_map('count', $pending)),
        '@indexes' => implode(', ', array_keys($pending)),
      ]);
    }

    // Drop the operations rather than leaving them for a later destruct() call.
    $this->operations = [];
  }

}
