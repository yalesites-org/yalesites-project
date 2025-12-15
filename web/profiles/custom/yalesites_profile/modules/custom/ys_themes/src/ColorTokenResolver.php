<?php

namespace Drupal\ys_themes;

use Psr\Log\LoggerInterface;
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
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a ColorTokenResolver.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(LoggerInterface $logger) {
    $this->logger = $logger;
    $this->findTokenFiles();
  }

  /**
   * Finds the token files in available locations.
   *
   * Checks both _yale-packages (local dev) and node_modules (deployed).
   */
  protected function findTokenFiles() {
    $base_paths = [
      DRUPAL_ROOT . '/themes/contrib/atomic/_yale-packages/tokens',
      DRUPAL_ROOT . '/themes/contrib/atomic/node_modules/@yalesites-org/tokens',
    ];

    $yaml_file = 'tokens/base/color.yml';
    $json_file = 'tokens/figma-export/tokens.json';

    // Try to find files in each possible location.
    foreach ($base_paths as $base_path) {
      $yaml_path = $base_path . '/' . $yaml_file;
      $json_path = $base_path . '/' . $json_file;

      if (file_exists($yaml_path) && file_exists($json_path)) {
        $this->yamlPath = $yaml_path;
        $this->jsonPath = $json_path;
        return;
      }
    }

    // Fallback to default paths if not found.
    $this->yamlPath = DRUPAL_ROOT . '/themes/contrib/atomic/_yale-packages/tokens/tokens/base/color.yml';
    $this->jsonPath = DRUPAL_ROOT . '/themes/contrib/atomic/_yale-packages/tokens/tokens/figma-export/tokens.json';
  }

  /**
   * Gets diagnostic information about file paths and accessibility.
   *
   * @return array
   *   Array with diagnostic information.
   */
  public function getDiagnostics() {
    $diagnostics = [
      'yaml_path' => $this->yamlPath,
      'yaml_exists' => file_exists($this->yamlPath),
      'yaml_readable' => file_exists($this->yamlPath) ? is_readable($this->yamlPath) : FALSE,
      'json_path' => $this->jsonPath,
      'json_exists' => file_exists($this->jsonPath),
      'json_readable' => file_exists($this->jsonPath) ? is_readable($this->jsonPath) : FALSE,
      'drupal_root' => DRUPAL_ROOT,
      'themes_dir_exists' => is_dir(DRUPAL_ROOT . '/themes'),
      'atomic_dir_exists' => is_dir(DRUPAL_ROOT . '/themes/contrib/atomic'),
      'yale_packages_tokens_exists' => is_dir(DRUPAL_ROOT . '/themes/contrib/atomic/_yale-packages/tokens'),
      'node_modules_tokens_exists' => is_dir(DRUPAL_ROOT . '/themes/contrib/atomic/node_modules/@yalesites-org/tokens'),
    ];

    // Try to get more specific path info.
    if (file_exists($this->yamlPath)) {
      $diagnostics['yaml_size'] = filesize($this->yamlPath);
    }
    if (file_exists($this->jsonPath)) {
      $diagnostics['json_size'] = filesize($this->jsonPath);
    }

    // Check alternative paths.
    $alt_paths = [
      'yale_packages_yaml' => DRUPAL_ROOT . '/themes/contrib/atomic/_yale-packages/tokens/tokens/base/color.yml',
      'yale_packages_json' => DRUPAL_ROOT . '/themes/contrib/atomic/_yale-packages/tokens/tokens/figma-export/tokens.json',
      'node_modules_yaml' => DRUPAL_ROOT . '/themes/contrib/atomic/node_modules/@yalesites-org/tokens/tokens/base/color.yml',
      'node_modules_json' => DRUPAL_ROOT . '/themes/contrib/atomic/node_modules/@yalesites-org/tokens/tokens/figma-export/tokens.json',
    ];

    foreach ($alt_paths as $key => $path) {
      $diagnostics[$key . '_exists'] = file_exists($path);
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
    if (!file_exists($this->yamlPath) || !file_exists($this->jsonPath)) {
      $this->logger->warning('Color token files not found. YAML: @yaml, JSON: @json', [
        '@yaml' => $this->yamlPath,
        '@json' => $this->jsonPath,
      ]);
      return [];
    }

    try {
      $yaml = Yaml::parseFile($this->yamlPath);
      $json = json_decode(file_get_contents($this->jsonPath), TRUE);

      if (!isset($yaml['global-themes']) || !isset($json['global']['color'])) {
        $this->logger->warning('Color token data structure invalid. YAML has global-themes: @yaml, JSON has global.color: @json', [
          '@yaml' => isset($yaml['global-themes']) ? 'yes' : 'no',
          '@json' => isset($json['global']['color']) ? 'yes' : 'no',
        ]);
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
      $this->logger->error('Error parsing color token files: @message', [
        '@message' => $e->getMessage(),
      ]);
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
