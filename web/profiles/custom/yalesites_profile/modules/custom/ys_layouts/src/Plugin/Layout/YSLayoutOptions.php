<?php

namespace Drupal\ys_layouts\Plugin\Layout;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Layout\LayoutDefault;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
   * Builds the Section Padding Options select, shared with Page Meta.
   *
   * Used by the Configure Section form alter in ys_layouts.module and by the
   * Page Meta block form. Callers add their own weight and wrapper.
   *
   * @param string $default_value
   *   The currently selected option key.
   *
   * @return array
   *   The select form element.
   */
  public static function sectionPaddingElement(string $default_value): array {
    return [
      '#type' => 'select',
      '#title' => new TranslatableMarkup('Section Padding Options'),
      '#options' => [
        'default' => new TranslatableMarkup('Padding on both top and bottom'),
        'no_top' => new TranslatableMarkup('No top padding'),
        'no_bottom' => new TranslatableMarkup('No bottom padding'),
        'no_padding' => new TranslatableMarkup('No padding (removes both top and bottom padding)'),
      ],
      '#default_value' => $default_value,
      '#description' => new TranslatableMarkup("To create connected sections, use 'No bottom padding' on the first section and 'No top padding' on the section below it. 'Padding on both top and bottom' maintains standard spacing for optimal readability."),
    ];
  }

  /**
   * Builds the Section Theme select, shared with the Page Meta block form.
   *
   * Callers add their own weight and the '#after_build' that attaches the
   * swatch picker.
   *
   * @param string $default_value
   *   The currently selected option key.
   *
   * @return array
   *   The select form element.
   */
  public static function sectionThemeElement(string $default_value): array {
    // Sections offer six color options, matching the block component pickers
    // (#1518). See ColorTokenResolver::getColorStylesForEntity() for which
    // palette slot each option resolves to. Labels are deliberately ordinals
    // rather than color names, matching every block-level picker
    // (ys_themes.component_overrides.yml): the underlying color differs per
    // global theme, so 'six' is light blue on Old Blues but red on It's Your
    // Yale.
    return [
      '#type' => 'select',
      '#title' => new TranslatableMarkup('Section Theme'),
      '#default_value' => $default_value,
      '#options' => [
        'default' => new TranslatableMarkup('Default - no color'),
        'one' => new TranslatableMarkup('One'),
        'two' => new TranslatableMarkup('Two'),
        'three' => new TranslatableMarkup('Three'),
        'four' => new TranslatableMarkup('Four'),
        'five' => new TranslatableMarkup('Five'),
        'six' => new TranslatableMarkup('Six'),
      ],
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
    $form['theme'] = static::sectionThemeElement($saved_theme) + [
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
    array $element,
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
