<?php

namespace Drupal\ys_themes\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsSelectWidget;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ys_themes\ThemeSettingsManager;
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
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, ThemeSettingsManager $theme_settings_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
    $this->themeSettingsManager = $theme_settings_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('ys_themes.theme_settings_manager')
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
    $palette_options = [];
    foreach ($options as $key => $label) {
      if ($key !== '_none') {
        $palette_options[$key] = $label;
      }
    }

    // Get color styles based on entity type and bundle.
    $all_color_styles = $this->getColorStylesForEntity($entity_type, $bundle);

    // Get color styles for the current global theme.
    $color_styles = $all_color_styles[$global_theme] ?? $all_color_styles['one'] ?? [];

    // Use a process callback to add the palette UI after the element is processed.
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
    
    // Remove the palette data from the element (it was just for passing to this callback).
    unset($element['#palette_options']);
    unset($element['#global_theme']);
    unset($element['#selected_value']);
    unset($element['#color_styles']);
    
    // Ensure selected_value is a string for comparison.
    $selected_value_string = '';
    if (is_array($selected_value)) {
      $selected_value_string = !empty($selected_value) ? (string) reset($selected_value) : '';
    } elseif ($selected_value !== NULL) {
      $selected_value_string = (string) $selected_value;
    }
    
    // Ensure palette_options values are strings (not arrays).
    $safe_palette_options = [];
    foreach ($palette_options as $key => $label) {
      if (is_scalar($key)) {
        $safe_key = (string) $key;
        $safe_label = is_scalar($label) ? (string) $label : $safe_key;
        $safe_palette_options[$safe_key] = $safe_label;
      }
    }
    
    // Ensure color_styles values are arrays of strings.
    // Use the same keys as palette_options to ensure they match.
    $safe_color_styles = [];
    foreach ($safe_palette_options as $option_key => $option_label) {
      // Look for color_styles with this key.
      if (isset($color_styles[$option_key]) && is_array($color_styles[$option_key])) {
        $safe_style_values = [];
        foreach ($color_styles[$option_key] as $style_value) {
          if (is_scalar($style_value)) {
            $safe_style_values[] = (string) $style_value;
          }
        }
        $safe_color_styles[$option_key] = $safe_style_values;
      } else {
        // If not found, try to find it with the original key structure.
        foreach ($color_styles as $style_key => $style_values) {
          if (is_scalar($style_key) && (string) $style_key === $option_key && is_array($style_values)) {
            $safe_style_values = [];
            foreach ($style_values as $style_value) {
              if (is_scalar($style_value)) {
                $safe_style_values[] = (string) $style_value;
              }
            }
            $safe_color_styles[$option_key] = $safe_style_values;
            break;
          }
        }
      }
    }
    
    // Render the palette UI using the template.
    $palette_render = [
      '#theme' => 'component_color_picker',
      '#palette_options' => $safe_palette_options,
      '#global_theme' => $global_theme,
      '#selected_value' => $selected_value_string,
      '#color_styles' => $safe_color_styles,
    ];
    
    // Wrap the select element and add the palette UI.
    $element['#prefix'] = '<div class="component-color-picker-wrapper" style="position: relative;">';
    $element['#suffix'] = \Drupal::service('renderer')->render($palette_render) . '</div>';
    
    return $element;
  }

  /**
   * Gets color styles for a specific entity type and bundle.
   *
   * @param string|null $entity_type
   *   The entity type ID (e.g., 'block_content', 'node').
   * @param string|null $bundle
   *   The bundle name (e.g., 'quote_callout').
   *
   * @return array
   *   Array of color styles keyed by global theme, then by component option.
   */
  protected function getColorStylesForEntity($entity_type = NULL, $bundle = NULL) {
    $global_themes = ['one', 'two', 'three', 'four', 'five'];
    $all_color_styles = [];

    // Define color styles for specific entity types/bundles.
    // Default styles for quote_callout block content.
    if ($entity_type === 'block_content' && $bundle === 'quote_callout') {
      foreach ($global_themes as $global) {
        $all_color_styles[$global] = [
          'one' => [
            "var(--global-themes-{$global}-colors-slot-one)",
            "var(--global-themes-{$global}-colors-slot-eight)",
          ],
          'two' => [
            "var(--global-themes-{$global}-colors-slot-three)",
            "var(--global-themes-{$global}-colors-slot-seven)",
          ],
          'three' => [
            "var(--global-themes-{$global}-colors-slot-five)",
            "var(--global-themes-{$global}-colors-slot-eight)",
          ],
          'four' => [
            "var(--global-themes-{$global}-colors-slot-four)",
            "var(--global-themes-{$global}-colors-slot-seven)",
          ],
          'five' => [
            "var(--global-themes-{$global}-colors-slot-two)",
            "var(--global-themes-{$global}-colors-slot-eight)",
          ],
        ];
      }
    }
    elseif ($entity_type === 'block_content' && $bundle === 'content_spotlight') {
      foreach ($global_themes as $global) {
        $all_color_styles[$global] = [
          'one' => [
            "var(--global-themes-{$global}-colors-slot-one)",
            "var(--global-themes-{$global}-colors-slot-eight)",
          ],
          'two' => [
            "var(--global-themes-{$global}-colors-slot-four)",
            "var(--global-themes-{$global}-colors-slot-seven)",
          ],
          'three' => [
            "var(--global-themes-{$global}-colors-slot-five)",
            "var(--global-themes-{$global}-colors-slot-eight)",
          ],
          'four' => [
            "var(--global-themes-{$global}-colors-slot-three)",
            "var(--global-themes-{$global}-colors-slot-seven)",
          ],
          'five' => [
            "var(--global-themes-{$global}-colors-slot-two)",
            "var(--global-themes-{$global}-colors-slot-eight)",
          ],
        ];
      }
    }
    elseif ($entity_type === 'block_content' && $bundle === 'accordion') {
      foreach ($global_themes as $global) {
        // Three colors: accent, background, text.
        // See: molecules/accordion/_yds-accordion.scss.
        $all_color_styles[$global] = [
          'default' => [
            "var(--color-accordion-accent)",
            "var(--color-basic-white)",
            "var(--color-gray-800)",
          ],
          'one' => [
            "var(--global-themes-{$global}-colors-slot-one)",
            "var(--color-gray-100)",
            "var(--color-gray-800)",
          ],
          'two' => [
            "var(--global-themes-{$global}-colors-slot-two)",
            "var(--color-gray-100)",
            "var(--color-gray-800)",
          ],
          'three' => [
            "var(--global-themes-{$global}-colors-slot-three)",
            "var(--color-gray-100)",
            "var(--color-gray-800)",
          ],
          'four' => [
            "var(--global-themes-{$global}-colors-slot-four)",
            "var(--color-gray-100)",
            "var(--color-gray-800)",
          ],
          'five' => [
            "var(--global-themes-{$global}-colors-slot-five)",
            "var(--color-gray-100)",
            "var(--color-gray-800)",
          ],
        ];
      }
    }
    // Add more entity type/bundle specific styles here as needed.
    // Example:
    // elseif ($entity_type === 'block_content' && $bundle === 'other_bundle') {
    //   // Different color styles for other_bundle
    // }
    // Default fallback if no specific styles are defined.
    else {
      foreach ($global_themes as $global) {
        $all_color_styles[$global] = [
          'one' => [
            "var(--global-themes-{$global}-colors-slot-one)",
            "var(--global-themes-{$global}-colors-slot-eight)",
          ],
          'two' => [
            "var(--global-themes-{$global}-colors-slot-three)",
            "var(--global-themes-{$global}-colors-slot-seven)",
          ],
          'three' => [
            "var(--global-themes-{$global}-colors-slot-five)",
            "var(--global-themes-{$global}-colors-slot-eight)",
          ],
          'four' => [
            "var(--global-themes-{$global}-colors-slot-four)",
            "var(--global-themes-{$global}-colors-slot-seven)",
          ],
          'five' => [
            "var(--global-themes-{$global}-colors-slot-two)",
            "var(--global-themes-{$global}-colors-slot-eight)",
          ],
        ];
      }
    }

    return $all_color_styles;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // Ensure values are properly extracted.
    $massaged = parent::massageFormValues($values, $form, $form_state);
    return $massaged;
  }

}

