<?php

namespace Drupal\ys_templated_content\Managers;

use Drupal\Core\Serialization\Yaml;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manager for importing templated content.
 */
class ImportManager {
  const PLACEHOLDER = 'public://templated-content-images/placeholder.png';

  /**
   * The template modifier.
   *
   * @var \Drupal\ys_templated_content\Modifiers\TemplateModifier
   */
  protected $templateModifier;

  /**
   * The template manager.
   *
   * @var \Drupal\ys_templated_content\Managers\TemplateManager
   */
  protected $templateManager;

  /**
   * Template Modifier Manager.
   *
   * @var \Drupal\ys_templated_content\TemplateModifierManager
   */
  protected $templateModifierManager;

  /**
   * The content importer.
   *
   * @var \Drupal\single_content_sync\ContentImporterInterface
   */
  protected $contentImporter;

  /**
   * The content sync helper.
   *
   * @var \Drupal\single_content_sync\ContentSyncHelperInterface
   */
  protected $contentSyncHelper;

  /**
   * The import plugin manager.
   *
   * @var \Drupal\ys_templated_content\Managers\ImportPluginManager
   */
  protected $importPluginManager;

  /**
   * The container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected $container;

  /**
   * Constructs the controller object.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   */
  public function __construct(ContainerInterface $container) {
    $this->container = $container;
    $this->contentImporter = $container->get('single_content_sync.importer');
    $this->contentSyncHelper = $container->get('single_content_sync.helper');
    $this->templateManager = $container->get('ys_templated_content.template_manager');
    $this->templateModifierManager = $container->get('plugin.manager.ys_templated_content.modifier_processor');
    $this->importPluginManager = $container->get('plugin.manager.ys_templated_content.importer_processor');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container);
  }

  /**
   * Create the import from the sample content.
   *
   * @param string $content_type
   *   The content type.
   * @param string $template
   *   The template.
   */
  public function createImport(
    String $content_type,
    String $template,
  ) {
    $filename = $this->templateManager->getFilenameForTemplate($content_type, $template);
    $templateTitle = $this->templateManager->getTemplateTitle($content_type, $template);

    if ($filename === NULL) {
      return FALSE;
    }

    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $templateModifierPluginId = $this->templateModifierManager->getPluginIdFromExtension($extension);
    $import_plugin_id = $this->importPluginManager->getPluginIdFromExtension($extension);
    $importResult = NULL;
    try {
      $this->templateModifier = $this->templateModifierManager->createInstance($templateModifierPluginId, ['container' => $this->container]);
      $importer = $this->importPluginManager->createInstance($import_plugin_id, ['importManager' => $this]);
      $importResult = $importer->import($filename);
    }
    catch (\Exception $e) {
      throw new \Exception('The file could not be imported at this time: ' . $templateTitle);
    }

    return $importResult;
  }

  /**
   * Get the content from a file.
   *
   * @param string $filename
   *   The filename.
   *
   * @return array
   *   The content array.
   */
  public function getContentFromFile($filename) {
    /* Taken from the implementation of single_content_sync:
     * https://git.drupalcode.org/project/single_content_sync/-/blob/1.4.x/src/Form/ContentImportForm.php?ref_type=heads#L136-143
     *
     * This would be a great way to contribute back:
     * $this->contentSyncHelper->generateEntityFromStringYaml($this::CONTENT);
     */
    $content = @file_get_contents($filename);

    if ($content === FALSE) {
      throw new \Exception('The file could not be read at this time: ' . $filename);
    }

    $content_array = $this
      ->contentSyncHelper
      ->validateYamlFileContent($content);

    $content_array = $this->templateModifier->process($content_array);

    return $content_array;
  }

  /**
   * Write the content to a file.
   *
   * We use this to re-write the modified YAML to the file.
   *
   * @param string $yamlFilename
   *   The filename.
   * @param array $content
   *   The content array.
   */
  public function writeContentToFile($yamlFilename, $content) {
    $yaml = Yaml::encode($content);
    file_put_contents($yamlFilename, $yaml);
  }

  /**
   * Generate a UUID.
   *
   * @return string
   *   The UUID.
   */
  public function generateUuid() {
    return $this->templateModifier->generateUuid();
  }

  /**
   * Get the template modifier.
   *
   * @return \Drupal\ys_templated_content\Modifiers\TemplateModifier
   *   The template modifier object.
   */
  public function getTemplateModifier() {
    return $this->templateModifier;
  }

  /**
   * Get the content importer.
   *
   * @return \Drupal\single_content_sync\ContentImporterInterface
   *   The content importer object.
   */
  public function getContentImporter() {
    return $this->contentImporter;
  }

  /**
   * Get the content sync helper.
   *
   * @return \Drupal\single_content_sync\ContentSyncHelperInterface
   *   The content sync helper object.
   */
  public function getContentSyncHelper() {
    return $this->contentSyncHelper;
  }

}
