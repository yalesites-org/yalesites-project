<?php

namespace Drupal\ys_themes;

use Symfony\Component\Yaml\Yaml;

/**
 * Service to resolve color token references to actual hex values.
 */
class ColorTokenResolver {

  /**
   * The path to the tokens YAML file.
   *
   * @var string
   */
  protected $yamlPath;

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
   * Constructs a ColorTokenResolver.
   */
  public function __construct() {
    $this->yamlPath = DRUPAL_ROOT . '/themes/contrib/atomic/_yale-packages/tokens/tokens/base/color.yml';
    $this->jsonPath = DRUPAL_ROOT . '/themes/contrib/atomic/_yale-packages/tokens/tokens/figma-export/tokens.json';
  }

  /**
   * Gets all global theme colors with resolved hex values.
   *
   * @return array
   *   Array of themes with their color slots and hex values.
   */
  public function getGlobalThemeColors() {
    if (!file_exists($this->yamlPath) || !file_exists($this->jsonPath)) {
      return [];
    }

    try {
      $yaml = Yaml::parseFile($this->yamlPath);
      $json = json_decode(file_get_contents($this->jsonPath), TRUE);

      if (!isset($yaml['global-themes']) || !isset($json['global']['color'])) {
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
    catch (\Exception $e) {
      return [];
    }
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

}
