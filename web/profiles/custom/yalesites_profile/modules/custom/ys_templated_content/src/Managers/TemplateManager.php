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
        'description' => 'An empty page, providing a clean slate for any type of content.',
        'filename' => '',
      ],
      'conference' => [
        'title' => 'Conference site homepage',
        'description' => 'Specifically designed for scheduling and providing detailed information about a conference.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/page__conference_site_homepage.zip',
      ],
      'landing_page' => [
        'title' => 'Landing page',
        'description' => 'An ideal choice for marketing or promoting a product or service, designed to grab attention and encourage action.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/page__epic_landing_page.zip',
      ],
      'lab1' => [
        'title' => 'Laboratory homepage',
        'description' => 'An excellent choice for showcasing research, experiments, or intricate project details.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/page__laboratory_homepage_1.zip',
      ],
    ],
    'post' => [
      '' => [
        'title' => 'Blank',
        'description' => 'An empty post, providing a clean slate for any type of content.',
        'filename' => '',
      ],
      'announcement' => [
        'title' => 'Announcement',
        'description' => 'A perfect choice for making official announcements or updates, with a clear and concise layout.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/post__announcement.zip',
      ],
      'blog1' => [
        'title' => 'Blog',
        'description' => 'Tailored for personal or professional blog posts, featuring a layout that encourages narrative.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/post__blog_post_1.zip',
      ],
      'news_article_1' => [
        'title' => 'News article',
        'description' => 'Specifically designed for publishing news or press releases, with a focus on readability.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/post__news_article_1.zip',
      ],
    ],
    'event' => [
      '' => [
        'title' => 'Blank',
        'description' => 'An empty event, providing a clean slate for any type of content.',
        'filename' => '',
      ],
      'in_person' => [
        'title' => 'In Person',
        'description' => 'Best suited for detailing in-person events, featuring sections for location and time specifics.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/event__in_person.zip',
      ],
      'online' => [
        'title' => 'Online',
        'description' => 'An ideal choice for online events, with dedicated sections for information on how to join.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/event__online.zip',
      ],
      'hybrid' => [
        'title' => 'Hybrid',
        'description' => 'A unique template suited for events that have both online and in-person participation, accommodating both types of information.',
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/event__hybrid.zip',
      ],
    ],
    'profile' => [
      '' => [
        'title' => 'Blank',
        'description' => 'An empty profile, providing a clean slate for any type of content.',
        'filename' => '',
      ],
      'keynote_speaker' => [
        'title' => 'Keynote speaker profile',
        'description' => "A specialized template for highlighting a speaker's website and expertise, with a focus on personal journey.",
        'filename' => 'https://github.com/dblanken-yale/content-templates/raw/main/profile__keynote_speaker_profile.zip',
      ],
      'organization' => [
        'title' => 'Organization profile',
        'description' => "A comprehensive template perfect for showcasing an organization's profile, history, and activities.",
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
