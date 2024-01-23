<?php

namespace Drupal\ys_templated_content;

use Drupal\Core\Entity\EntityInterface;
use Drupal\single_content_sync\ContentImporterInterface;
use Drupal\single_content_sync\ContentSyncHelperInterface;
use Drupal\ys_templated_content\Support\TemplateModifier;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manager for importing templated content.
 */
class ImportManager {

  const PLACEHOLDER = 'public://templated-content-images/placeholder.png';

  /**
   * The template modifier.
   *
   * @var \Drupal\ys_templated_content\Support\TemplateModifier
   */
  protected $templateModifier;

  /**
   * The template manager.
   *
   * @var \Drupal\ys_templated_content\TemplateManager
   */
  protected $templateManager;

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
   * Constructs the controller object.
   *
   * @param \Drupal\single_content_sync\ContentImporterInterface $contentImporter
   *   The content importer.
   * @param \Drupal\single_content_sync\ContentSyncHelperInterface $contentSyncHelper
   *   The content sync helper.
   * @param \Drupal\ys_templated_content\TemplateManager $templateManager
   *   The template manager.
   * @param \Drupal\ys_templated_content\Support\TemplateModifier $templateModifier
   *   The template modifier.
   */
  public function __construct(
    ContentImporterInterface $contentImporter,
    ContentSyncHelperInterface $contentSyncHelper,
    TemplateManager $templateManager,
    TemplateModifier $templateModifier,
  ) {
    $this->contentImporter = $contentImporter;
    $this->contentSyncHelper = $contentSyncHelper;
    $this->templateManager = $templateManager;
    $this->templateModifier = $templateModifier;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('single_content_sync.importer'),
      $container->get('single_content_sync.helper'),
      $container->get('ys_templated_content.template_manager'),
      $container->get('ys_templated_content.template_modifier'),
    );
  }

  /**
   * Create the import from the sample content.
   *
   * @param string $content_type
   *   The content type.
   * @param string $template
   *   The template.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Redirects to the edit form of the imported entity.
   */
  public function createImport(
    String $content_type,
    String $template
  ) : EntityInterface {
    /* Taken from the implementation of single_content_sync:
     * https://git.drupalcode.org/project/single_content_sync/-/blob/1.4.x/src/Form/ContentImportForm.php?ref_type=heads#L136-143
     *
     * This would be a great way to contribute back:
     * $this->contentSyncHelper->generateEntityFromStringYaml($this::CONTENT);
     */
    $content = file_get_contents(
        $this->templateManager->getFilenameForTemplate($content_type, $template)
      );

    $content_array = $this
      ->contentSyncHelper
      ->validateYamlFileContent($content);

    $content_array = $this->templateModifier->process($content_array);

    $entity = $this->contentImporter->doImport($content_array);

    return $entity;
  }

}
