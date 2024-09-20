<?php

namespace Drupal\ys_views_basic\Plugin\views\filter;

use Drupal\Core\Database\Connection;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\filter\InOperator;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filter posts by year.
 *
 * @ingroup views_filter_handlers
 *
 * @ViewsFilter("post_year_filter")
 */
class PostYearFilter extends InOperator {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $connection;

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
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $connection) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, array &$options = NULL) {
    parent::init($view, $display, $options);

    $this->valueTitle = $this->t('Post Year Filter');
    $this->definition['options callback'] = [$this, 'generateYearOptions'];
  }

  /**
   * Generates the options for the filter.
   *
   * @return array
   *   Returns an associative array where the keys and values are years.
   */
  public function generateYearOptions(): array {
    $options = [];
    // Query to get the minimum year from the publish dates.
    $query = $this->connection->select('node__field_publish_date', 'nfd')
      ->fields('nfd', ['field_publish_date_value'])
      ->condition('bundle', 'post')
      ->orderBy('field_publish_date_value')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    $min_year = substr($query, 0, 4);
    $current_year = date('Y');
    // Generate a range of years from the minimum year to the current year.
    if ($min_year) {
      foreach (range($min_year, $current_year) as $year) {
        $options[$year] = $year;
      }
    }

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
