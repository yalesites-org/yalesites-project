<?php

declare(strict_types=1);

namespace Drupal\ys_migrate_sustainability_news\Plugin\migrate\source;

use Drupal\migrate\Row;
use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;

/**
 * D7 source plugin for public image files with per-field alt and title text.
 *
 * Extends the standard file_managed query with LEFT JOINs on both D7 image
 * field tables (field_news_image and field_image2) so that alt and title text
 * are available as source properties. When a file is referenced by both
 * fields, field_image2 takes precedence (it covers the more recent nodes).
 *
 * Produces the same base fields as the core d7_file plugin, plus:
 *   - alt:   Coalesced alt text from field_image2 or field_news_image.
 *   - title: Coalesced title text from field_image2 or field_news_image.
 *
 * Usage in migration YAML:
 *
 * @code
 * source:
 *   plugin: d7_news_images
 *   key: d7_sustainability
 * @endcode
 *
 * @MigrateSource(
 *   id = "d7_news_images",
 *   source_module = "file"
 * )
 */
class D7NewsImages extends DrupalSqlBase {

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('file_managed', 'fm')
      ->fields('fm')
      ->condition('fm.uri', 'temporary://%', 'NOT LIKE')
      ->condition('fm.uri', 'public://%', 'LIKE')
      ->condition('fm.filemime', [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
      ], 'IN')
      ->orderBy('fm.timestamp');

    // LEFT JOIN field_image2 to get alt/title for newer nodes (199 nodes).
    $query->leftJoin(
      'field_data_field_image2',
      'fi2',
      'fi2.field_image2_fid = fm.fid'
    );

    // LEFT JOIN field_news_image to get alt/title for older nodes (132 nodes).
    $query->leftJoin(
      'field_data_field_news_image',
      'fni',
      'fni.field_news_image_fid = fm.fid'
    );

    // Prefer field_image2 alt/title; fall back to field_news_image.
    $query->addExpression(
      'COALESCE(fi2.field_image2_alt, fni.field_news_image_alt)',
      'alt'
    );
    $query->addExpression(
      'COALESCE(fi2.field_image2_title, fni.field_news_image_title)',
      'title'
    );

    // Deduplicate: a file may be referenced by multiple nodes, which would
    // produce multiple rows. GROUP BY fid keeps one row per file.
    $query->groupBy('fm.fid');

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // Normalise NULL alt/title to empty strings so downstream process
    // plugins can safely use default_value fallbacks.
    if ($row->getSourceProperty('alt') === NULL) {
      $row->setSourceProperty('alt', '');
    }
    if ($row->getSourceProperty('title') === NULL) {
      $row->setSourceProperty('title', '');
    }
    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'fid'       => $this->t('File ID'),
      'uid'       => $this->t('The user who added the file.'),
      'filename'  => $this->t('File name'),
      'uri'       => $this->t('File URI'),
      'filemime'  => $this->t('File MIME type'),
      'filesize'  => $this->t('File size in bytes'),
      'status'    => $this->t('Published status'),
      'timestamp' => $this->t('Time the file was added'),
      'alt'       => $this->t('Alt text (coalesced from field_image2 or field_news_image)'),
      'title'     => $this->t('Title text (coalesced from field_image2 or field_news_image)'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'fid' => [
        'type'  => 'integer',
        'alias' => 'fm',
      ],
    ];
  }

}
