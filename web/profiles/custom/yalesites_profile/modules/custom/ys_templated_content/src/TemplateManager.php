<?php

namespace Drupal\ys_templated_content;

use Drupal\ys_templated_content\Support\TemplateFilenameHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manager for templates.
 */
class TemplateManager {
  /**
   * The templates that will be available to the user to select from.
   *
   * @var array
   */
  public $templates = [
    'page' => [
      '' => [
        'title' => 'Empty',
        'description' => 'An empty template.',
        'filename' => '',
        'preview_image' => '',
      ],
      'faq' => [
        'title' => 'FAQ',
        'description' => 'A template for a FAQ page.',
        'filename' => 'page__faq.yml',
        'preview_image' => '',
      ],
      'landing_page' => [
        'title' => 'Landing Page',
        'description' => 'A template for a landing page.',
        'filename' => 'page__landing_page.yml',
        'preview_image' => '',
      ],
    ],
    'post' => [
      '' => [
        'title' => 'Empty',
        'description' => 'An empty template.',
        'filename' => '',
        'preview_image' => '',
      ],
      'blog' => [
        'title' => 'Blog',
        'description' => 'A template for a blog post.',
        'filename' => 'post__blog.yml',
        'preview_image' => '',
      ],
      'news' => [
        'title' => 'News',
        'description' => 'A template for a news post.',
        'filename' => 'post__news.yml',
        'preview_image' => '',
      ],
      'press_release' => [
        'title' => 'Press Release',
        'description' => 'A template for a press release.',
        'filename' => 'post__press_release.yml',
        'preview_image' => '',
      ],
    ],
    'event' => [
      '' => [
        'title' => 'Empty',
        'description' => 'An empty template.',
        'filename' => '',
        'preview_image' => '',
      ],
      'in_person' => [
        'title' => 'In Person',
        'description' => 'A template for an in person event.',
        'filename' => 'event__in_person.yml',
        'preview_image' => '',
      ],
      'online' => [
        'title' => 'Online',
        'description' => 'A template for an online event.',
        'filename' => 'event__online.yml',
        'preview_image' => '',
      ],
    ],
    'profile' => [
      '' => [
        'title' => 'Empty',
        'description' => 'An empty template.',
        'filename' => '',
        'preview_image' => '',
      ],
      'faculty' => [
        'title' => 'Faculty',
        'description' => 'A template for a faculty profile.',
        'filename' => 'profile__faculty.yml',
        'preview_image' => '',
      ],
      'student' => [
        'title' => 'Student',
        'description' => 'A template for a student profile.',
        'filename' => 'profile__student.yml',
        'preview_image' => '',
      ],
      'staff' => [
        'title' => 'Staff',
        'description' => 'A template for a staff profile.',
        'filename' => 'profile__staff.yml',
        'preview_image' => '',
      ],
    ],
  ];

  /**
   * The template filename helper.
   *
   * @var \Drupal\ys_templated_content\Support\TemplateFilenameHelper
   */
  protected $templateFilenameHelper;

  /**
   * Constructs the controller object.
   *
   * @param \Drupal\ys_templated_content\Support\TemplateFilenameHelper $templateFilenameHelper
   *   The template filename helper.
   */
  public function __construct(
    TemplateFilenameHelper $templateFilenameHelper,
  ) {
    $this->templateFilenameHelper = $templateFilenameHelper;
    /* $this->templates = $this->reload(); */
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ys_templated_content.template_filename_helper'),
    );
  }

  /**
   * Get the template options for the currrent content type.
   */
  public function reload() {
    /* $this->templates = $this->refreshTemplates($this->templateFilenameHelper->getTemplateBasePath()); */
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
    return $this->templateFilenameHelper->getImportFilePath($content_type, $template);
  }

  /**
   * Get the template options for the currrent content type.
   *
   * @return array
   *   The template options.
   */
  public function getCurrentTemplates($content_type) : array {
    $templates = [];
    // Return an empty array if there is no content type.
    if ($content_type) {
      // For each key and title value, create an array of the key and title.
      foreach ($this->templates[$content_type] as $key => $template) {
        $templates[$key] = $template['title'];
      }
    }
    return $templates;
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
    return $this->templates[$content_type][$template_name]['description'] ?? "Hi";
  }

  /**
   * Get the templates.
   */
  public function getTemplates() {
    return $this->templates;
  }

}
