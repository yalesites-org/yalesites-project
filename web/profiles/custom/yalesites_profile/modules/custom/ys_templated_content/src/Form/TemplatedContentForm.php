<?php

namespace Drupal\ys_templated_content\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\ys_templated_content\Managers\ImportManager;
use Drupal\ys_templated_content\Managers\TemplateManager;
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
   * The content type.
   *
   * @var string
   */
  protected $contentType;

  /**
   * Constructs the controller object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager to get the content types available.
   * @param \Drupal\ys_templated_content\Managers\TemplateManager $templateManager
   *   The template manager.
   * @param \Drupal\ys_templated_content\Managers\ImportManager $importManager
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
  public function buildForm(array $form, FormStateInterface $form_state, string $content_type = NULL) {
    $this->contentType = $form_state->get('content_types') ?? $content_type ?? 'page';

    $form['#title'] = $this->getTitle();

    $form['local_tasks'] = [
      '#theme' => 'menu_local_tasks',
      '#primary' => $this->getContentTypeLocalMenu(),
    ];

    $form['content_types'] = [
      '#type' => 'hidden',
      '#title' => $this->t('Content Type'),
      '#options' => $this->getContentTypes(),
      '#required' => TRUE,
      '#default_value' => $content_type ?? 'page',
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
      '#options' => $this->getCurrentTemplateOptions($this->contentType),
      '#required' => FALSE,
      '#prefix' => '<div id="template-update-wrapper">',
      '#suffix' => '</div>',
      '#ajax' => [
        'callback' => [$this, 'updateDescription'],
        'wrapper' => 'template-description-wrapper',
        'disable-refocus' => FALSE,
        'event' => 'change',
        'progress' => [
          'type' => 'none',
        ],
      ],
    ];

    $form['template_description'] = [
      '#type' => 'item',
      '#markup' => $this->templateManager->getTemplateDescription($this->contentType, ''),
      '#prefix' => '<div id="template-description-wrapper">',
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
        if ($entity) {
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
      ->getCurrentTemplateOptions($currentContentType);
    $form_state->setValue('templates', '');

    return $form['templates'];
  }

  /**
   * Update the template description when the template changes.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The updated template description.
   */
  public function updateDescription(
    array &$form,
    FormStateInterface $form_state
  ) : array {
    $currentContentType = $this->getCurrentContentType($form_state);
    $currentTemplate = $form_state->getValue('templates');
    $form['template_description']['#markup'] = $this
      ->templateManager->getTemplateDescription($currentContentType, $currentTemplate);
    return $form['template_description'];
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
    $content_types_with_templates = array_keys($this->templateManager->getTemplates());
    // Get all content types that match the content types with templates.
    $content_types = $this
      ->entityManager
      ->getStorage('node_type')
      ->loadByProperties(['type' => $content_types_with_templates]);
    $options = [];

    foreach ($content_types as $content_type) {
      $options[$content_type->id()] = $content_type->label();
    }

    return $options;
  }

  /**
   * Get the template options for the currrent content type.
   *
   * @param string $content_type
   *   The content type to get templates for.
   *
   * @return array
   *   The template options.
   */
  private function getCurrentTemplateOptions($content_type) {
    $keyValuePairs = [];
    $templates = $this->templateManager->getTemplates($content_type);
    foreach ($templates as $key => $template) {
      $keyValuePairs[$key] = $template['title'];
    }

    return $keyValuePairs;
  }

  /**
   * {@inheritdoc}
   */
  public function getTitle() {
    if ($this->contentType) {
      return 'Create a ' . $this->contentType;
    }

    return 'Create from template';
  }

  /**
   * Get the local menu for content types.
   *
   * @return array
   *   The local menu array.
   */
  protected function getContentTypeLocalMenu() {
    $content_types = $this->entityManager->getStorage('node_type')->loadMultiple();
    uasort($content_types, function ($a, $b) {
      return strcasecmp($a->label(), $b->label());
    });

    $menu = [];
    foreach ($content_types as $content_type) {
      $type = $content_type->id();
      $menu[$type] = [
        '#type' => 'link',
        '#link' => [
          'title' => $content_type->label(),
          'url' => Url::fromRoute('ys_templated_content.selection', ['content_type' => $type]),
        ],
        '#level' => 'primary',
        '#theme' => 'menu_local_task__navigation',
        '#active' => $this->contentType == $type,
      ];
    }

    return $menu;
  }

}
