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
      'conference' => [
        'title' => 'Conference site homepage',
        'description' => 'A template for a conference site homepage.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/page__conference_site_homepage.zip',
      ],
      'landing_page' => [
        'title' => 'Landing page',
        'description' => 'A template for a landing page.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/page__epic_landing_page.zip',
      ],
      'lab1' => [
        'title' => 'Laboratory homepage',
        'description' => 'A template for a lab page.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/page__laboratory_homepage_1.zip',
      ],
    ],
    'post' => [
      '' => [
        'title' => 'Blank',
        'description' => 'A blank post.',
        'filename' => '',
      ],
      'announcement' => [
        'title' => 'Announcement',
        'description' => 'A template for an announcement.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/post__announcement.zip',
      ],
      'blog1' => [
        'title' => 'Blog',
        'description' => 'A template for a blog page.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/post__blog_post_1.zip',
      ],
      'news_article_1' => [
        'title' => 'News article',
        'description' => 'A template for a news article.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/post__news_article_1.zip',
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
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/event__in_person.zip',
      ],
      'online' => [
        'title' => 'Online',
        'description' => 'A template for an online event.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/event__online.zip',
      ],
      'hybrid' => [
        'title' => 'Hybrid',
        'description' => 'A template for a hybrid event.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/event__hybrid.zip',
      ],
    ],
    'profile' => [
      '' => [
        'title' => 'Blank',
        'description' => 'A blank profile.',
        'filename' => '',
      ],
      'keynote_speaker' => [
        'title' => 'Keynote speaker profile',
        'description' => 'A template for a keynote speaker profile.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/profile__keynote_speaker_profile.zip',
      ],
      'organization' => [
        'title' => 'Organization profile',
        'description' => 'A template for an organization profile.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/profile__organization.zip',
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

}
