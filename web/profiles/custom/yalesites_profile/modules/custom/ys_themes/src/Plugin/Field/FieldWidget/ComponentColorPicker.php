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

    // Get the available options from the field.
    $options = $this->getOptions($items->getEntity());
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

    // Build array of all color styles for all global themes.
    // First index is the global theme name.
    // Second index is the component option name.
    $global_themes = ['one', 'two', 'three', 'four', 'five'];
    $all_color_styles = [];
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

    // Get color styles for the current global theme.
    $color_styles = $all_color_styles[$global_theme] ?? $all_color_styles['one'];

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
    
    // Build the palette HTML directly in PHP - simpler and more reliable than template.
    \Drupal::logger('ys_themes')->debug('Building palette HTML directly in PHP');
    \Drupal::logger('ys_themes')->debug('Color styles available for keys: @keys', [
      '@keys' => implode(', ', array_keys($safe_color_styles)),
    ]);
    
    $button_html = '<button type="button" class="expand-indicator" data-expand-button aria-label="' . htmlspecialchars((string) $this->t('Show all palettes'), ENT_QUOTES, 'UTF-8') . '">';
    $button_html .= '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">';
    $button_html .= '<path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>';
    $button_html .= '</svg></button>';
    
    $palette_options_html = '';
    $total_circles = 0;
    foreach ($safe_palette_options as $option_key => $option_label) {
      $is_selected = ($selected_value_string === $option_key) ? 'true' : 'false';
      $escaped_key = htmlspecialchars($option_key, ENT_QUOTES, 'UTF-8');
      $escaped_label = htmlspecialchars($option_label, ENT_QUOTES, 'UTF-8');
      
      // Build color circles for this option.
      $circles_html = '';
      if (isset($safe_color_styles[$option_key]) && is_array($safe_color_styles[$option_key])) {
        foreach ($safe_color_styles[$option_key] as $color_style) {
          $safe_color = is_scalar($color_style) ? (string) $color_style : '';
          if (!empty($safe_color)) {
            $escaped_color = htmlspecialchars($safe_color, ENT_QUOTES, 'UTF-8');
            // Use data-color attribute instead of inline style to avoid Drupal sanitization
            $circles_html .= '<span class="palette-circle" data-color="' . $escaped_color . '"></span>';
            $total_circles++;
          }
        }
        \Drupal::logger('ys_themes')->debug('Palette @key: added @count circles', [
          '@key' => $option_key,
          '@count' => substr_count($circles_html, 'palette-circle'),
        ]);
      } else {
        \Drupal::logger('ys_themes')->debug('Palette @key: no color styles found', ['@key' => $option_key]);
      }
      
      $palette_options_html .= '<div class="palette-option" data-palette="' . $escaped_key . '" data-selected="' . $is_selected . '">';
      $palette_options_html .= '<div class="palette-circles">' . $circles_html . '</div>';
      $palette_options_html .= '</div>';
    }
    
    // Build the complete palette HTML.
    $palette_html = '<div class="palette-selector" data-palette-selector>';
    $palette_html .= '<div class="palette-visual-container" data-palette-container>';
    $palette_html .= $button_html;
    $palette_html .= $palette_options_html;
    $palette_html .= '</div>';
    $palette_html .= '</div>';
    
    \Drupal::logger('ys_themes')->debug('Built palette HTML - Total circles: @count, Has button: @button, Has colors: @colors', [
      '@count' => $total_circles,
      '@button' => strpos($palette_html, 'data-expand-button') !== FALSE ? 'yes' : 'no',
      '@colors' => strpos($palette_html, 'background-color:') !== FALSE ? 'yes' : 'no',
    ]);
    
    // Log a sample of the actual HTML to verify it's correct
    $sample_html = substr($palette_html, 0, 800);
    \Drupal::logger('ys_themes')->debug('Palette HTML sample: @sample', ['@sample' => $sample_html]);
    
    // Ensure prefix and suffix are simple strings, not arrays.
    // Drupal should preserve the HTML in #suffix, including style attributes.
    $element['#prefix'] = '<div class="component-color-picker-wrapper" style="position: relative;">';
    $element['#suffix'] = $palette_html . '</div>';
    
    // Final verification - check the complete suffix
    $has_colors_in_suffix = strpos($element['#suffix'], 'background-color:') !== FALSE;
    \Drupal::logger('ys_themes')->debug('Final suffix check - Has colors: @colors, Suffix length: @length', [
      '@colors' => $has_colors_in_suffix ? 'yes' : 'no',
      '@length' => strlen($element['#suffix']),
    ]);
    
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    // Ensure values are properly extracted.
    // The parent class should handle this, but we can add debugging if needed.
    $massaged = parent::massageFormValues($values, $form, $form_state);
    return $massaged;
  }

}

