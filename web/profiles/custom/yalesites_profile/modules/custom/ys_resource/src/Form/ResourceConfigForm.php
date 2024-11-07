<?php

namespace Drupal\ys_resource\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a configuration form for resource settings.
 */
class ResourceConfigForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ResourceConfigForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ys_resource.resource_config'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'resource_config_form';
  }

  /**
   * Builds the configuration form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ys_resource.resource_config');

    // Load available terms for Category and Tags vocabularies.
    $category_options = $this->getVocabularyTerms('page_category');
    $tags_options = $this->getVocabularyTerms('tags');
    $page_type_options = $this->getVocabularyTerms('page_type');

    // Category term reference field.
    $form['page_type_term'] = [
      '#type' => 'select',
      '#title' => $this->t('Page Type'),
      '#options' => $page_type_options,
      '#default_value' => $config->get('page_type_term'),
      '#description' => $this->t('Select the Resource term for Page type.'),
      '#required' => TRUE,
    ];

    $form['category_parent_term'] = [
      '#type' => 'select',
      '#title' => $this->t('Parent Category Term'),
      '#options' => $category_options,
      '#default_value' => $config->get('category_parent_term'),
      '#description' => $this->t('Select the parent term for Category.'),
      '#required' => TRUE,
    ];

    // Tags term reference field.
    $form['tags_parent_term'] = [
      '#type' => 'select',
      '#title' => $this->t('Parent Tags Term'),
      '#options' => $tags_options,
      '#default_value' => $config->get('tags_parent_term'),
      '#description' => $this->t('Select the parent term for Tags.'),
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Fetches terms from a vocabulary.
   */
  protected function getVocabularyTerms($vocabulary_id) {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree($vocabulary_id);
    $options = [];
    foreach ($terms as $term) {
      $options[$term->tid] = $term->name;
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('ys_resource.resource_config')
      ->set('category_parent_term', $form_state->getValue('category_parent_term'))
      ->set('tags_parent_term', $form_state->getValue('tags_parent_term'))
      ->set('page_type_term', $form_state->getValue('page_type_term'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
