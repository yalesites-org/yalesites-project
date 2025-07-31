<?php

declare(strict_types=1);

namespace Drupal\ys_whc_migrate\Plugin\migrate\process;

use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Get Media File process plugin.
 *
 * @code
 * process:
 *   fid:
 *     plugin: whc_get_media_file
 *     source: mid
 * @endcode
 */
#[MigrateProcess(
  id: 'whc_get_media_file',
)]
class GetMediaFile extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The current row.
   *
   * @var \Drupal\migrate\Row|null
   */
  private ?Row $row = NULL;

  /**
   * Constructs the plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly Connection $database,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property): mixed {
    $fid = $this->database->select('media__field_media_image', 'media')
      ->fields('media', ['field_media_image_target_id'])
      ->condition('entity_id', $value)
      ->execute()
      ->fetchField();

    return $fid;
  }

}
