<?php

namespace Drupal\ys_templated_content\Managers;

use Drupal\Core\Serialization\Yaml;
use Drupal\single_content_sync\ContentImporterInterface;
use Drupal\single_content_sync\ContentSyncHelperInterface;
use Drupal\ys_templated_content\ImportPluginManager;
use Drupal\ys_templated_content\Modifiers\TemplateModifier;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ys_templated_content\Modifiers\TemplateModifierFactory;

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
   * Template Modifier Factory
   *
   * @var \Drupal\ys_templated_content\Modifiers\TemplateModifierFactory
   */
  protected $templateModifierFactory;

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
   * Constructs the controller object.
   *
   * @param \Drupal\single_content_sync\ContentImporterInterface $contentImporter
   *   The content importer.
   * @param \Drupal\single_content_sync\ContentSyncHelperInterface $contentSyncHelper
   *   The content sync helper.
   * @param \Drupal\ys_templated_content\Managers\TemplateManager $templateManager
   *   The template manager.
   * @param \Drupal\ys_templated_content\Modifiers\TemplateModifier $templateModifier
   *   The template modifier.
   * @param \Drupal\ys_templated_content\ImportPluginManager $importPluginManager
   *   The import plugin manager.
   */
  public function __construct(
    ContentImporterInterface $contentImporter,
    ContentSyncHelperInterface $contentSyncHelper,
    TemplateManager $templateManager,
    TemplateModifierFactory $templateModifierFactory,
    ImportPluginManager $importPluginManager,
  ) {
    $this->contentImporter = $contentImporter;
    $this->contentSyncHelper = $contentSyncHelper;
    $this->templateManager = $templateManager;
    $this->templateModifierFactory = $templateModifierFactory;
    $this->importPluginManager = $importPluginManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('single_content_sync.importer'),
      $container->get('single_content_sync.helper'),
      $container->get('ys_templated_content.template_manager'),
      $container->get('ys_templated_content.template_modifier_factory'),
      $container->get('plugin.manager.templated_importer'),
    );
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
    $this->templateModifier = $this->templateModifierFactory->getTemplateModifier($extension);
    $importResult = NULL;
    $plugin_id = $this->importPluginManager->getPluginIdFromExtension($extension);
    try {
      $importer = $this->importPluginManager->createInstance($plugin_id, ['importManager' => $this]);
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
