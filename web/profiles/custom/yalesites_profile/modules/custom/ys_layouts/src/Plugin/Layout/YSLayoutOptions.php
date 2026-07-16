<?php

namespace Drupal\ys_layouts\Plugin\Layout;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Layout\LayoutDefault;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\ys_themes\ColorTokenResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration per section for YS Layouts.
 */
class YSLayoutOptions extends LayoutDefault implements ContainerFactoryPluginInterface {

  /**
   * The color token resolver.
   *
   * @var \Drupal\ys_themes\ColorTokenResolver
   */
  protected ColorTokenResolver $colorTokenResolver;

  /**
   * Constructs a YSLayoutOptions object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\ys_themes\ColorTokenResolver $color_token_resolver
   *   The color token resolver service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ColorTokenResolver $color_token_resolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->colorTokenResolver = $color_token_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // @phpstan-ignore-next-line
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ys_themes.color_token_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $configuration = parent::defaultConfiguration();

    return $configuration + [
      'divider' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['divider'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Divider'),
      '#default_value' => $this->configuration['divider'],
      '#description' => $this->t('Add a divider between the columns.'),
      '#weight' => 10,
    ];

    // Use the saved theme value directly from configuration.
    $saved_theme = $this->configuration['theme'] ?? 'default';
    $form['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Component theme'),
      '#default_value' => $saved_theme,
      '#options' => [
        'default' => $this->t('Default - no color'),
        'one' => $this->t('One'),
        'two' => $this->t('Two'),
        'three' => $this->t('Three'),
        'four' => $this->t('Four'),
      ],
      '#weight' => 10,
      '#after_build' => [
        [$this, 'processColorPicker'],
      ],
    ];

    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $this->configuration['divider'] = $form_state->getValue('divider');

    // Save the theme value directly from the form.
    $this->configuration['theme'] = $form_state->getValue('theme');
  }

  /**
   * After build callback to add the color picker palette UI.
   *
   * Wraps the ColorTokenResolver processColorPicker method with the section
   * layout mapping: one=Blue Yale, two=Gray 100, three=Gray 800,
   * four=Blue Medium, five=Blue Light.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The processed form element.
   */
  public function processColorPicker(
    array &$element,
    FormStateInterface $form_state,
  ) {
    // Get the complete form from form state (required for after_build).
    $complete_form = $form_state->getCompleteForm();

    // Use section layout mapping: one→slot-one, two→slot-three,
    // three→slot-two, four→slot-five, five→slot-four.
    // This gives: one=Blue Yale, two=Gray 100, three=Gray 800,
    // four=Blue Medium, five=Blue Light.
    return $this->colorTokenResolver->processColorPicker(
      $element,
      $form_state,
      $complete_form,
      'layout_section',
      'ys_layout_options',
    );
  }

}
