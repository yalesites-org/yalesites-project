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
  public function buildForm(array $form, FormStateInterface $_form_state) {
    /* $form['#theme'] = 'ys_templated_content'; */
    $form['content_types'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#options' => $this->getContentTypes(),
      '#required' => TRUE,
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
    $content_type = $form_state->getValue('content_types');
    $form_state->setRedirect('node.add', ['node_type' => $content_type]);
  }

  /**
   * Get all content types.
   */
  private function getContentTypes() {
    $content_types = $this->entityManager->getStorage('node_type')->loadMultiple();
    $options = [];
    foreach ($content_types as $content_type) {
      $options[$content_type->id()] = $content_type->label();
    }
    return $options;
  }

}
