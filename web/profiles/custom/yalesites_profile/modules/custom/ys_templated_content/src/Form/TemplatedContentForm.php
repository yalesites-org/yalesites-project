<?php

namespace Drupal\ys_templated_content\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for allowing users to create nodes based on templated content.
 */
class TemplatedContentForm extends FormBase implements FormInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * The available templates.
   *
   * @var array
   */
  protected const TEMPLATES = [
    'page' => [
      '' => 'Empty',
      'faq' => 'FAQ',
      'landing_page' => 'Landing Page',
    ],
    'event' => [
      '' => 'Empty',
      'in_person' => 'In Person',
      'online' => 'Online',
    ],
    'post' => [
      '' => 'Empty',
      'blog' => 'Blog',
      'news' => 'News',
      'press_release' => 'Press Release',
    ],
    'profile' => [
      '' => 'Empty',
      'student' => 'Student',
      'faculty' => 'Faculty',
      'staff' => 'Staff',
    ],
  ];

  /**
   * Constructs the controller object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager to get the content types available.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
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
    $form_state->setRedirect('node.add', ['node_type' => $content_type]);
  }

  /**
   * Get all content types.
   *
   * @return array
   *   The content types.
   */
  private function getContentTypes() {
    $content_types = $this->entityManager->getStorage('node_type')->loadMultiple();
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
  public function updateTemplates(array &$form, FormStateInterface $form_state) {
    $currentContentType = $this->getCurrentContentType($form_state);
    $form['templates']['#options'] = $this->getCurrentTemplates($currentContentType);
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
  protected function getCurrentContentType($form_state) {
    return $form_state->getValue('content_types') ?? 'page';
  }

  /**
   * Get the template options for the currrent content type.
   *
   * @return array
   *   The template options.
   */
  protected function getCurrentTemplates($content_type) {
    $templates = [];

    // Return an empty array if there is no content type.
    if ($content_type) {
      $templates = self::TEMPLATES[$content_type];
    }

    return $templates;
  }

}
