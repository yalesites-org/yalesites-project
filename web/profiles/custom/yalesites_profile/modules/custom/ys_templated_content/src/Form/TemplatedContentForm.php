<?php

namespace Drupal\ys_templated_content\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ys_templated_content\ImportManager;
use Drupal\ys_templated_content\TemplateManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for allowing users to create nodes based on templated content.
 */
class TemplatedContentForm extends FormBase implements FormInterface {

  const PLACEHOLDER = 'public://templated-content-images/placeholder.png';

  /**
   * The template manager.
   *
   * @var \Drupal\ys_templated_content\TemplateManager
   */
  protected $templateManager;

  /**
   * The templates that will be available to the user to select from.
   *
   * @var array
   */
  protected $templates = [];

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;


  /**
   * Allows us to create an import of a template.
   *
   * @var \Drupal\ys_templated_content\ImportManager
   */
  protected $importManager;

  /**
   * Constructs the controller object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager to get the content types available.
   * @param \Drupal\ys_templated_content\TemplateManager $templateManager
   *   The template manager.
   * @param \Drupal\ys_templated_content\ImportManager $importManager
   *   The import manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    TemplateManager $templateManager,
    ImportManager $importManager,
  ) {
    $this->entityManager = $entityTypeManager;
    $this->templateManager = $templateManager;
    $this->importManager = $importManager;
    $this->templates = $this->templateManager->reload();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('ys_templated_content.template_manager'),
      $container->get('ys_templated_content.import_manager'),
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
      '#options' => $this->templateManager->getCurrentTemplates($currentContentType),
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
      try {
        $entity = $this->importManager->createImport($content_type, $template);
        $this->messenger()->addMessage("Content generated successfully.  Please make any edits now as this has already been created for you.  Don't forget to change the URL alias.");

        // Noticed that when you update a node, a log is created.
        // Figured we need to also have a log showing it was imported.
        $this->logger('ys_templated_content')->notice(
        'Templated content created: @label (@type)',
        [
          '@label' => $entity->label(),
          '@type' => $entity->getEntityTypeId(),
        ]
        );
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
      ->templateManager->getCurrentTemplates($currentContentType);
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

}
