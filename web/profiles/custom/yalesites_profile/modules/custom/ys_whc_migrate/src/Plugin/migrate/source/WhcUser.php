<?php

declare(strict_types=1);

namespace Drupal\ys_whc_migrate\Plugin\migrate\source;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\migrate\Row;
use Drupal\user\Plugin\migrate\source\d7\User;

/**
 * The 'whc_user' source plugin.
 *
 * @MigrateSource(
 *   id = "whc_user",
 *   source_module = "node",
 * )
 */
class WhcUser extends User {

  /**
   * {@inheritdoc}
   */
  public function query(): SelectInterface {
    $query = parent::query();
    $query->condition('u.status', 1);

    if ($this->configuration['get_media'] ?? FALSE) {
      $query->condition('u.picture', 0, '<>');
    }

    if ($this->configuration['skip_users'] ?? FALSE) {
      $query->condition('u.uid', $this->configuration['skip_users'], 'NOT IN');
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator(): \Iterator {
    if (($this->configuration['get_media'] ?? FALSE) === TRUE) {
      return $this->getMediaIterator();
    }

    return parent::initializeIterator();
  }

  /**
   * Creates an iterator for media files.
   *
   * @return \ArrayIterator
   *   The iterator.
   */
  private function getMediaIterator(): \ArrayIterator {
    $files_found = [];
    $users = parent::initializeIterator();

    foreach ($users as $user) {
      if (!$user['picture']) {
        continue;
      }

      $picture_file = $this->getPictureFile((int) $user['picture']);
      if (!isset($picture_file['uri']) || isset($files_found[$picture_file['fid']])) {
        continue;
      }

      $file_url = $picture_file['uri'];
      if ($this->configuration['d7_file_url'] ?? FALSE) {
        $file_url = str_replace('public://', '', $file_url);
        $file_path = UrlHelper::encodePath($file_url);
        $file_url = $this->configuration['d7_file_url'] . $file_path;
      }

      $file_name = $picture_file['filename'];
      $file_url_pieces = explode('/', $file_url);
      if ($file_name !== end($file_url_pieces)) {
        $file_name = end($file_url_pieces);
      }

      $files_found[$picture_file['fid']] = [
        'uid' => $user['uid'],
        'fid' => $picture_file['fid'],
        'target_id' => $picture_file['fid'],
        'alt' => $user['name'] ?? NULL,
        'file_name' => $file_name,
        'file_path' => $file_url,
        'file_mime' => $picture_file['filemime'],
        'file_type' => 'image',
      ];
    }

    return new \ArrayIterator($files_found);
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row): bool {
    $result = parent::prepareRow($row);

    $fellow_role = $row->getSourceProperty('field_fellow_role/0/target_id');
    $people_type = $row->getSourceProperty('field_people_type/0/value');
    $row->setSourceProperty('skip_row', (bool) (!$fellow_role && !$people_type));

    return $result;
  }

  /**
   * Get a file by fid.
   *
   * @param int $fid
   *   The file id.
   *
   * @return array|false
   *   The file information, or FALSE if not found.
   */
  private function getPictureFile(int $fid): array|false {
    $file = $this->select('file_managed', 'f')
      ->fields('f')
      ->condition('fid', $fid)
      ->execute()
      ->fetchAssoc();

    return $file;
  }

}
