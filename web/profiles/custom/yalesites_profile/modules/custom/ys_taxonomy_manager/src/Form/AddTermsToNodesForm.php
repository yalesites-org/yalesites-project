<?php

namespace Drupal\ys_taxonomy_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\TermStorage;
use Drupal\taxonomy\VocabularyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for exporting given terms.
 */
class AddTermsToNodesForm extends FormBase {

  /**
   * Term management.
   *
   * @var \Drupal\taxonomy\TermStorageInterface
   */
  protected $termStorage;

  /**
   * ExportTermsForm constructor.
   *
   * @param \Drupal\taxonomy\TermStorage $termStorage
   *   Object with convenient methods to manage terms.
   */
  public function __construct(TermStorage $termStorage) {
    $this->termStorage = $termStorage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')->getStorage('taxonomy_term')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?VocabularyInterface $taxonomy_vocabulary = NULL, $selected_terms = []) {

    $form['voc'] = [
      '#type' => 'value',
      '#value' => $taxonomy_vocabulary,
    ];
    $form['selected_terms']['#tree'] = TRUE;

    // Load tree.
    $tree = $this->termStorage->loadTree($taxonomy_vocabulary->id());
    $result = [];
    foreach ($tree as $term) {
      $result[$term->tid] = str_repeat('-', $term->depth) . $term->name;
    }
    $desc = 'Child terms are prefixed with dashes.<br>';

    $form['terms'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Terms to add'),
      '#options' => $result,
      '#multiple' => TRUE,
      '#prefix' => '<div id="export-wrapper">',
      '#suffix' => '</div>',
      '#description' => $desc,
    ];
    // Get all field definitions that reference taxonomy terms.
    $entityFieldManager = \Drupal::service('entity_field.manager');
    $nodeFieldDefinitions = $entityFieldManager->getFieldStorageDefinitions('node');

    // Find fields that reference our vocabulary.
    $field_options = [];
    foreach ($nodeFieldDefinitions as $fieldName => $fieldDefinition) {
      if ($fieldDefinition->getType() === 'entity_reference' &&
          $fieldDefinition->getSetting('target_type') === 'taxonomy_term' &&
          $fieldDefinition->getSetting('handler_settings')['target_bundles'][$taxonomy_vocabulary->id()]) {
        $field_options[$fieldName] = $fieldName;
      }
    }

    $form['field_selection'] = [
      '#type' => 'select',
      '#title' => $this->t('Select field'),
      '#options' => $field_options,
      '#empty_option' => $this->t('- Select a field -'),
      '#ajax' => [
        'callback' => '::updateNodeList',
        'wrapper' => 'node-list-wrapper',
      ],
    ];

    $form['node_selection_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'node-list-wrapper'],
    ];

    // Only load nodes if a field is selected.
    $selected_field = $form_state->getValue('field_selection');
    if ($selected_field) {
      // Get all nodes that have this field.
      $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
      $query = $nodeStorage->getQuery()
        ->condition('status', 1)
        ->condition($selected_field, NULL, 'EXISTS')
        ->accessCheck(TRUE);

      $nids = $query->execute();
      $nodes = $nodeStorage->loadMultiple($nids);

      $options = [];
      foreach ($nodes as $node) {
        $options[$node->id()] = $node->label();
      }

      $form['node_selection_wrapper']['nodes'] = [
        '#type' => 'select',
        '#title' => $this->t('Select nodes'),
        '#options' => $options,
        '#multiple' => TRUE,
        '#empty_option' => $this->t('- Select -'),
        '#description' => $this->t('Select the nodes where you want to add the terms.'),
      ];
    }

    return $form;
  }

  /**
   * Ajax callback to update the node list based on field selection.
   */
  public function updateNodeList(array &$form, FormStateInterface $form_state) {
    return $form['node_selection_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $taxonomy_vocabulary = $form_state->getValue('voc');
    $form_state->setRedirect(
      'taxonomy_manager.admin_vocabulary',
      ['taxonomy_vocabulary' => $taxonomy_vocabulary->id()]
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_taxonomy_manager_add_terms_to_nodes_form';
  }

}
