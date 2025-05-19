<?php

namespace Drupal\ys_unified_search\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a unified search block.
 *
 * @Block(
 *   id = "yale_unified_search_block",
 *   admin_label = @Translation("Yale Unified Search"),
 *   category = @Translation("YaleSites")
 * )
 */
class YaleUnifiedSearchBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'search_options' => [
        [
          'label' => 'This Site',
          'url' => 'https://library.yale.edu/search/google/{{query}}',
        ],
        [
          'label' => 'All Resources',
          'url' => 'https://search.library.yale.edu/quicksearch?q={{query}}&commit=Search',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['search_options'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Label'),
        $this->t('URL'),
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

    $search_options = $config['search_options'] ?? $this->defaultConfiguration()['search_options'];
    foreach ($search_options as $delta => $option) {
      $form['search_options'][$delta] = [
        'label' => [
          '#type' => 'textfield',
          '#default_value' => $option['label'],
          '#required' => TRUE,
        ],
        'url' => [
          '#type' => 'textfield',
          '#default_value' => $option['url'],
          '#required' => TRUE,
          '#description' => $this->t('Use {{query}} as a placeholder for the search term.'),
        ],
        'weight' => [
          '#type' => 'weight',
          '#title' => $this->t('Weight'),
          '#default_value' => $delta,
          '#delta' => 50,
          '#title_display' => 'invisible',
          '#attributes' => ['class' => ['search-option-weight']],
        ],
      ];
    }

    $form['add_option'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add another option'),
      '#submit' => [[$this, 'addOptionSubmit']],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Submit handler for adding a new search option.
   */
  public function addOptionSubmit(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild();
    $config = $this->getConfiguration();
    $config['search_options'][] = [
      'label' => '',
      'url' => '',
    ];
    $this->setConfiguration($config);
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['search_options'] = $form_state->getValue('search_options');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->getConfiguration();
    $search_options = $config['search_options'] ?? $this->defaultConfiguration()['search_options'];

    return [
      '#theme' => 'block__unified_search_block',
      '#settings' => [
        'search_options' => $search_options,
      ],
      '#attached' => [
        'library' => [
          'ys_unified_search/unified-search',
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
      'content' => [
        '#theme' => 'block__unified_search_block',
        '#settings' => [
          'search_options' => $search_options,
        ],
      ],
    ];
  }

} 