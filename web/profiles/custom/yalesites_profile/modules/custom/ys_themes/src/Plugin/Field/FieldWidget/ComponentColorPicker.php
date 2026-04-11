<?php

namespace Drupal\ys_themes\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_themes\ThemeSettingsManager;
use Drupal\ys_themes\ColorTokenResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'component_color_picker' widget.
 */
#[FieldWidget(
  id: 'component_color_picker',
  label: new TranslatableMarkup('Component Color Picker'),
  field_types: [
    'entity_reference',
    'list_integer',
    'list_float',
    'list_string',
  ],
  multiple_values: TRUE,
)]
class ComponentColorPicker extends OptionsSelectWidget implements ContainerFactoryPluginInterface {

  /**
   * The theme settings manager service.
   *
   * @var \Drupal\ys_themes\ThemeSettingsManager
   */
  protected $themeSettingsManager;

  /**
   * The color token resolver service.
   *
   * @var \Drupal\ys_themes\ColorTokenResolver
   */
  protected $colorTokenResolver;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a ComponentColorPicker widget.
   *
   * @param string $plugin_id
   *   The plugin_id for the widget.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the widget is associated.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\ys_themes\ThemeSettingsManager $theme_settings_manager
   *   The theme settings manager service.
   * @param \Drupal\ys_themes\ColorTokenResolver $color_token_resolver
   *   The color token resolver service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, ThemeSettingsManager $theme_settings_manager, ColorTokenResolver $color_token_resolver, RendererInterface $renderer) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->themeSettingsManager = $theme_settings_manager;
    $this->colorTokenResolver = $color_token_resolver;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // @phpstan-ignore-next-line
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('ys_themes.theme_settings_manager'),
      $container->get('ys_themes.color_token_resolver'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);

    // Get the current global theme value.
    $global_theme = $this->themeSettingsManager->getSetting('global_theme') ?? 'one';

    // Get the entity to determine entity type and bundle.
    $entity = $items->getEntity();
    $entity_type = $entity ? $entity->getEntityTypeId() : NULL;
    $bundle = $entity ? $entity->bundle() : NULL;

    // Get the available options from the field.
    $options = $this->getOptions($entity);
    $selected_value = $this->getSelectedOptions($items);
    $selected_value = is_array($selected_value) ? reset($selected_value) : $selected_value;

    // Hide the select element visually.
    $element['#attributes']['class'][] = 'palette-select-hidden';
    $element['#attributes']['style'] = 'position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; overflow: hidden;';

    // Filter out the _none option for palette display.
    $palette_options = array_filter($options, function ($key) {
      return $key !== '_none';
    }, ARRAY_FILTER_USE_KEY);

    // Default to 'default' if no value is selected and 'default' exists.
    if (empty($selected_value) && isset($palette_options['default'])) {
      $selected_value = 'default';
      // Set the default value on the element.
      $element['#default_value'] = 'default';
    }

    // Get color styles based on entity type and bundle using the service.
    $all_color_styles = $this->colorTokenResolver->getColorStylesForEntity($entity_type, $bundle);

    // Get color styles for the current global theme.
    $color_styles = $all_color_styles[$global_theme] ?? $all_color_styles['one'] ?? [];

    // Use a process callback to add the palette UI after the element is
    // processed.
    $element['#process'][] = [
      $this,
      'processColorPicker',
    ];

    // Store the palette data for the process callback.
    $element['#palette_options'] = $palette_options;
    $element['#global_theme'] = $global_theme;
    $element['#selected_value'] = $selected_value;
    $element['#color_styles'] = $color_styles;

    // Attach the library.
    $element['#attached']['library'][] = 'ys_themes/component_color_picker';

    return $element;
  }

  /**
   * Process callback to add the palette UI wrapper.
   */
  public function processColorPicker(array &$element, FormStateInterface $form_state, array &$complete_form) {
    // Extract palette data.
    $palette_options = $element['#palette_options'] ?? [];
    $global_theme = $element['#global_theme'] ?? 'one';
    $selected_value = $element['#selected_value'] ?? NULL;
    $color_styles = $element['#color_styles'] ?? [];

    // Remove the palette data from the element.
    unset($element['#palette_options'], $element['#global_theme'], $element['#selected_value'], $element['#color_styles']);

    // Normalize selected value to string.
    $selected_value_string = '';
    if (is_array($selected_value)) {
      $selected_value_string = !empty($selected_value) ? (string) reset($selected_value) : '';
    }
    elseif ($selected_value !== NULL) {
      $selected_value_string = (string) $selected_value;
    }

    // Ensure palette_options values are strings.
    $safe_palette_options = [];
    foreach ($palette_options as $key => $label) {
      if (is_scalar($key)) {
        $safe_palette_options[(string) $key] = is_scalar($label) ? (string) $label : (string) $key;
      }
    }

    // Default to 'default' if no value is selected and 'default' exists.
    if (empty($selected_value_string) && isset($safe_palette_options['default'])) {
      $selected_value_string = 'default';
    }

    // Build color information using ColorTokenResolver.
    $color_info = [];
    foreach ($safe_palette_options as $option_key => $option_label) {
      $background_color_var = $color_styles[$option_key][0] ?? NULL;
      $color_info[$option_key] = $this->colorTokenResolver->buildColorInfo($option_key, $background_color_var);
    }

    // Render the palette UI.
    $palette_render = [
      '#theme' => 'component_color_picker',
      '#palette_options' => $safe_palette_options,
      '#global_theme' => $global_theme,
      '#selected_value' => $selected_value_string,
      '#color_info' => $color_info,
    ];

    $element['#prefix'] = '<div class="component-color-picker-wrapper" style="position: relative;">';
    $element['#suffix'] = $this->renderer->render($palette_render) . '</div>';

    return $element;
  }

}
