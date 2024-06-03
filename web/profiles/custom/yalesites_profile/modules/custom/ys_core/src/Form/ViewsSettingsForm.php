<?php

namespace Drupal\ys_core\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for managing views settings.
 *
 * @package Drupal\ys_core\Form
 */
class ViewsSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  final public function __construct(
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ys_core_views_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ys_core.views_settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ys_core.views_settings');

    $form['#attached']['library'][] = 'core/drupal.vertical-tabs';
    $form['vertical_tabs'] = [
      '#type' => 'vertical_tabs',
      '#default_tab' => 'edit-first',
    ];

    // Search views settings group.
    $form['search'] = [
      '#type' => 'details',
      '#title' => $this->t('Search View'),
      '#group' => 'vertical_tabs',
    ];

    $form['search']['show_content_type_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the <strong>Content Type</strong> filter '),
      '#default_value' => $config->get('show_content_type_filter'),
      '#description' => $this->t('Enable to display the <em>Content Type</em> filter that will be shown on the <em>Search</em> page.'),
    ];

    $form['search']['content_type_list'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content Types'),
      '#default_value' => $config->get('content_type_list') ?? [],
      '#options' => $this->getContentTypeOptions(),
      '#description' => $this->t('Select the content types that will be visible in Content Type select filter.'),
      '#states' => [
        'visible' => [
          ':input[name="show_content_type_filter"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ys_core.views_settings')
      ->set('show_content_type_filter', $form_state->getValue('show_content_type_filter'))
      ->set('content_type_list', array_filter($form_state->getValue('content_type_list')))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Gets the content type options.
   *
   * @return array
   *   An associative array of content type machine names and labels.
   */
  protected function getContentTypeOptions(): array {
    /** @var \Drupal\node\NodeTypeInterface $content_types*/
    $content_types = $this->entityTypeManager
      ->getStorage('node_type')
      ->loadMultiple();

    // Create an array of content type labels indexed by their machine names.
    $options = [];
    foreach ($content_types as $type) {
      $options[$type->id()] = $type->label();
    }

    return $options;
  }

}
