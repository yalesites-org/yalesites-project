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

    // An int, not '': config/schema/ys_layouts.schema.yml declares 'divider'
    // as an integer for every layout this class backs, and a real checkbox
    // submission always resolves to 0/1 anyway. A section can also be written
    // straight from here without ever passing through the form -- a config
    // entity's default section, as core.entity_view_display.node.profile
    // .default.yml does for ys_layout_two_column -- and a string default there
    // fails strict schema checking on the type instead of on the missing
    // mapping. Both '' and 0 are falsy in Twig, so the templates'
    // `settings.divider ? 'true' : 'false'` is unaffected.
    return $configuration + [
      'divider' => 0,
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
    // Sections offer six color options, matching the block component pickers
    // (#1518). See ColorTokenResolver::getColorStylesForEntity() for which
    // palette slot each option resolves to. Labels are deliberately ordinals
    // rather than color names, matching every block-level picker
    // (ys_themes.component_overrides.yml): the underlying color differs per
    // global theme, so 'six' is light blue on Old Blues but red on It's Your
    // Yale.
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
        'five' => $this->t('Five'),
        'six' => $this->t('Six'),
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
   * Wraps the ColorTokenResolver processColorPicker method, which owns the
   * section layout mapping for the 'layout_section'/'ys_layout_options'
   * entity/bundle pair.
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

    // No mapping is passed from this plugin: getColorStylesForEntity() is the
    // single source of truth for which slot each option resolves to, and for
    // the full list of places that mapping must stay in sync with (the
    // '#options' array above among them).
    return $this->colorTokenResolver->processColorPicker(
      $element,
      $form_state,
      $complete_form,
      'layout_section',
      'ys_layout_options',
    );
  }

}
