<?php

namespace Drupal\ys_themes;

use Drupal\Core\Extension\ThemeExtensionList;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Service to resolve color token references to actual hex values.
 */
class ColorTokenResolver {

  /**
   * The path to the tokens YAML file.
   *
   * @var string|null
   */
  protected $yamlPath;

  /**
   * The path to the tokens JSON file.
   *
   * @var string
   */
  protected $jsonPath;

  /**
   * Whether we're using the built JSON format (node_modules).
   *
   * @var bool
   */
  protected $usingBuiltJson = FALSE;

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

    // First, try _yale-packages (local dev with YAML + JSON).
    $yale_packages_yaml = $atomic_theme_path . '/_yale-packages/tokens/tokens/base/color.yml';
    $yale_packages_json = $atomic_theme_path . '/_yale-packages/tokens/tokens/figma-export/tokens.json';

    if (file_exists($yale_packages_yaml) && file_exists($yale_packages_json)) {
      $this->yamlPath = $yale_packages_yaml;
      $this->jsonPath = $yale_packages_json;
      $this->usingBuiltJson = FALSE;
      $this->logger->debug('Using _yale-packages token files (YAML + JSON format).');
      return;
    }

    // Second, try _yale-packages component library node_modules
    // (local dev with built tokens).
    $yale_packages_cl_json = $atomic_theme_path .
      '/_yale-packages/component-library-twig/node_modules/@yalesites-org/tokens/build/json/tokens.json';

    if (file_exists($yale_packages_cl_json)) {
      $this->yamlPath = NULL;
      $this->jsonPath = $yale_packages_cl_json;
      $this->usingBuiltJson = TRUE;
      $this->logger->debug('Using _yale-packages component library node_modules built JSON token file.');
      return;
    }

    // Third, try node_modules built JSON (deployed environment).
    $node_modules_json = $atomic_theme_path . '/node_modules/@yalesites-org/tokens/build/json/tokens.json';

    if (file_exists($node_modules_json)) {
      $this->yamlPath = NULL;
      $this->jsonPath = $node_modules_json;
      $this->usingBuiltJson = TRUE;
      $this->logger->debug('Using node_modules built JSON token file.');
      return;
    }

    // Fourth, try component library dist folder (alternative location).
    $component_library_json = $atomic_theme_path . '/node_modules/@yalesites-org/component-library-twig/node_modules/@yalesites-org/tokens/build/json/tokens.json';

    if (file_exists($component_library_json)) {
      $this->yamlPath = NULL;
      $this->jsonPath = $component_library_json;
      $this->usingBuiltJson = TRUE;
      $this->logger->debug('Using component library node_modules built JSON token file.');
      return;
    }

    // Fallback to default paths if not found.
    $this->yamlPath = $yale_packages_yaml;
    $this->jsonPath = $yale_packages_json;
    $this->usingBuiltJson = FALSE;
    $this->logger->warning('Token files not found in expected locations. Using default paths.');
  }

  /**
   * Gets diagnostic information about file paths and accessibility.
   *
   * @return array
   *   Array with diagnostic information.
   */
  public function getDiagnostics() {
    // Get the atomic theme path.
    // getPath() returns a relative path, so we need to prepend DRUPAL_ROOT.
    try {
      $atomic_theme_relative = $this->themeExtensionList->getPath('atomic');
      $atomic_theme_path = DRUPAL_ROOT . '/' . $atomic_theme_relative;
    }
    catch (\Exception $e) {
      $atomic_theme_path = DRUPAL_ROOT . '/themes/contrib/atomic';
    }

    $diagnostics = [
      'yaml_path' => $this->yamlPath,
      'yaml_exists' => $this->yamlPath ? file_exists($this->yamlPath) : FALSE,
      'yaml_readable' => ($this->yamlPath && file_exists($this->yamlPath)) ? is_readable($this->yamlPath) : FALSE,
      'json_path' => $this->jsonPath,
      'json_exists' => file_exists($this->jsonPath),
      'json_readable' => file_exists($this->jsonPath) ? is_readable($this->jsonPath) : FALSE,
      'using_built_json' => $this->usingBuiltJson,
      'drupal_root' => DRUPAL_ROOT,
      'atomic_theme_path_relative' => $atomic_theme_relative ?? 'unknown',
      'atomic_theme_path_absolute' => $atomic_theme_path,
      'atomic_theme_path_exists' => is_dir($atomic_theme_path),
      'themes_dir_exists' => is_dir(DRUPAL_ROOT . '/themes'),
      'atomic_dir_exists' => is_dir(DRUPAL_ROOT . '/themes/contrib/atomic'),
      'yale_packages_tokens_exists' => is_dir($atomic_theme_path . '/_yale-packages/tokens'),
      'node_modules_tokens_exists' => is_dir($atomic_theme_path . '/node_modules/@yalesites-org/tokens'),
    ];

    // Try to get more specific path info.
    if ($this->yamlPath && file_exists($this->yamlPath)) {
      $diagnostics['yaml_size'] = filesize($this->yamlPath);
    }
    if (file_exists($this->jsonPath)) {
      $diagnostics['json_size'] = filesize($this->jsonPath);
    }

    // Check alternative paths.
    $alt_paths = [
      'yale_packages_yaml' => $atomic_theme_path . '/_yale-packages/tokens/tokens/base/color.yml',
      'yale_packages_json' => $atomic_theme_path . '/_yale-packages/tokens/tokens/figma-export/tokens.json',
      'yale_packages_cl_node_modules_json' => $atomic_theme_path . '/_yale-packages/component-library-twig/node_modules/@yalesites-org/tokens/build/json/tokens.json',
      'node_modules_built_json' => $atomic_theme_path . '/node_modules/@yalesites-org/tokens/build/json/tokens.json',
      'component_library_node_modules_json' => $atomic_theme_path . '/node_modules/@yalesites-org/component-library-twig/node_modules/@yalesites-org/tokens/build/json/tokens.json',
    ];

    foreach ($alt_paths as $key => $path) {
      $diagnostics[$key . '_exists'] = file_exists($path);
    }

    // Check directories separately.
    $diagnostics['yale_packages_cl_node_modules_exists'] = is_dir($atomic_theme_path . '/_yale-packages/component-library-twig/node_modules/@yalesites-org/tokens');
    $diagnostics['yale_packages_cl_node_modules_dir'] = $atomic_theme_path . '/_yale-packages/component-library-twig/node_modules/@yalesites-org';
    $diagnostics['yale_packages_cl_node_modules_dir_exists'] = is_dir($atomic_theme_path . '/_yale-packages/component-library-twig/node_modules/@yalesites-org');
    $diagnostics['component_library_dist_exists'] = is_dir($atomic_theme_path . '/node_modules/@yalesites-org/component-library-twig/dist');
    $diagnostics['node_modules_cl_exists'] = is_dir($atomic_theme_path . '/node_modules/@yalesites-org/component-library-twig');

    // Try to find tokens by scanning node_modules directories.
    $possible_token_paths = [
      $atomic_theme_path . '/node_modules/@yalesites-org/component-library-twig/node_modules/@yalesites-org/tokens/build/json/tokens.json',
      $atomic_theme_path . '/_yale-packages/component-library-twig/node_modules/@yalesites-org/tokens/build/json/tokens.json',
    ];

    foreach ($possible_token_paths as $idx => $path) {
      $key = 'scan_path_' . ($idx + 1);
      $diagnostics[$key] = $path;
      $diagnostics[$key . '_exists'] = file_exists($path);
      if (file_exists($path)) {
        $diagnostics[$key . '_readable'] = is_readable($path);
      }
    }

    return $diagnostics;
  }

  /**
   * Gets all global theme colors with resolved hex values.
   *
   * @return array
   *   Array of themes with their color slots and hex values.
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

      if ($this->usingBuiltJson) {
        // Handle built JSON format (node_modules).
        return $this->parseBuiltJson($json);
      }
      else {
        // Handle YAML + JSON format (local dev).
        return $this->parseYamlJson($json);
      }
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
   * @param array $json
   *   The parsed JSON data.
   *
   * @return array
   *   Array of themes with their color slots and hex values.
   */
  protected function parseBuiltJson(array $json) {
    if (!isset($json['global-themes'])) {
      $this->logger->warning('Built JSON does not contain global-themes key.');
      return [];
    }

    // Build a lookup map of HSL values to color token names.
    $color_lookup = $this->buildColorLookup($json);

    $themes = [];
    foreach ($json['global-themes'] as $theme_id => $theme_data) {
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
          $theme_info['colors'][$slot] = [
            'token' => $slot,
            'hex' => $hex_value,
            'name' => $token_name,
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
   * Parses the YAML + JSON format (from _yale-packages).
   *
   * @param array $json
   *   The parsed JSON data.
   *
   * @return array
   *   Array of themes with their color slots and hex values.
   */
  protected function parseYamlJson(array $json) {
    if (!$this->yamlPath || !file_exists($this->yamlPath)) {
      $this->logger->warning('YAML file not found: @yaml', [
        '@yaml' => $this->yamlPath,
      ]);
      return [];
    }

    if (!isset($json['global']['color'])) {
      $this->logger->warning('JSON does not contain global.color key.');
      return [];
    }

    $yaml = Yaml::parseFile($this->yamlPath);

    if (!isset($yaml['global-themes'])) {
      $this->logger->warning('YAML does not contain global-themes key.');
      return [];
    }

    $themes = [];
    foreach ($yaml['global-themes'] as $theme_id => $theme_data) {
      $theme_info = [
        'id' => $theme_id,
        'label' => $theme_data['label']['value'] ?? $theme_id,
        'colors' => [],
      ];

      if (isset($theme_data['colors'])) {
        foreach ($theme_data['colors'] as $slot => $slot_data) {
          $token_ref = $slot_data['value'] ?? '';
          $hex_value = $this->resolveTokenReference($token_ref, $json);
          $theme_info['colors'][$slot] = [
            'token' => $token_ref,
            'hex' => $hex_value,
            'name' => $this->getTokenName($token_ref),
          ];
        }
      }

      $themes[$theme_id] = $theme_info;
    }

    return $themes;
  }

  /**
   * Resolves a token reference to a hex value.
   *
   * @param string $token_ref
   *   Token reference like "{color.blue.yale.value}".
   * @param array $json_data
   *   The parsed JSON token data.
   *
   * @return string
   *   The hex color value or empty string if not found.
   */
  protected function resolveTokenReference($token_ref, array $json_data) {
    // Remove curly braces and .value suffix.
    $token_ref = trim($token_ref, '{}');
    $token_ref = str_replace('.value', '', $token_ref);

    // Split by dots to navigate the JSON structure.
    $parts = explode('.', $token_ref);
    if (empty($parts) || $parts[0] !== 'color') {
      return '';
    }

    // Navigate through the JSON structure.
    // The JSON structure is: global.color.blue.yale.value.
    // But the token reference is: color.blue.yale.
    $value = $json_data['global']['color'] ?? [];

    // Skip 'color' part and navigate through the rest.
    for ($i = 1; $i < count($parts); $i++) {
      if (!isset($value[$parts[$i]])) {
        return '';
      }
      $value = $value[$parts[$i]];
    }

    // If we have a value key, get its value.
    if (isset($value['value'])) {
      return $value['value'];
    }

    return '';
  }

  /**
   * Gets a human-readable name from a token reference.
   *
   * @param string $token_ref
   *   Token reference like "{color.blue.yale.value}".
   *
   * @return string
   *   Human-readable name like "Blue Yale".
   */
  protected function getTokenName($token_ref) {
    // Remove curly braces and .value suffix.
    $token_ref = trim($token_ref, '{}');
    $token_ref = str_replace('.value', '', $token_ref);

    // Split by dots.
    $parts = explode('.', $token_ref);
    if (empty($parts) || $parts[0] !== 'color') {
      return $token_ref;
    }

    // Remove 'color' from the beginning.
    array_shift($parts);

    // Capitalize and join.
    $name_parts = [];
    foreach ($parts as $part) {
      $name_parts[] = ucfirst($part);
    }

    return implode(' ', $name_parts);
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
