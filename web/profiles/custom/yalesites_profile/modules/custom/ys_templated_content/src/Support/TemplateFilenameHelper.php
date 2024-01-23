<?php

namespace Drupal\ys_templated_content\Support;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

/**
 * Helper class for template filenames.
 *
 * Currently handles all of the things related to getting info on templated
 * filenames, like the full path, base location, etc.
 */
class TemplateFilenameHelper implements ContainerInjectionInterface {

  const TEMPLATE_PATH = '/config/templates/';

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * TemplateFilenameHelper constructor.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct($module_handler) {
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create($container) {
    return new static(
        $container->get('module_handler')
      );
  }

  /**
   * Get the import file path.
   *
   * @param string $content_type
   *   The content type.
   * @param string $template
   *   The template.
   *
   * @return string
   *   The file path.
   */
  public function getImportFilePath(
    String $content_type,
    String $template
  ) : String {
    $filename = ContentTypedFilename::constructFilename($content_type, $template);
    $path = $this->getFullFilenamePath($filename);
    $this->validatePath($path);
    return $path;
  }

  /**
   * Get the full file path.
   *
   * @param string $filename
   *   The filename.
   *
   * @return string
   *   The full file path from the base template path.
   */
  protected function getFullFilenamePath(String $filename) : String {
    return $this->getTemplateBasePath() . $filename;
  }

  /**
   * Get the base template path.
   *
   * @return string
   *   The base template path.
   */
  public function getTemplateBasePath() : String {
    return $this
      ->moduleHandler
      ->getModule('ys_templated_content')
      ->getPath() . $this::TEMPLATE_PATH;
  }

  /**
   * Validate the import file path.
   *
   * @param string $path
   *   The path.
   *
   * @return bool
   *   Whether the path exists.
   *
   * @throws \Exception
   */
  protected function validatePath(String $path) : bool {
    if (!file_exists($path)) {
      throw new \Exception('The import file does not exist.  Please ensure there is an import for this content type and template.');
    }

    return TRUE;
  }

  /**
   * Construct the templates array from the filenames.
   *
   * @param array $filenames
   *   The filenames.
   *
   * @return array
   *   The templates.
   */
  public function constructTemplatesArrayFromFilenames($filenames) : array {
    $templates = [];
    foreach ($filenames as $filename) {
      $contentTypedFilename = new ContentTypedFilename($filename);
      $templates[$contentTypedFilename->contentType][$contentTypedFilename->template] = $contentTypedFilename->humanizedTemplateName;
    }
    return $templates;
  }

  /**
   * Get the sanitized filenames from the path.
   *
   * This will remove . and .. from the array.
   *
   * @param string $path
   *   The path.
   *
   * @return array
   *   The filenames.
   */
  public function getSanitizedFilenamesFromPath($path) : array {
    $filenames = scandir($path);
    // Remove . and .. from the array.
    $filenames = array_slice($filenames, 2);
    return $filenames;
  }

}
