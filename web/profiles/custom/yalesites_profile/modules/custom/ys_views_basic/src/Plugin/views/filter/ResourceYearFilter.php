<?php

namespace Drupal\ys_views_basic\Plugin\views\filter;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter resources by year.
 *
 * Shares PostYearFilter's query; only the year options differ.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("resource_year_filter")
 */
class ResourceYearFilter extends PostYearFilter {

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * Constructs a Handler object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection used by the entity query.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The default cache bin.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $connection, CacheBackendInterface $cache) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $connection);
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('cache.default')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, ?array &$options = NULL) {
    parent::init($view, $display, $options);
    $this->valueTitle = $this->t('Resource Year Filter');
  }

  /**
   * Generates the options for the filter.
   *
   * Returns only the distinct years that actually appear on a published
   * resource node's publish date. Cached and invalidated by the
   * `node_list:resource` cache tag.
   *
   * @return array
   *   Associative array where the keys and values are years (newest first).
   */
  public function generateYearOptions(): array {
    // The cache id predates the move from ys_views_content_resources (#1723)
    // and is kept so the entry already cached on each site stays valid.
    $cid = 'ys_views_content_resources:resource_year_filter:options';
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }

    $query = $this->connection->select('node__field_publish_date', 'nfd');
    $query->addExpression('SUBSTRING(nfd.field_publish_date_value, 1, 4)', 'year');
    $query->condition('nfd.bundle', 'resource');
    $query->distinct();
    $query->orderBy('year', 'DESC');
    $years = $query->execute()->fetchCol();

    $options = [];
    foreach ($years as $year) {
      if ($year !== '' && $year !== NULL) {
        $options[$year] = $year;
      }
    }

    $this->cache->set($cid, $options, CacheBackendInterface::CACHE_PERMANENT, ['node_list:resource']);

    return $options;
  }

}
