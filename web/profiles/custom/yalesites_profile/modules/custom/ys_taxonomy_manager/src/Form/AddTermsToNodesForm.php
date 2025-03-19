<?php

namespace Drupal\ys_taxonomy_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\taxonomy\VocabularyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding taxonomy terms to nodes.
 */
class AddTermsToNodesForm extends FormBase {

  /**
   * Term management.
   *
   * @var \Drupal\taxonomy\TermStorageInterface
   */
  protected $termStorage;

  /**
   * The node storage.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected $nodeStorage;

  /**
   * The field config storage.
   *
   * @var \Drupal\Core\Config\ConfigStorageInterface
   */
  protected $fieldConfigStorage;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * AddTermsToNodesForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager) {
    $this->termStorage = $entityTypeManager->getStorage('taxonomy_term');
    $this->nodeStorage = $entityTypeManager->getStorage('node');
    $this->fieldConfigStorage = $entityTypeManager->getStorage('field_config');
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?VocabularyInterface $taxonomy_vocabulary = NULL, $selected_terms = []) {

    // Store the vocabulary in the form state, used for redirect back to the
    // vocabulary page.
    $form['voc'] = [
      '#type' => 'value',
      '#value' => $taxonomy_vocabulary,
    ];

    $form['selected_terms']['#tree'] = TRUE;

    // Only show nodes that have fields that reference the selected vocabulary.
    $nodeFieldDefinitions = $this->entityFieldManager->getFieldStorageDefinitions('node');
    // Get bundles that have fields that reference the selected vocabulary.
    $relevantBundles = [];
    foreach ($nodeFieldDefinitions as $fieldName => $fieldDefinition) {
      // Check if the field is an entity reference to taxonomy terms.
      if ($fieldDefinition->getType() === 'entity_reference' &&
          $fieldDefinition->getSetting('target_type') === 'taxonomy_term') {

        // Load the field config to check the vocabulary.
        $field_configs = $this->fieldConfigStorage->loadByProperties([
          'field_name' => $fieldName,
          'entity_type' => 'node',
        ]);

        foreach ($field_configs as $config) {
          $handler_settings = $config->getSetting('handler_settings');
          if (!empty($handler_settings['target_bundles']) &&
              in_array($taxonomy_vocabulary->id(), $handler_settings['target_bundles'])) {
            $relevantFields[] = $fieldName;
            $relevantBundles[] = $config->getTargetBundle();
          }
        }
      }
    }

    // Get nodes of the relevant bundles.
    $query = $this->nodeStorage->getQuery()
      ->condition('status', 1)
      ->condition('type', array_unique($relevantBundles), 'IN')
      ->accessCheck(TRUE);

    $nids = $query->execute();

    // If no nodes are found, show a message and return.
    if (empty($nids)) {
      $form['message'] = [
        '#type' => 'markup',
        '#markup' => $this->t('<br>No nodes found with fields that reference the selected vocabulary.'),
      ];
      return $form;
    }

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
      '#default_value' => $selected_terms ?: [],
    ];

    $nodes = $this->nodeStorage->loadMultiple($nids);

    $options = [];
    foreach ($nodes as $node) {
      // Find which of the relevant fields this node has.
      foreach ($relevantFields as $fieldName) {
        if ($node->hasField($fieldName)) {
          // Store node ID and field name in the key.
          $options[$node->id() . '-' . $fieldName] = $node->label() . ' (' . $fieldName . ')';
          break;
        }
      }
    }

    $form['nodes'] = [
      '#type' => 'select',
      '#title' => $this->t('Select nodes'),
      '#options' => $options,
      '#multiple' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#description' => $this->t('Select the nodes where you want to add the terms.'),
      '#chosen' => TRUE,
      '#attributes' => [
        'class' => ['chosen-select'],
      ],
    ];

    // Store relevant fields for use in submit handler.
    $form['#relevant_fields'] = $relevantFields;

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Terms'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $taxonomy_vocabulary = $form_state->getValue('voc');
    $selected_terms = array_filter($form_state->getValue('terms'));
    $selected_nodes = $form_state->getValue('nodes');

    // Load terms to get their names.
    $terms = $this->termStorage->loadMultiple($selected_terms);
    $term_names = [];
    foreach ($terms as $term) {
      $term_names[] = $term->label();
    }

    // Track node names.
    $node_names = [];

    foreach ($selected_nodes as $combined_key) {
      // Split the combined key back into node ID and field name.
      [$node_id, $fieldName] = explode('-', $combined_key);

      $node = $this->nodeStorage->load($node_id);
      $node_names[] = $node->label();

      // Get current field values.
      $current_values = [];
      if (!$node->get($fieldName)->isEmpty()) {
        $current_values = array_column($node->get($fieldName)->getValue(), 'target_id');
      }

      // Merge current values with new ones and remove duplicates.
      $merged_values = array_unique(array_merge($current_values, $selected_terms));

      // Set the merged values.
      $node->set($fieldName, $merged_values);
      $node->save();
    }

    $this->messenger()->addStatus($this->t('The terms %terms were added to nodes %nodes', [
      '%terms' => implode(', ', $term_names),
      '%nodes' => implode(', ', $node_names),
    ]));

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
