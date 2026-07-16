<?php

namespace Drupal\ys_views_content_resources\Plugin\views\filter;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\filter\InOperator;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter resources by year.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("resource_year_filter")
 */
class ResourceYearFilter extends InOperator {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $connection;

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
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->connection = $connection;
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
    $this->definition['options callback'] = [$this, 'generateYearOptions'];
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

  /**
   * {@inheritdoc}
   */
  public function query() {
    // If there are no values, do nothing.
    if (empty($this->value)) {
      return;
    }

    // Ensure the main table for this handler is in the query.
    $this->ensureMyTable();

    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;

    $lookupTable = $query->addTable('node__field_publish_date');
    $field = "$lookupTable.field_publish_date_value";

    // Prepare the placeholder and conditions.
    $placeholders = [];
    $conditions = [];

    // Handle multiple values or single value.
    if ($this->options['expose']['multiple']) {
      foreach ($this->value as $index => $year) {
        $placeholder = ":year_$index";
        $conditions[] = "$field LIKE $placeholder";
        $placeholders[$placeholder] = $year . '%';
      }
      $condition_expression = implode(' OR ', $conditions);
    }
    else {
      $placeholder = ":year";
      $conditions[] = "$field LIKE $placeholder";
      $year = reset($this->value);
      $placeholders[$placeholder] = $year . '%';
      $condition_expression = $conditions[0];
    }

    // Add the where expression with placeholders.
    $query->addWhereExpression($this->options['group'], $condition_expression, $placeholders);
  }

}
