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
  protected $templates = [];

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
    $this->templates = $this->reload();
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
    $this->templates = $this->refreshTemplates($this->templateFilenameHelper->getTemplateBasePath());
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
      $templates = $this->templates[$content_type];
    }
    return $templates;
  }

  /**
   * Refresh the templates array.
   *
   * @param string $path
   *   The path to the templates.
   *
   * @return array
   *   The templates.
   */
  protected function refreshTemplates($path) : array {
    $filenames = $this->templateFilenameHelper->getSanitizedFilenamesFromPath($path);
    $templates = $this->templateFilenameHelper->constructTemplatesArrayFromFilenames($filenames);

    // Prepend the Empty case.
    foreach ($templates as $key => $template) {
      $templates[$key] = ['' => 'Empty'] + $template;
    }

    return $templates;
  }

}
