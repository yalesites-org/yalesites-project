<?php

namespace Drupal\ys_migrate_core\Plugin\migrate\process;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Process plugin for handling media migration with alt text enforcement.
 *
 * This plugin handles media migration with smart reuse and alt text enforcement:
 * - Reuses existing media if filename matches
 * - Creates new media entity if file doesn't exist
 * - Enforces alt text with helpful defaults when missing
 * - Downloads and stores media files from external sources
 *
 * @MigrateProcessPlugin(
 *   id = "process_media_field"
 * )
 */
class ProcessMediaField extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a ProcessMediaField plugin instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('migrate');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (empty($value)) {
      return NULL;
    }

    // Handle different input formats
    if (is_string($value)) {
      // Simple URL or file path
      $media_data = ['url' => $value];
    } elseif (is_array($value)) {
      $media_data = $value;
    } else {
      $this->logger->warning('Invalid media data format: @data', ['@data' => json_encode($value)]);
      return NULL;
    }

    // Required data
    $source_url = $media_data['url'] ?? $media_data['src'] ?? $media_data['file'] ?? null;
    if (!$source_url) {
      $this->logger->warning('No media URL provided in: @data', ['@data' => json_encode($media_data)]);
      return NULL;
    }

    // Extract filename from URL
    $filename = $this->extractFilename($source_url);
    if (!$filename) {
      $this->logger->warning('Could not extract filename from URL: @url', ['@url' => $source_url]);
      return NULL;
    }

    // Check if media with this filename already exists
    $existing_media = $this->findExistingMedia($filename);
    if ($existing_media) {
      $this->logger->info('Reusing existing media @id for file: @filename', [
        '@id' => $existing_media->id(),
        '@filename' => $filename,
      ]);
      
      // Return existing media with alt text handling
      return $this->formatMediaReference($existing_media, $media_data);
    }

    // Create new media entity
    $new_media = $this->createMediaEntity($source_url, $filename, $media_data);
    if (!$new_media) {
      return NULL;
    }

    $this->logger->info('Created new media @id for file: @filename', [
      '@id' => $new_media->id(),
      '@filename' => $filename,
    ]);

    return $this->formatMediaReference($new_media, $media_data);
  }

  /**
   * Extract filename from URL or file path.
   */
  protected function extractFilename(string $source_url): ?string {
    // Handle URLs with query parameters
    $parsed_url = parse_url($source_url);
    $path = $parsed_url['path'] ?? $source_url;
    
    $filename = basename($path);
    
    // Validate filename
    if (empty($filename) || $filename === '.' || $filename === '..') {
      return NULL;
    }

    return $filename;
  }

  /**
   * Find existing media entity by filename.
   */
  protected function findExistingMedia(string $filename) {
    $media_storage = $this->entityTypeManager->getStorage('media');
    $file_storage = $this->entityTypeManager->getStorage('file');

    // Find file entities with matching filename
    $file_query = $file_storage->getQuery()
      ->condition('filename', $filename)
      ->accessCheck(FALSE)
      ->range(0, 1);
    
    $file_ids = $file_query->execute();
    if (empty($file_ids)) {
      return NULL;
    }

    $file_id = reset($file_ids);

    // Find media entities that reference this file
    $media_query = $media_storage->getQuery()
      ->accessCheck(FALSE)
      ->range(0, 1);

    // Check different media source fields
    $group = $media_query->orConditionGroup()
      ->condition('field_media_image', $file_id)
      ->condition('field_media_file', $file_id)
      ->condition('field_media_video_file', $file_id);
    
    $media_query->condition($group);
    
    $media_ids = $media_query->execute();
    if (empty($media_ids)) {
      return NULL;
    }

    $media_id = reset($media_ids);
    return $media_storage->load($media_id);
  }

  /**
   * Create new media entity from source URL.
   */
  protected function createMediaEntity(string $source_url, string $filename, array $media_data) {
    try {
      // Download file from source
      $file_entity = $this->downloadAndCreateFile($source_url, $filename);
      if (!$file_entity) {
        return NULL;
      }

      // Determine media bundle based on file type
      $media_bundle = $this->determineMediaBundle($filename);
      $source_field = $this->getSourceFieldForBundle($media_bundle);

      // Prepare media values
      $media_values = [
        'bundle' => $media_bundle,
        'name' => $media_data['title'] ?? $media_data['name'] ?? pathinfo($filename, PATHINFO_FILENAME),
        $source_field => $file_entity->id(),
        'status' => 1,
      ];

      // Add alt text for images with enforcement
      if ($media_bundle === 'image') {
        $alt_text = $this->enforceAltText($media_data, $filename);
        $media_values[$source_field] = [
          'target_id' => $file_entity->id(),
          'alt' => $alt_text,
        ];
      }

      // Create media entity
      $media = $this->entityTypeManager->getStorage('media')->create($media_values);
      $media->save();

      return $media;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create media entity for @url: @message', [
        '@url' => $source_url,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Download file from URL and create file entity.
   */
  protected function downloadAndCreateFile(string $source_url, string $filename) {
    try {
      // Determine destination directory based on file type
      $destination_dir = $this->getDestinationDirectory($filename);
      $this->fileSystem->prepareDirectory($destination_dir, FileSystemInterface::CREATE_DIRECTORY);

      $destination = $destination_dir . '/' . $filename;

      // Download file content
      if (filter_var($source_url, FILTER_VALIDATE_URL)) {
        // External URL
        $file_content = file_get_contents($source_url);
        if ($file_content === FALSE) {
          $this->logger->error('Failed to download file from URL: @url', ['@url' => $source_url]);
          return NULL;
        }
      } else {
        // Local file path
        if (!file_exists($source_url)) {
          $this->logger->error('Source file does not exist: @path', ['@path' => $source_url]);
          return NULL;
        }
        $file_content = file_get_contents($source_url);
      }

      // Save file
      $file_uri = $this->fileSystem->saveData($file_content, $destination, FileSystemInterface::EXISTS_RENAME);
      if (!$file_uri) {
        $this->logger->error('Failed to save file: @destination', ['@destination' => $destination]);
        return NULL;
      }

      // Create file entity
      $file = $this->entityTypeManager->getStorage('file')->create([
        'filename' => basename($file_uri),
        'uri' => $file_uri,
        'status' => 1,
      ]);
      $file->save();

      return $file;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to download and create file @filename: @message', [
        '@filename' => $filename,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Determine media bundle based on file extension.
   */
  protected function determineMediaBundle(string $filename): string {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
    $video_extensions = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm'];
    $audio_extensions = ['mp3', 'wav', 'ogg', 'aac', 'm4a'];
    
    if (in_array($extension, $image_extensions)) {
      return 'image';
    } elseif (in_array($extension, $video_extensions)) {
      return 'video';
    } elseif (in_array($extension, $audio_extensions)) {
      return 'audio';
    } else {
      return 'document';
    }
  }

  /**
   * Get the source field name for a media bundle.
   */
  protected function getSourceFieldForBundle(string $bundle): string {
    switch ($bundle) {
      case 'image':
        return 'field_media_image';
      case 'video':
        return 'field_media_video_file';
      case 'audio':
        return 'field_media_file'; // Audio uses the generic file field
      case 'document':
      default:
        return 'field_media_file'; // Documents use the generic file field
    }
  }

  /**
   * Get destination directory based on file type.
   */
  protected function getDestinationDirectory(string $filename): string {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'])) {
      return 'public://media/images';
    } elseif (in_array($extension, ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm'])) {
      return 'public://media/videos';
    } elseif (in_array($extension, ['mp3', 'wav', 'ogg', 'aac', 'm4a'])) {
      return 'public://media/audio';
    } else {
      return 'public://media/documents';
    }
  }

  /**
   * Enforce alt text with helpful defaults when missing.
   */
  protected function enforceAltText(array $media_data, string $filename): string {
    $alt_text = $media_data['alt'] ?? $media_data['alt_text'] ?? '';
    
    if (empty($alt_text)) {
      // Create helpful default that prompts for admin attention
      $basename = pathinfo($filename, PATHINFO_FILENAME);
      $alt_text = "[ALT TEXT NEEDED] Image: " . str_replace(['_', '-'], ' ', $basename);
      
      $this->logger->warning('No alt text provided for image @filename, using placeholder: @alt', [
        '@filename' => $filename,
        '@alt' => $alt_text,
      ]);
    }
    
    return $alt_text;
  }

  /**
   * Format media reference for field storage.
   */
  protected function formatMediaReference($media_entity, array $media_data): array {
    $reference = ['target_id' => $media_entity->id()];
    
    // Add alt text for image fields if provided
    if ($media_entity->bundle() === 'image' && !empty($media_data['alt'])) {
      $reference['alt'] = $media_data['alt'];
    }
    
    return $reference;
  }

}