<?php

namespace Drupal\ys_themes;

use Drupal\Core\Extension\ThemeExtensionList;
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
   * Constructs a ColorTokenResolver.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Extension\ThemeExtensionList $theme_extension_list
   *   The theme extension list service.
   */
  public function __construct(LoggerInterface $logger, ThemeExtensionList $theme_extension_list) {
    $this->logger = $logger;
    $this->themeExtensionList = $theme_extension_list;
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

}
