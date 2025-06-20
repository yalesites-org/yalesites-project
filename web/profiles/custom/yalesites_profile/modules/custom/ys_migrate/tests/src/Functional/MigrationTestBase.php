<?php

namespace Drupal\Tests\ys_migrate\Functional;

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\media\Entity\Media;
use Drupal\Tests\BrowserTestBase;
use Symfony\Component\Yaml\Yaml;

/**
 * Base class for migration functional tests.
 */
abstract class MigrationTestBase extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'ys_migrate',
    'migrate_plus',
    'migrate_tools',
    'block_content',
    'paragraphs',
    'layout_builder',
    'node',
    'field',
    'text',
    'link',
    'media',
    'image',
    'file',
  ];

  /**
   * Created media entities for testing.
   *
   * @var \Drupal\media\Entity\Media[]
   */
  protected $testMedia = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Enable Layout Builder for page content type
    $this->enableLayoutBuilderForBundle('node', 'page');

    // Create test media entities
    $this->createTestMedia();
  }

  /**
   * Run a migration and assert it completed successfully.
   *
   * @param string $migration_id
   *   The migration ID to run.
   *
   * @return \Drupal\migrate\MigrateExecutableInterface
   *   The migration executable.
   */
  protected function runMigration($migration_id) {
    /** @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migration_manager */
    $migration_manager = $this->container->get('plugin.manager.migration');
    $migration = $migration_manager->createInstance($migration_id);
    
    /** @var \Drupal\migrate_tools\MigrateExecutable $executable */
    $executable = new \Drupal\migrate_tools\MigrateExecutable($migration);
    $result = $executable->import();
    
    $this->assertEquals(\Drupal\migrate\MigrateExecutableInterface::RESULT_COMPLETED, $result);
    
    return $executable;
  }

  /**
   * Load a block content entity by its info/label.
   *
   * @param string $info
   *   The block info/label.
   *
   * @return \Drupal\block_content\Entity\BlockContent|null
   *   The block content entity or NULL if not found.
   */
  protected function loadBlockByInfo($info) {
    $blocks = \Drupal::entityTypeManager()
      ->getStorage('block_content')
      ->loadByProperties(['info' => $info]);
    
    return reset($blocks);
  }

  /**
   * Load a node by title.
   *
   * @param string $title
   *   The node title.
   *
   * @return \Drupal\node\Entity\Node|null
   *   The node entity or NULL if not found.
   */
  protected function loadNodeByTitle($title) {
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties(['title' => $title]);
    
    return reset($nodes);
  }

  /**
   * Enable Layout Builder for a bundle.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   */
  protected function enableLayoutBuilderForBundle($entity_type, $bundle) {
    $display = LayoutBuilderEntityViewDisplay::load($entity_type . '.' . $bundle . '.default');
    if (!$display) {
      $display = LayoutBuilderEntityViewDisplay::create([
        'targetEntityType' => $entity_type,
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    }
    $display->enableLayoutBuilder()->setOverridable()->save();
  }

  /**
   * Create test media entities for use in migrations.
   */
  protected function createTestMedia() {
    $fixtures_path = __DIR__ . '/../fixtures/test_media.yml';
    
    if (!file_exists($fixtures_path)) {
      return;
    }

    $media_data = Yaml::parseFile($fixtures_path);
    
    foreach ($media_data['test_media'] as $media_config) {
      $file = $this->createTestFile($media_config['filename']);
      
      $media = Media::create([
        'bundle' => $media_config['bundle'],
        'name' => $media_config['name'],
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt' => $media_config['alt'] ?? '',
        ],
        'status' => 1,
      ]);
      
      $media->save();
      $this->testMedia[$media_config['id']] = $media;
    }
  }

  /**
   * Create a test file entity.
   *
   * @param string $filename
   *   The filename.
   *
   * @return \Drupal\file\Entity\File
   *   The created file entity.
   */
  protected function createTestFile($filename) {
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    
    // Create a simple test image
    $image_data = $this->createTestImageData();
    $directory = 'public://test-images';
    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    
    $file_path = $directory . '/' . $filename;
    $file_system->saveData($image_data, $file_path, FileSystemInterface::EXISTS_REPLACE);
    
    $file = File::create([
      'filename' => $filename,
      'uri' => $file_path,
      'status' => 1,
    ]);
    $file->save();
    
    return $file;
  }

  /**
   * Create minimal test image data.
   *
   * @return string
   *   Binary image data for a 1x1 pixel PNG.
   */
  protected function createTestImageData() {
    // 1x1 pixel transparent PNG
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
  }

  /**
   * Get migration messages for debugging.
   *
   * @param string $migration_id
   *   The migration ID.
   *
   * @return array
   *   Array of migration messages.
   */
  protected function getMigrationMessages($migration_id) {
    /** @var \Drupal\migrate\Plugin\MigrationPluginManagerInterface $migration_manager */
    $migration_manager = $this->container->get('plugin.manager.migration');
    $migration = $migration_manager->createInstance($migration_id);
    
    $messages = [];
    $map = $migration->getIdMap();
    
    foreach ($map->getMessages() as $message) {
      $messages[] = [
        'level' => $message->level,
        'message' => $message->message,
      ];
    }
    
    return $messages;
  }

  /**
   * Assert that a migration completed without errors.
   *
   * @param string $migration_id
   *   The migration ID.
   */
  protected function assertMigrationSuccess($migration_id) {
    $messages = $this->getMigrationMessages($migration_id);
    $error_messages = array_filter($messages, function($message) {
      return $message['level'] == 1; // MigrationInterface::MESSAGE_ERROR
    });
    
    $this->assertEmpty($error_messages, sprintf(
      'Migration %s completed with errors: %s',
      $migration_id,
      implode(', ', array_column($error_messages, 'message'))
    ));
  }

  /**
   * Get a test media entity by ID.
   *
   * @param string $media_id
   *   The test media ID.
   *
   * @return \Drupal\media\Entity\Media|null
   *   The media entity or NULL if not found.
   */
  protected function getTestMedia($media_id) {
    return $this->testMedia[$media_id] ?? NULL;
  }

}