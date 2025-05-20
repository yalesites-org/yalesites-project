<?php

namespace Drupal\ys_unified_search\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Unified Search settings.
 */
class UnifiedSearchSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ys_unified_search.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_unified_search_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Use form_state for dynamic rows.
    if ($form_state->has('search_options')) {
      $search_options = $form_state->get('search_options');
    }
    else {
      $config = $this->config('ys_unified_search.settings');
      $search_options = $config->get('search_options') ?? [];
      // Filter out empty options when loading
      $search_options = array_filter($search_options, function($option) {
        return !empty($option['label']) && !empty($option['url']);
      });
      $form_state->set('search_options', array_values($search_options));
    }

    $form['search_options'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Search Label'),
        $this->t('Search URL'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('No search options configured.'),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'search-option-weight',
        ],
      ],
    ];

    foreach ($search_options as $key => $option) {
      $form['search_options'][$key] = [
        '#attributes' => ['class' => ['draggable']],
        'label' => [
          '#type' => 'textfield',
          '#default_value' => $option['label'] ?? '',
          '#required' => TRUE,
        ],
        'url' => [
          '#type' => 'textfield',
          '#default_value' => $option['url'] ?? '',
          '#required' => TRUE,
        ],
        'weight' => [
          '#type' => 'weight',
          '#title' => $this->t('Weight'),
          '#default_value' => $key,
          '#attributes' => ['class' => ['search-option-weight']],
        ],
        'delete' => [
          '#type' => 'submit',
          '#value' => $this->t('Delete'),
          '#name' => 'delete_' . $key,
          '#submit' => ['::deleteSearchOption'],
          '#ajax' => [
            'callback' => '::updateSearchOptions',
            'wrapper' => 'search-options-wrapper',
          ],
        ],
      ];
    }

    $form['add_option'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add Search Option'),
      '#submit' => ['::addSearchOption'],
      '#ajax' => [
        'callback' => '::updateSearchOptions',
        'wrapper' => 'search-options-wrapper',
      ],
    ];

    $form['#prefix'] = '<div id="search-options-wrapper">';
    $form['#suffix'] = '</div>';

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $search_options = [];
    if ($form_state->hasValue('search_options')) {
      foreach ($form_state->getValue('search_options') as $row) {
        if (!empty($row['label']) && !empty($row['url'])) {
          $search_options[] = [
            'label' => trim($row['label']),
            'url' => trim($row['url']),
          ];
        }
      }
    }
    
    $this->config('ys_unified_search.settings')
      ->set('search_options', $search_options)
      ->save();
    $form_state->set('search_options', $search_options);
    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler for adding a new search option.
   */
  public function addSearchOption(array &$form, FormStateInterface $form_state) {
    $search_options = $form_state->get('search_options') ?? [];
    $search_options[] = ['label' => '', 'url' => ''];
    $form_state->set('search_options', $search_options);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for deleting a search option.
   */
  public function deleteSearchOption(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $key = str_replace('delete_', '', $trigger['#name']);
    $search_options = $form_state->get('search_options') ?? [];
    unset($search_options[$key]);
    $form_state->set('search_options', array_values($search_options));
    $form_state->setRebuild();
  }

  /**
   * Ajax callback for updating the search options table.
   */
  public function updateSearchOptions(array &$form, FormStateInterface $form_state) {
    return $form;
  }

} 