<?php

namespace Drupal\ys_templated_content\Managers;

use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Manager for templates.
 */
class TemplateManager {

  const TEMPLATE_PATH = '/config/templates/';

  const MANIFEST_URL = 'https://raw.githubusercontent.com/dblanken-yale/content-templates/main/manifest.yml';

  const DEFAULT_TEMPLATES = [
    'page' => [
      '' => [
        'title' => 'Blank',
        'description' => 'An empty page, providing a clean slate for any type of content.',
        'filename' => '',
      ],
    ],
    'post' => [
      '' => [
        'title' => 'Blank',
        'description' => 'An empty post, providing a clean slate for any type of content.',
        'filename' => '',
      ],
    ],
    'event' => [
      '' => [
        'title' => 'Blank',
        'description' => 'An empty event, providing a clean slate for any type of content.',
        'filename' => '',
      ],
    ],
    'profile' => [
      '' => [
        'title' => 'Blank',
        'description' => 'An empty profile, providing a clean slate for any type of content.',
        'filename' => '',
      ],
    ],
  ];


  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileRepositoryInterface
   * The file system.
   */
  protected $fileRepository;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   * The file system.
   */
  protected $fileSystem;

  /**
   * The templates that will be available to the user to select from.
   *
   * @var array
   */
  public $templates = [];

  /**
   * Constructs the controller object.
   *
   * @param \Drupal\Core\Extension\ModuleHandler $module_handler
   *   The module handler.
   * @param \Drupal\file\FileRepositoryInterface $fileRepository
   *   The file repository.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   */
  public function __construct(
    ModuleHandler $module_handler,
    FileRepositoryInterface $fileRepository,
    FileSystemInterface $fileSystem,
  ) {
    $this->moduleHandler = $module_handler;
    $this->fileRepository = $fileRepository;
    $this->fileSystem = $fileSystem;
    $this->templates = $this->getTemplateManifest();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('file.repository'),
      $container->get('file_system'),
    );
  }

  /**
   * Get the template options for the currrent content type.
   *
   * @param string $content_type
   *   The content type.
   * @param string $template
   *   The template.
   *
   * @return string
   *   The file path.
   */
  public function getFilenameForTemplate($content_type, $template) {
    $filename = $this->templates[$content_type][$template]['filename'];

    // See if the filename is a remote URL or a filename. If it is a remote
    // URL, we will download the file and store it in the temp directory. If it
    // is a filename, we will just return the filename.
    if (filter_var($filename, FILTER_VALIDATE_URL)) {
      $temp_dir = 'temporary://';
      $temp_filename = $temp_dir . basename($filename);
      $file_data = @file_get_contents($filename);

      if ($file_data === FALSE) {
        throw new \Exception('The file could not be downloaded for template at this time: ' . $this->getTemplateTitle($content_type, $template));
      }

      $temp_file = $this->fileRepository->writeData(
        $file_data,
        $temp_filename,
        FileSystemInterface::EXISTS_REPLACE
      );
      return $this->fileSystem->realpath($temp_file->getFileUri());
    }
    else {
      return $this
        ->moduleHandler
        ->getModule('ys_templated_content')
        ->getPath() . $this::TEMPLATE_PATH . $filename;
    }
  }

  /**
   * Get the template options for the currrent content type.
   *
   * @param string $content_type
   *   The content type to get templates for.
   * @param string $template
   *   The template name.
   *
   * @return array
   *   The template options.
   */
  public function getTemplateTitle($content_type, $template) {
    return $this->templates[$content_type][$template]['title'];
  }

  /**
   * Get the template options for the currrent content type.
   *
   * @param string $content_type
   *   The content type.
   * @param string $template_name
   *   The template name.
   *
   * @return array
   *   The template options.
   */
  public function getTemplateDescription($content_type, $template_name) {
    if (!isset($this->templates[$content_type][$template_name])) {
      $template_name = array_key_first($this->templates[$content_type]);
    }

    return $this->templates[$content_type][$template_name]['description'] ?? "";
  }

  /**
   * Get the templates.
   */
  public function getTemplates($content_type = NULL) {
    if ($content_type) {
      return $this->templates[$content_type];
    }

    return $this->templates;
  }

  /**
   * Get the template manifest from the GitHub repository.
   *
   * @return array
   *   The template manifest.
   */
  public static function getTemplateManifest(): array {
    $templates = [];
    $cache = \Drupal::cache('ys_templated_content_cache_bin');

    // If cached, return it.
    if ($cache->get('ys_templated_content_templates')) {
      return $cache->get('ys_templated_content_templates')->data;
    }

    try {
      $content = @file_get_contents(static::MANIFEST_URL);
      $manifest = Yaml::parse($content);
      $templates = $manifest['templates'];
    }
    catch (\Exception) {
      // If we can't get to github, at least offer blank configs.
      $templates = static::DEFAULT_TEMPLATES;
    }

    // Cache the data.
    $oneHourFromNow = time() + 3600;
    $cache->set('ys_templated_content_cache_bin', $templates, $oneHourFromNow);
    return $templates;
  }

}
