<?php

namespace Drupal\ys_templated_content\Form;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\single_content_sync\ContentImporterInterface;
use Drupal\single_content_sync\ContentSyncHelperInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for allowing users to create nodes based on templated content.
 */
class TemplatedContentForm extends FormBase implements FormInterface {

  const TEMPLATE_PATH = '/config/templates/';

  /**
   * The templates that will be available to the user to select from.
   *
   * @var array
   */
  protected $templates = [];

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Module\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The UUID service.
   *
   * @var \Drupal\Core\Uuid\UuidInterface
   */
  protected $uuidService;

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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * Constructs the controller object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager to get the content types available.
   * @param \Drupal\single_content_sync\ContentImporterInterface $contentImporter
   *   The content importer.
   * @param \Drupal\single_content_sync\ContentSyncHelperInterface $contentSyncHelper
   *   The content sync helper.
   * @param \Drupal\Core\Uuid\UuidInterface $uuidService
   *   The UUID service.
   * @param \Drupal\Core\Module\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    ContentImporterInterface $contentImporter,
    ContentSyncHelperInterface $contentSyncHelper,
    UuidInterface $uuidService,
    ModuleHandlerInterface $moduleHandler
  ) {
    $this->entityManager = $entityTypeManager;
    $this->contentImporter = $contentImporter;
    $this->contentSyncHelper = $contentSyncHelper;
    $this->uuidService = $uuidService;
    $this->moduleHandler = $moduleHandler;
    $this->templates = $this->refreshTemplates($this->getTemplateBasePath());
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('single_content_sync.importer'),
      $container->get('single_content_sync.helper'),
      $container->get('uuid'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'templated_content_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $currentContentType = $this->getCurrentContentType($form_state);

    $form['content_types'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#options' => $this->getContentTypes(),
      '#required' => TRUE,
      '#default_value' => 'page',
      '#ajax' => [
        'callback' => [$this, 'updateTemplates'],
        'wrapper' => 'template-update-wrapper',
        'disable-refocus' => FALSE,
        'event' => 'change',
        'progress' => [
          'type' => 'none',
        ],
      ],
    ];

    $form['templates'] = [
      '#type' => 'select',
      '#title' => $this->t('Template'),
      '#options' => $this->getCurrentTemplates($currentContentType),
      '#required' => FALSE,
      '#prefix' => '<div id="template-update-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $content_type = $this->getCurrentContentType($form_state);
    $template = $form_state->getValue('templates');

    if ($template == '') {
      $form_state->setRedirect(
        'node.add',
        ['node_type' => $content_type, 'template' => $template]
      );
    }
    else {
      $this->createImport($form_state, $content_type, $template);
    }
  }

  /**
   * Create the import from the sample content.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $content_type
   *   The content type.
   * @param string $template
   *   The template.
   *
   * @return void
   *   Redirects to the edit form of the imported entity.
   */
  protected function createImport(
    FormStateInterface $form_state,
    String $content_type,
    String $template
  ) : void {
    /* Taken from the implementation of single_content_sync:
     * https://git.drupalcode.org/project/single_content_sync/-/blob/1.4.x/src/Form/ContentImportForm.php?ref_type=heads#L136-143
     *
     * This would be a great way to contribute back:
     * $this->contentSyncHelper->generateEntityFromStringYaml($this::CONTENT);
     */
    try {
      $content = file_get_contents(
        $this->getImportFilePath($content_type, $template)
      );
      $content_array = $this
        ->contentSyncHelper
        ->validateYamlFileContent($content);
      $content_array['uuid'] = $this->uuidService->generate();

      $entity = $this->contentImporter->doImport($content_array);
      $this->messenger()->addMessage("Content generated successfully.  Please make any edits now as this has already been created for you.  Don't forget to change the URL alias.");
      $form_state->setRedirect(
        $this->getEntityEditFormPath($entity),
        [$entity->getEntityTypeId() => $entity->id()]
      );
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
  }

  /**
   * Get the entity path.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return string
   *   The path to the edit form.
   */
  private function getEntityEditFormPath($entity) {
    return 'entity.' . $entity->getEntityTypeId() . '.edit_form';
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
  protected function getImportFilePath(
    String $content_type,
    String $template
  ) : String {
    $filename = $this->constructImportFilename($content_type, $template);
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
  protected function getTemplateBasePath() : String {
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
   * Construct the import filename.
   *
   * @param string $content_type
   *   The content type.
   * @param string $template
   *   The template.
   *
   * @return string
   *   The filename.
   */
  protected function constructImportFilename(
    String $content_type,
    String $template
  ) : String {
    return $content_type . '__' . $template . '.yml';
  }

  /**
   * Get all content types.
   *
   * @return array
   *   The content types.
   */
  private function getContentTypes() : array {
    $content_types = $this
      ->entityManager
      ->getStorage('node_type')
      ->loadMultiple();
    $options = [];

    foreach ($content_types as $content_type) {
      $options[$content_type->id()] = $content_type->label();
    }

    return $options;
  }

  /**
   * Update the template options when the content type changes.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The updated template options.
   */
  public function updateTemplates(
    array &$form,
    FormStateInterface $form_state
  ) : array {
    $currentContentType = $this->getCurrentContentType($form_state);
    $form['templates']['#options'] = $this
      ->getCurrentTemplates($currentContentType);
    $form_state->setValue('templates', '');
    return $form['templates'];
  }

  /**
   * Get the content type from the form state.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return string
   *   The content type.
   */
  protected function getCurrentContentType(
    FormStateInterface $form_state
  ) : String {
    return $form_state->getValue('content_types') ?? 'page';
  }

  /**
   * Get the template options for the currrent content type.
   *
   * @return array
   *   The template options.
   */
  protected function getCurrentTemplates($content_type) : array {
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
    $filenames = $this->getSanitizedFilenamesFromPath($path);
    $templates = $this->constructTemplatesArrayFromFilenames($filenames);

    // Prepend the Empty case.
    foreach ($templates as $key => $template) {
      $templates[$key] = ['' => 'Empty'] + $template;
    }

    return $templates;
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
  protected function constructTemplatesArrayFromFilenames($filenames) : array {
    $templates = [];
    foreach ($filenames as $filename) {
      $filename = str_replace('.yml', '', $filename);
      $filename_parts = explode('__', $filename);
      // We should probably create a parser for this so we're not
      // primitively obsessing.
      $templates[$filename_parts[0]][$filename_parts[1]] = $this->humanReadable($filename_parts[1]);
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
  protected function getSanitizedFilenamesFromPath($path) : array {
    $filenames = scandir($path);
    // Remove . and .. from the array.
    $filenames = array_slice($filenames, 2);
    return $filenames;
  }

  /**
   * Make a string human readable.
   *
   * Given a string like 'this_is_a_string',
   * this will return 'This Is A String'.
   *
   * @param string $string
   *   The string.
   *
   * @return string
   *   The human readable string.
   */
  protected function humanReadable($string) : string {
    return ucwords(str_replace('_', ' ', $string));
  }

}
