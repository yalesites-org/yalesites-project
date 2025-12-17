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
    $palette_options = [];
    foreach ($options as $key => $label) {
      if ($key !== '_none') {
        $palette_options[$key] = $label;
      }
    }

    // Default to 'default' if no value is selected and 'default' exists.
    if (empty($selected_value) && isset($palette_options['default'])) {
      $selected_value = 'default';
      // Set the default value on the element.
      $element['#default_value'] = 'default';
    }

    // Get color styles based on entity type and bundle.
    $all_color_styles = $this->getColorStylesForEntity($entity_type, $bundle);

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

    // Remove the palette data from the element (it was just for passing to
    // this callback).
    unset($element['#palette_options']);
    unset($element['#global_theme']);
    unset($element['#selected_value']);
    unset($element['#color_styles']);

    // Ensure selected_value is a string for comparison.
    $selected_value_string = '';
    if (is_array($selected_value)) {
      $selected_value_string = !empty($selected_value)
        ? (string) reset($selected_value)
        : '';
    }
    elseif ($selected_value !== NULL) {
      $selected_value_string = (string) $selected_value;
    }

    // Default to 'default' if no value is selected and 'default' exists.
    if (empty($selected_value_string) && isset($safe_palette_options['default'])) {
      $selected_value_string = 'default';
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

    // Get color information for each palette option.
    // We'll get the background color (first color) and resolve it to hex and
    // token name.
    $color_info = [];
    foreach ($safe_palette_options as $option_key => $option_label) {
      // Get the background color (first color) for this option.
      $background_color_var = $color_styles[$option_key][0] ?? NULL;

      // Defaults.
      $token_ref = '';
      $hex_value = '';
      $token_name = '';

      // Handle default option explicitly: force white with a token name.
      // This applies even if no CSS variable is defined for the default option.
      if ($option_key === 'default') {
        $hex_value = '#ffffff';
        $token_name = 'Default';
        $token_ref = $background_color_var ?: 'default';
        $css_var_for_display = $hex_value;

        $color_info[$option_key] = [
          'css_var' => $css_var_for_display,
          'hex' => $hex_value,
          'token_name' => $token_name,
          'token_ref' => $token_ref,
        ];
      }
      elseif ($background_color_var && is_scalar($background_color_var)) {
        $background_color_var = (string) $background_color_var;

        if (str_starts_with($background_color_var, 'var(') && preg_match('/var\(([^)]+)\)/', $background_color_var, $matches)) {
          $css_var = $matches[1];

          // Global theme colors: --global-themes-{theme}-colors-slot-{slot}.
          // Extract theme number and slot identifier from CSS variable.
          // Example: --global-themes-one-colors-slot-four → theme="one",
          // slot="four".
          if (preg_match('/--global-themes-([A-Za-z0-9_-]+)-colors-slot-([A-Za-z0-9_-]+)/', $css_var, $var_matches)) {
            $theme_num = $var_matches[1];
            $slot_identifier = $var_matches[2];

            $theme_colors = $this->colorTokenResolver->getThemeColors($theme_num);
            // The slot key in theme_colors should be "slot-{identifier}".
            // Example: if slot_identifier is "four", look for "slot-four".
            $slot_key = "slot-{$slot_identifier}";
            if (isset($theme_colors[$slot_key])) {
              $color_data = $theme_colors[$slot_key];
              $hex_value = $color_data['hex'] ?? '';
              $token_name = $color_data['name'] ?? '';
              $token_ref = $color_data['token'] ?? '';
            }
          }
        }

        $css_var_for_display = $background_color_var;

        $color_info[$option_key] = [
          'css_var' => $css_var_for_display,
          'hex' => $hex_value,
          'token_name' => $token_name,
          'token_ref' => $token_ref,
        ];
      }
      else {
        // Fallback if no color found.
        $color_info[$option_key] = [
          'css_var' => '',
          'hex' => '',
          'token_name' => '',
          'token_ref' => '',
        ];
      }
    }

    // Render the palette UI using the template.
    $palette_render = [
      '#theme' => 'component_color_picker',
      '#palette_options' => $safe_palette_options,
      '#global_theme' => $global_theme,
      '#selected_value' => $selected_value_string,
      '#color_info' => $color_info,
    ];

    // Wrap the select element and add the palette UI.
    $element['#prefix'] = '<div class="component-color-picker-wrapper" style="position: relative;">';
    $element['#suffix'] = $this->renderer->render($palette_render) . '</div>';

    return $element;
  }

  /**
   * Gets color styles for a specific entity type and bundle.
   *
   * Returns only the background color (first color) for each theme option.
   *
   * @param string|null $entity_type
   *   The entity type ID (e.g., 'block_content', 'node').
   * @param string|null $bundle
   *   The bundle name (e.g., 'quote_callout').
   *
   * @return array
   *   Array of color styles keyed by global theme, then by component option.
   *   Each option contains only the background color.
   */
  protected function getColorStylesForEntity($entity_type = NULL, $bundle = NULL) {
    $global_themes = ['one', 'two', 'three', 'four', 'five'];
    $all_color_styles = [];

    // Base mapping: options map directly to global slots (1:1).
    // This matches accordion, wrapped_callout, tile, and most components.
    foreach ($global_themes as $global) {
      $all_color_styles[$global] = [
        'one' => [
          "var(--global-themes-{$global}-colors-slot-one)",
        ],
        'two' => [
          "var(--global-themes-{$global}-colors-slot-two)",
        ],
        'three' => [
          "var(--global-themes-{$global}-colors-slot-three)",
        ],
        'four' => [
          "var(--global-themes-{$global}-colors-slot-four)",
        ],
        'five' => [
          "var(--global-themes-{$global}-colors-slot-five)",
        ],
      ];
    }

    // Callout mapping: one→slot-one, two→slot-four, three→slot-five,
    // four→slot-three, five→slot-two.
    // Used by: callout, content_spotlight, content_spotlight_portrait,
    // cta_banner, grand_hero.
    // Note: content_spotlight uses text-with-image component.
    if ($entity_type === 'block_content' && in_array($bundle, [
      'callout',
      'content_spotlight',
      'content_spotlight_portrait',
      'cta_banner',
      'grand_hero',
    ])) {
      foreach ($global_themes as $global) {
        $all_color_styles[$global] = [
          'one' => [
            "var(--global-themes-{$global}-colors-slot-one)",
          ],
          'two' => [
            "var(--global-themes-{$global}-colors-slot-four)",
          ],
          'three' => [
            "var(--global-themes-{$global}-colors-slot-five)",
          ],
          'four' => [
            "var(--global-themes-{$global}-colors-slot-three)",
          ],
          'five' => [
            "var(--global-themes-{$global}-colors-slot-two)",
          ],
        ];
      }
    }

    // Facts mapping: one→slot-one, two→slot-four, three→slot-five,
    // four→slot-two, five→slot-three.
    // Used by: facts (uses facts-and-figures-group organism).
    if ($entity_type === 'block_content' && $bundle === 'facts') {
      foreach ($global_themes as $global) {
        $all_color_styles[$global] = [
          'one' => [
            "var(--global-themes-{$global}-colors-slot-one)",
          ],
          'two' => [
            "var(--global-themes-{$global}-colors-slot-four)",
          ],
          'three' => [
            "var(--global-themes-{$global}-colors-slot-five)",
          ],
          'four' => [
            "var(--global-themes-{$global}-colors-slot-two)",
          ],
          'five' => [
            "var(--global-themes-{$global}-colors-slot-three)",
          ],
        ];
      }
    }

    // Quote-callout/Link-grid mapping: one→slot-one, two→slot-three,
    // three→slot-five, four→slot-four, five→slot-two.
    // Used by: quote_callout, link_grid.
    if ($entity_type === 'block_content' && in_array($bundle, [
      'quote_callout',
      'link_grid',
    ])) {
      foreach ($global_themes as $global) {
        $all_color_styles[$global] = [
          'one' => [
            "var(--global-themes-{$global}-colors-slot-one)",
          ],
          'two' => [
            "var(--global-themes-{$global}-colors-slot-three)",
          ],
          'three' => [
            "var(--global-themes-{$global}-colors-slot-five)",
          ],
          'four' => [
            "var(--global-themes-{$global}-colors-slot-four)",
          ],
          'five' => [
            "var(--global-themes-{$global}-colors-slot-two)",
          ],
        ];
      }
    }

    // Inline-message mapping: one→slot-one, two→slot-one, three→slot-two,
    // four→slot-three, five→slot-five.
    if ($entity_type === 'block_content' && $bundle === 'inline_message') {
      foreach ($global_themes as $global) {
        $all_color_styles[$global] = [
          'one' => [
            "var(--global-themes-{$global}-colors-slot-one)",
          ],
          'two' => [
            "var(--global-themes-{$global}-colors-slot-one)",
          ],
          'three' => [
            "var(--global-themes-{$global}-colors-slot-two)",
          ],
          'four' => [
            "var(--global-themes-{$global}-colors-slot-three)",
          ],
          'five' => [
            "var(--global-themes-{$global}-colors-slot-five)",
          ],
        ];
      }
    }

    // Accordion gets an explicit default (accent) in addition to the base map.
    if ($entity_type === 'block_content' && $bundle === 'accordion') {
      foreach ($global_themes as $global) {
        $all_color_styles[$global]['default'] = ["var(--color-accordion-accent)"];
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
