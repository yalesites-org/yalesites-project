<?php

namespace Drupal\ys_templated_content\Form;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\single_content_sync\ContentImporterInterface;
use Drupal\single_content_sync\ContentSyncHelperInterface;
use Drupal\ys_templated_content\Support\TemplateFilenameHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for allowing users to create nodes based on templated content.
 */
class TemplatedContentForm extends FormBase implements FormInterface {

  const PLACEHOLDER = 'public://templated-content-images/placeholder.png';

  /**
   * The template filename helper.
   *
   * @var \Drupal\ys_templated_content\Support\TemplateFilenameHelper
   */
  protected $templateFilenameHelper;

  /**
   * The templates that will be available to the user to select from.
   *
   * @var array
   */
  protected $templates = [];

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
   * @param \Drupal\ys_templated_content\Support\TemplateFilenameHelper $templateFilenameHelper
   *   The template filename helper.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    ContentImporterInterface $contentImporter,
    ContentSyncHelperInterface $contentSyncHelper,
    UuidInterface $uuidService,
    TemplateFilenameHelper $templateFilenameHelper,
  ) {
    $this->entityManager = $entityTypeManager;
    $this->contentImporter = $contentImporter;
    $this->contentSyncHelper = $contentSyncHelper;
    $this->uuidService = $uuidService;
    $this->templateFilenameHelper = $templateFilenameHelper;
    $this->templates = $this->refreshTemplates($this->templateFilenameHelper->getTemplateBasePath());
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
      $container->get('ys_templated_content.template_filename_helper'),
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
        $this->templateFilenameHelper->getImportFilePath($content_type, $template)
      );

      $content_array = $this
        ->contentSyncHelper
        ->validateYamlFileContent($content);

      $content_array = $this->modifyForAddition($content_array);

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
   * Modify the content array for addition.
   *
   * @param array $content_array
   *   The content array.
   */
  protected function modifyForAddition(array $content_array) : array {
    $content_array['uuid'] = $this->uuidService->generate();
    /* $content_array = $this->replaceBrokenImages($content_array); */

    return $content_array;
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
    $filenames = $this->templateFilenameHelper->getSanitizedFilenamesFromPath($path);
    $templates = $this->templateFilenameHelper->constructTemplatesArrayFromFilenames($filenames);

    // Prepend the Empty case.
    foreach ($templates as $key => $template) {
      $templates[$key] = ['' => 'Empty'] + $template;
    }

    return $templates;
  }

  /**
   * Replace broken images with a placeholder.
   *
   * @param array $content_array
   *   The content array.
   *
   * @return array
   *   The content array with images fixed with placeholder.
   */
  protected function replaceBrokenImages(array $content_array) : array {
    foreach ($content_array as $key => $value) {
      if (is_array($value)) {
        $content_array[$key] = $this->replaceBrokenImages($value);
      }
      elseif ($key == 'uri') {
        $path = $value;
        $path = str_replace('public://', 'sites/default/files/', $path);
        if (!file_exists($path)) {
          $content_array[$key] = $this::PLACEHOLDER;
        }
      }
    }

    return $content_array;
  }

}
