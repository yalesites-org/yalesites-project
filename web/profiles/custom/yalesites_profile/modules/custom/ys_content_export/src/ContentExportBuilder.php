<?php

namespace Drupal\ys_content_export;

use Drupal\node\NodeInterface;

/**
 * Builds the columns and rows for a content-list CSV export.
 *
 * Kept free of injected services so its column mapping and cell sanitisation
 * can be unit tested directly; row building reads only node methods.
 */
class ContentExportBuilder {

  /**
   * Taxonomy columns shared by every content type.
   *
   * Keyed by field machine name; the value is the column header.
   */
  const SHARED_TAXONOMY = [
    'field_tags' => 'Tags',
    'field_audience' => 'Audience',
    'field_custom_vocab' => 'Custom Vocab',
  ];

  /**
   * Taxonomy columns specific to each content type.
   *
   * `field_category` is one field whose label differs per bundle; profiles use
   * `field_affiliation` instead and have no category.
   */
  const BUNDLE_TAXONOMY = [
    'page' => ['field_category' => 'Category'],
    'post' => ['field_category' => 'Category'],
    'event' => ['field_category' => 'Event Category'],
    'resource' => ['field_category' => 'Resource Category'],
    'profile' => ['field_affiliation' => 'Affiliation'],
  ];

  /**
   * Returns the ordered export columns for a content type.
   *
   * @param string $bundle
   *   The node bundle machine name.
   *
   * @return array
   *   Ordered map of column key (a node field name, or one of the pseudo keys
   *   title/url/published/cas_protected) to its header label.
   */
  public static function getColumns(string $bundle): array {
    $columns = [
      'title' => 'Title',
      'url' => 'URL',
      'published' => 'Published',
      'cas_protected' => 'CAS Protected',
    ];
    $columns += self::SHARED_TAXONOMY;
    $columns += self::BUNDLE_TAXONOMY[$bundle] ?? [];
    return $columns;
  }

  /**
   * Builds one sanitised CSV row for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to export.
   * @param string $bundle
   *   The node bundle machine name.
   *
   * @return array
   *   The row values, in the same order as getColumns(), each passed through
   *   sanitizeCell().
   */
  public static function getRow(NodeInterface $node, string $bundle): array {
    $row = [];
    foreach (array_keys(self::getColumns($bundle)) as $key) {
      $row[] = self::sanitizeCell(self::cellValue($node, $key));
    }
    return $row;
  }

  /**
   * Resolves the raw value for a single column of a node.
   */
  protected static function cellValue(NodeInterface $node, string $key): string {
    switch ($key) {
      case 'title':
        return (string) $node->label();

      case 'url':
        return $node->toUrl()->toString();

      case 'published':
        return $node->isPublished() ? 'Yes' : 'No';

      case 'cas_protected':
        return $node->hasField('field_login_required') && $node->get('field_login_required')->value
          ? 'Yes' : 'No';

      default:
        if (!$node->hasField($key)) {
          return '';
        }
        $names = [];
        foreach ($node->get($key)->referencedEntities() as $term) {
          $names[] = $term->label();
        }
        return implode(', ', $names);
    }
  }

  /**
   * Neutralises CSV formula injection (CWE-1236).
   *
   * Spreadsheet apps treat a cell beginning with =, +, -, @, tab or carriage
   * return as a formula. Prefixing such a value with a single quote forces it
   * to be read as text.
   *
   * @param string $value
   *   The raw cell value.
   *
   * @return string
   *   The value, safe to write to a CSV cell.
   */
  public static function sanitizeCell(string $value): string {
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
      return "'" . $value;
    }
    return $value;
  }

}
