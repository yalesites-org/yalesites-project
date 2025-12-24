<?php

namespace Drupal\ys_themes;

use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Psr\Log\LoggerInterface;

/**
 * Service to resolve color token references to actual hex values.
 */
class ColorTokenResolver {

  /**
   * The path to the tokens JSON file.
   *
   * @var string
   */
  protected $jsonPath;

  /**
   * Cached token values from JSON.
   *
   * @var array
   */
  protected $tokenValues = NULL;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The theme extension list service.
   *
   * @var \Drupal\Core\Extension\ThemeExtensionList
   */
  protected $themeExtensionList;

  /**
   * The theme settings manager service.
   *
   * @var \Drupal\ys_themes\ThemeSettingsManager
   */
  protected $themeSettingsManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a ColorTokenResolver.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Extension\ThemeExtensionList $theme_extension_list
   *   The theme extension list service.
   * @param \Drupal\ys_themes\ThemeSettingsManager $theme_settings_manager
   *   The theme settings manager service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(
    LoggerInterface $logger,
    ThemeExtensionList $theme_extension_list,
    ThemeSettingsManager $theme_settings_manager,
    RendererInterface $renderer,
  ) {
    $this->logger = $logger;
    $this->themeExtensionList = $theme_extension_list;
    $this->themeSettingsManager = $theme_settings_manager;
    $this->renderer = $renderer;
    $this->findTokenFiles();
  }

  /**
   * Finds the token files in available locations.
   *
   * Checks both _yale-packages (local dev) and node_modules (deployed).
   */
  protected function findTokenFiles() {
    // Get the atomic theme path using the theme extension list service.
    // getPath() returns a relative path, so we need to prepend DRUPAL_ROOT.
    try {
      $atomic_theme_relative = $this->themeExtensionList->getPath('atomic');
      $atomic_theme_path = DRUPAL_ROOT . '/' . $atomic_theme_relative;
    }
    catch (\Exception $e) {
      // Fallback to DRUPAL_ROOT if theme extension list fails.
      $atomic_theme_path = DRUPAL_ROOT . '/themes/contrib/atomic';
      $this->logger->warning('Could not get atomic theme path from extension list, using fallback: @path', [
        '@path' => $atomic_theme_path,
      ]);
    }

    $cl_dist_json = $atomic_theme_path .
      '/node_modules/@yalesites-org/component-library-twig/dist/tokens.json';

    if (file_exists($cl_dist_json)) {
      $this->jsonPath = $cl_dist_json;
      return;
    }

    // Default to primary path even if missing, so the warning is clear.
    $this->jsonPath = $cl_dist_json;
    $this->logger->warning('Token file not found; expected at @path', [
      '@path' => $cl_dist_json,
    ]);
  }

  /**
   * Gets all global theme colors with resolved hex values.
   *
   * @return array
   *   Array of themes with their color slots and hex values.
   *   Structure matches the Twig template: tokens['global-themes'].
   */
  public function getGlobalThemeColors() {
    if (!file_exists($this->jsonPath)) {
      $this->logger->warning('Color token JSON file not found: @json', [
        '@json' => $this->jsonPath,
      ]);
      return [];
    }

    try {
      $json = json_decode(file_get_contents($this->jsonPath), TRUE);

      if (!isset($json['global-themes'])) {
        $this->logger->warning('Token JSON does not contain global-themes key.');
        return [];
      }

      return $this->parseBuiltJson($json['global-themes']);
    }
    catch (\Exception $e) {
      $this->logger->error('Error parsing color token files: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Parses the built JSON format (from node_modules).
   *
   * @param array $global_themes
   *   The global-themes data from tokens['global-themes'].
   *
   * @return array
   *   Array of themes with their color slots and hex values.
   *   Structure matches: { theme_id: { label: "...", colors: {...} } }.
   */
  protected function parseBuiltJson(array $global_themes) {
    // Build a lookup map of HSL values to color token names.
    // We need the full JSON to build the lookup, so get it from the file.
    $full_json = json_decode(file_get_contents($this->jsonPath), TRUE);
    $color_lookup = $this->buildColorLookup($full_json);

    $themes = [];
    foreach ($global_themes as $theme_id => $theme_data) {
      // Structure matches the Twig template: values.label and values.colors.
      $theme_info = [
        'id' => $theme_id,
        'label' => $theme_data['label'] ?? $theme_id,
        'colors' => [],
      ];

      if (isset($theme_data['colors'])) {
        foreach ($theme_data['colors'] as $slot => $color_value) {
          // Color value is in HSL format, convert to hex.
          $hex_value = $this->hslToHex($color_value);
          // Look up the color name from the HSL value.
          $token_name = $color_lookup[$color_value] ?? $this->getSlotName($slot);
          // Store both the original HSL value and converted hex,
          // matching Twig structure.
          $theme_info['colors'][$slot] = [
            'hsl' => $color_value,
            'hex' => $hex_value,
            'token' => $slot,
            'name' => $token_name,
            'css_var' => "--global-themes-{$theme_id}-colors-{$slot}",
          ];
        }
      }

      $themes[$theme_id] = $theme_info;
    }

    return $themes;
  }

  /**
   * Builds a lookup map of HSL values to color token names.
   *
   * @param array $json
   *   The parsed JSON data.
   *
   * @return array
   *   Array mapping HSL strings to human-readable color names.
   */
  protected function buildColorLookup(array $json) {
    $lookup = [];
    if (!isset($json['color'])) {
      return $lookup;
    }

    // Recursively traverse the color object to build the lookup.
    $this->traverseColorObject($json['color'], $lookup, []);

    return $lookup;
  }

  /**
   * Recursively traverses the color object to build HSL to name lookup.
   *
   * @param array $color_obj
   *   The color object or sub-object.
   * @param array &$lookup
   *   The lookup array being built (passed by reference).
   * @param array $path
   *   The current path in the color object (e.g., ['blue', 'yale']).
   */
  protected function traverseColorObject(array $color_obj, array &$lookup, array $path) {
    foreach ($color_obj as $key => $value) {
      if (is_string($value) && preg_match('/^hsl\(/', $value)) {
        // This is an HSL color value.
        $name_parts = array_merge($path, [$key]);
        // Capitalize each part and join with space.
        $name = implode(' ', array_map('ucfirst', $name_parts));
        $lookup[$value] = $name;
      }
      elseif (is_array($value)) {
        // Recurse into nested objects.
        $this->traverseColorObject($value, $lookup, array_merge($path, [$key]));
      }
    }
  }

  /**
   * Gets all colors for a specific global theme.
   *
   * @param string $theme_id
   *   The theme ID (e.g., 'one', 'two', etc.).
   *
   * @return array
   *   Array of colors for the theme.
   */
  public function getThemeColors($theme_id) {
    $themes = $this->getGlobalThemeColors();
    return $themes[$theme_id]['colors'] ?? [];
  }

  /**
   * Converts HSL color string to hex.
   *
   * @param string $hsl
   *   HSL color string like "hsl(210, 100%, 21%)".
   *
   * @return string
   *   Hex color value like "#00356b".
   */
  protected function hslToHex($hsl) {
    // Parse HSL string: "hsl(210, 100%, 21%)".
    if (preg_match('/hsl\((\d+),\s*(\d+)%,\s*(\d+)%\)/', $hsl, $matches)) {
      $h = (int) $matches[1];
      $s = (int) $matches[2] / 100;
      $l = (int) $matches[3] / 100;

      // Convert HSL to RGB.
      $c = (1 - abs(2 * $l - 1)) * $s;
      $x = $c * (1 - abs(fmod(($h / 60), 2) - 1));
      $m = $l - ($c / 2);

      if ($h < 60) {
        $r = $c;
        $g = $x;
        $b = 0;
      }
      elseif ($h < 120) {
        $r = $x;
        $g = $c;
        $b = 0;
      }
      elseif ($h < 180) {
        $r = 0;
        $g = $c;
        $b = $x;
      }
      elseif ($h < 240) {
        $r = 0;
        $g = $x;
        $b = $c;
      }
      elseif ($h < 300) {
        $r = $x;
        $g = 0;
        $b = $c;
      }
      else {
        $r = $c;
        $g = 0;
        $b = $x;
      }

      $r = round(($r + $m) * 255);
      $g = round(($g + $m) * 255);
      $b = round(($b + $m) * 255);

      return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    // If parsing fails, return empty string.
    return '';
  }

  /**
   * Gets a human-readable name from a slot name.
   *
   * @param string $slot
   *   Slot name like "slot-one".
   *
   * @return string
   *   Human-readable name like "Slot One".
   */
  protected function getSlotName($slot) {
    return ucwords(str_replace('-', ' ', $slot));
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
  public function getColorStylesForEntity($entity_type = NULL, $bundle = NULL) {
    $global_themes = ['one', 'two', 'three', 'four', 'five'];
    $all_color_styles = [];

    // Section layout mapping: one→slot-one, two→slot-three, three→slot-two,
    // four→slot-five, five→slot-four.
    // Used by: Layout Builder section configuration forms.
    // Mapping: one=Blue Yale, two=Gray 100, three=Gray 800,
    // four=Blue Medium, five=Blue Light.
    // Note: slot-four contains Blue Light, slot-five contains Blue Medium.
    if ($entity_type === 'layout_section' && $bundle === 'ys_layout_options') {
      foreach ($global_themes as $global) {
        $all_color_styles[$global] = [
          'one' => [
            "var(--global-themes-{$global}-colors-slot-one)",
          ],
          'two' => [
            "var(--global-themes-{$global}-colors-slot-three)",
          ],
          'three' => [
            "var(--global-themes-{$global}-colors-slot-two)",
          ],
          'four' => [
            "var(--global-themes-{$global}-colors-slot-five)",
          ],
          'five' => [
            "var(--global-themes-{$global}-colors-slot-four)",
          ],
        ];
      }
      return $all_color_styles;
    }

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
   * Process callback to add the color picker palette UI.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form.
   * @param string|null $entity_type
   *   Optional entity type for custom color mappings.
   * @param string|null $bundle
   *   Optional bundle for custom color mappings.
   *
   * @return array
   *   The processed form element.
   */
  public function processColorPicker(
    array &$element,
    FormStateInterface $form_state,
    array &$complete_form,
    $entity_type = NULL,
    $bundle = NULL,
  ) {
    // Get the current global theme value.
    $global_theme = $this->themeSettingsManager->getSetting('global_theme') ?? 'one';

    // Get the selected value.
    $selected_value = $element['#default_value'] ?? 'one';

    // Get options from the element.
    $palette_options = $element['#options'] ?? [];
    unset($palette_options['_none']);

    // Get color styles using the service method.
    $all_color_styles = $this->getColorStylesForEntity($entity_type, $bundle);
    $color_styles = $all_color_styles[$global_theme] ?? $all_color_styles['one'] ?? [];

    // For section layout forms, reorder options to ensure correct display
    // order: default, one (Blue Yale), two (Gray 100), three (Gray 800),
    // four (Blue Medium), five (Blue Light).
    if ($entity_type === 'layout_section' && $bundle === 'ys_layout_options') {
      $ordered_options = [];
      if (isset($palette_options['default'])) {
        $ordered_options['default'] = $palette_options['default'];
      }
      foreach (['one', 'two', 'three', 'four', 'five'] as $key) {
        if (isset($palette_options[$key])) {
          $ordered_options[$key] = $palette_options[$key];
        }
      }
      $palette_options = $ordered_options;
    }

    // Hide the select element visually.
    $element['#attributes']['class'][] = 'palette-select-hidden';
    $element['#attributes']['style'] = 'position: absolute; opacity: 0; pointer-events: none; width: 0; height: 0; overflow: hidden;';

    // Ensure selected_value is a string.
    $selected_value_string = (string) $selected_value;

    // Get color information for each palette option.
    $color_info = [];
    foreach ($palette_options as $option_key => $option_label) {
      $background_color_var = $color_styles[$option_key][0] ?? NULL;

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

          if (preg_match('/--global-themes-([A-Za-z0-9_-]+)-colors-slot-([A-Za-z0-9_-]+)/', $css_var, $var_matches)) {
            $theme_num = $var_matches[1];
            $slot_identifier = $var_matches[2];

            $theme_colors = $this->getThemeColors($theme_num);
            $slot_key = "slot-{$slot_identifier}";
            if (isset($theme_colors[$slot_key])) {
              $color_data = $theme_colors[$slot_key];
              $hex_value = $color_data['hex'] ?? '';
              $token_name = $color_data['name'] ?? '';
              $token_ref = $color_data['token'] ?? '';
            }
          }
        }

        $css_var_for_display = $background_color_var ?: '';

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
      '#palette_options' => $palette_options,
      '#global_theme' => $global_theme,
      '#selected_value' => $selected_value_string,
      '#color_info' => $color_info,
    ];

    // Wrap the select element and add the palette UI.
    $element['#prefix'] = '<div class="component-color-picker-wrapper" style="position: relative;">';
    $element['#suffix'] = $this->renderer->render($palette_render) . '</div>';

    // Attach the library.
    $element['#attached']['library'][] = 'ys_themes/component_color_picker';

    return $element;
  }

}
