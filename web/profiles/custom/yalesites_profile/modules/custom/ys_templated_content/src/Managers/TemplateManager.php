<?php

namespace Drupal\ys_templated_content\Managers;

use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manager for templates.
 */
class TemplateManager {

  const TEMPLATE_PATH = '/config/templates/';

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
  public $templates = [
    'page' => [
      '' => [
        'title' => 'Blank',
        'description' => 'A blank page.',
        'filename' => '',
      ],
      'faq' => [
        'title' => 'FAQ',
        'description' => 'A template for a FAQ page.',
        'filename' => 'page__faq.yml',
      ],
      'landing_page' => [
        'title' => 'Landing Page',
        'description' => 'A template for a landing page.',
        'filename' => 'page__landing_page.yml',
      ],
      'remote_zip_file' => [
        'title' => 'Remote Zip',
        'description' => 'A template for a remote zip file.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/page__zip_file.zip',
      ],
      'local_zip_file' => [
        'title' => 'Local Zip',
        'description' => 'A template for a local zip file.',
        'filename' => 'page__zip_file.zip',
      ],
      'remote_zip_file_dne' => [
        'title' => 'Remote Zip Does Not Exist',
        'description' => 'A template for a remote zip file.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/page__zip_files.zip',
      ],
      'landing_page_dne' => [
        'title' => 'Landing Page Does Not Exist',
        'description' => 'A template for a landing page.',
        'filename' => 'page__landing_pages.yml',
      ],
    ],
    'post' => [
      '' => [
        'title' => 'Blank',
        'description' => 'A blank post.',
        'filename' => '',
      ],
      'blog' => [
        'title' => 'Blog',
        'description' => 'A template for a blog post.',
        'filename' => 'post__blog.yml',
      ],
      'news' => [
        'title' => 'News',
        'description' => 'A template for a news post.',
        'filename' => 'post__news.yml',
      ],
      'press_release' => [
        'title' => 'Press Release',
        'description' => 'A template for a press release.',
        'filename' => 'post__press_release.yml',
      ],
    ],
    'event' => [
      '' => [
        'title' => 'Blank',
        'description' => 'A blank event.',
        'filename' => '',
      ],
      'in_person' => [
        'title' => 'In Person',
        'description' => 'A template for an in person event.',
        'filename' => 'event__in_person.yml',
      ],
      'online' => [
        'title' => 'Online',
        'description' => 'A template for an online event.',
        'filename' => 'event__online.yml',
      ],
    ],
    'profile' => [
      '' => [
        'title' => 'Blank',
        'description' => 'A blank profile.',
        'filename' => '',
      ],
      'faculty' => [
        'title' => 'Faculty',
        'description' => 'A template for a faculty profile.',
        'filename' => 'profile__faculty.yml',
      ],
      'student' => [
        'title' => 'Student',
        'description' => 'A template for a student profile.',
        'filename' => 'profile__student.yml',
      ],
      'staff' => [
        'title' => 'Staff',
        'description' => 'A template for a staff profile.',
        'filename' => 'profile__staff.yml',
      ],
    ],
  ];

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

}
