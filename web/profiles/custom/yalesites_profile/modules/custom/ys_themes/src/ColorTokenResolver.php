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
    // The tokens package builds to build/json/tokens.json per sd.config.js.
    $yale_packages_cl_json = $atomic_theme_path .
      '/_yale-packages/component-library-twig/node_modules/@yalesites-org/tokens/build/json/tokens.json';

    if (file_exists($yale_packages_cl_json)) {
      $this->yamlPath = NULL;
      $this->jsonPath = $yale_packages_cl_json;
      $this->usingBuiltJson = TRUE;
      $this->logger->debug('Using _yale-packages component library node_modules built JSON token file: @path', [
        '@path' => $yale_packages_cl_json,
      ]);
      return;
    }

    // Also check if tokens package exists directly in _yale-packages
    // (if built locally).
    $yale_packages_tokens_json = $atomic_theme_path .
      '/_yale-packages/tokens/build/json/tokens.json';

    if (file_exists($yale_packages_tokens_json)) {
      $this->yamlPath = NULL;
      $this->jsonPath = $yale_packages_tokens_json;
      $this->usingBuiltJson = TRUE;
      $this->logger->debug('Using _yale-packages tokens built JSON token file: @path', [
        '@path' => $yale_packages_tokens_json,
      ]);
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

    // Fifth, try to find tokens by searching in node_modules recursively.
    // This handles cases where the structure might be different on multidev.
    $found_json = $this->findTokensInNodeModules($atomic_theme_path);
    if ($found_json) {
      $this->yamlPath = NULL;
      $this->jsonPath = $found_json;
      $this->usingBuiltJson = TRUE;
      $this->logger->debug('Found tokens by searching node_modules: @path', [
        '@path' => $found_json,
      ]);
      return;
    }

    // Fallback to default paths if not found.
    $this->yamlPath = $yale_packages_yaml;
    $this->jsonPath = $yale_packages_json;
    $this->usingBuiltJson = FALSE;
    $this->logger->warning('Token files not found in expected locations. Using default paths.');
  }

  /**
   * Recursively searches for tokens.json in node_modules.
   *
   * This mimics how webpack resolves @yalesites-org/tokens imports.
   * Webpack looks in node_modules/@yalesites-org/tokens for the package.
   *
   * @param string $atomic_theme_path
   *   The atomic theme path.
   *
   * @return string|null
   *   Path to tokens.json if found, NULL otherwise.
   */
  protected function findTokensInNodeModules($atomic_theme_path) {
    // The component library imports tokens from
    // '@yalesites-org/tokens/build/json/tokens.json' which webpack resolves
    // from the component library's node_modules.
    // So we need to check:
    // component-library-twig/node_modules/@yalesites-org/tokens/build/json/tokens.json
    // First, check _yale-packages if it exists (local dev).
    // This is where the user confirmed the file exists locally.
    $yale_cl_node_modules = $atomic_theme_path .
      '/_yale-packages/component-library-twig/node_modules';
    if (is_dir($yale_cl_node_modules)) {
      $yale_cl_tokens_path = $yale_cl_node_modules .
        '/@yalesites-org/tokens/build/json/tokens.json';
      if (file_exists($yale_cl_tokens_path)) {
        $this->logger->debug('Found tokens in _yale-packages component library node_modules: @path', [
          '@path' => $yale_cl_tokens_path,
        ]);
        return $yale_cl_tokens_path;
      }
    }

    // Second, check if component-library-twig has node_modules that we can
    // search. This is the most likely location on multidev.
    $cl_node_modules = $atomic_theme_path .
      '/node_modules/@yalesites-org/component-library-twig/node_modules';
    if (is_dir($cl_node_modules)) {
      // Look for @yalesites-org/tokens in component library's node_modules.
      $cl_tokens_path = $cl_node_modules .
        '/@yalesites-org/tokens/build/json/tokens.json';
      if (file_exists($cl_tokens_path)) {
        $this->logger->debug('Found tokens in component library node_modules: @path', [
          '@path' => $cl_tokens_path,
        ]);
        return $cl_tokens_path;
      }
    }

    // Check other possible paths (direct tokens package).
    $search_paths = [
      $atomic_theme_path .
      '/node_modules/@yalesites-org/tokens/build/json/tokens.json',
    ];

    foreach ($search_paths as $path) {
      if (file_exists($path)) {
        $this->logger->debug('Found tokens in search path: @path', [
          '@path' => $path,
        ]);
        return $path;
      }
    }

    // Last resort: recursively search for tokens.json in node_modules.
    // This handles cases where npm hoists dependencies or the structure
    // is different than expected.
    $node_modules_base = $atomic_theme_path . '/node_modules';
    if (is_dir($node_modules_base)) {
      $found = $this->recursiveFindTokens($node_modules_base);
      if ($found) {
        $this->logger->debug('Found tokens via recursive search: @path', [
          '@path' => $found,
        ]);
        return $found;
      }
    }

    return NULL;
  }

  /**
   * Recursively searches for tokens.json in node_modules directories.
   *
   * @param string $dir
   *   The directory to search in.
   * @param int $depth
   *   Current recursion depth (to prevent infinite loops).
   *
   * @return string|null
   *   Path to tokens.json if found, NULL otherwise.
   */
  protected function recursiveFindTokens($dir, $depth = 0) {
    // Limit recursion depth to prevent infinite loops.
    if ($depth > 10) {
      return NULL;
    }

    // Look for tokens.json in the expected location relative to this directory.
    $tokens_path = $dir . '/@yalesites-org/tokens/build/json/tokens.json';
    if (file_exists($tokens_path)) {
      return $tokens_path;
    }

    // Search in subdirectories, but skip certain directories to avoid
    // unnecessary searching.
    if (is_dir($dir) && is_readable($dir)) {
      $entries = @scandir($dir);
      if ($entries === FALSE) {
        return NULL;
      }

      foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
          continue;
        }

        $full_path = $dir . '/' . $entry;

        // Skip if not a directory.
        if (!is_dir($full_path)) {
          continue;
        }

        // Skip certain directories that are unlikely to contain tokens.
        $skip_dirs = ['.bin', '.cache', 'bin'];
        if (in_array($entry, $skip_dirs)) {
          continue;
        }

        // Recursively search in this directory.
        $found = $this->recursiveFindTokens($full_path, $depth + 1);
        if ($found) {
          return $found;
        }
      }
    }

    return NULL;
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
      'yale_packages_tokens_build_json' => $atomic_theme_path . '/_yale-packages/tokens/build/json/tokens.json',
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

    // Check what's in the root node_modules/@yalesites-org directory.
    $yalesites_org_root = $atomic_theme_path . '/node_modules/@yalesites-org';
    $diagnostics['node_modules_yalesites_org_exists'] = is_dir($yalesites_org_root);
    if (is_dir($yalesites_org_root)) {
      $yalesites_org_contents = @scandir($yalesites_org_root);
      if ($yalesites_org_contents) {
        $diagnostics['node_modules_yalesites_org_contents'] = array_filter($yalesites_org_contents, function ($item) {
          return $item !== '.' && $item !== '..';
        });
      }
      // Check if tokens package exists (even without build/json).
      $tokens_package = $yalesites_org_root . '/tokens';
      $diagnostics['tokens_package_exists'] = is_dir($tokens_package);
      if (is_dir($tokens_package)) {
        $tokens_package_contents = @scandir($tokens_package);
        if ($tokens_package_contents) {
          $diagnostics['tokens_package_contents'] = array_filter($tokens_package_contents, function ($item) {
            return $item !== '.' && $item !== '..';
          });
        }
        // Check if build directory exists.
        $tokens_build = $tokens_package . '/build';
        $diagnostics['tokens_build_exists'] = is_dir($tokens_build);
        if (is_dir($tokens_build)) {
          $tokens_build_contents = @scandir($tokens_build);
          if ($tokens_build_contents) {
            $diagnostics['tokens_build_contents'] = array_filter($tokens_build_contents, function ($item) {
              return $item !== '.' && $item !== '..';
            });
          }
          // Check if json directory exists.
          $tokens_json_dir = $tokens_build . '/json';
          $diagnostics['tokens_json_dir_exists'] = is_dir($tokens_json_dir);
          if (is_dir($tokens_json_dir)) {
            $tokens_json_contents = @scandir($tokens_json_dir);
            if ($tokens_json_contents) {
              $diagnostics['tokens_json_dir_contents'] = array_filter($tokens_json_contents, function ($item) {
                return $item !== '.' && $item !== '..';
              });
            }
          }
        }
      }
    }

    // Also check if we can find tokens.json via recursive search.
    $node_modules_base = $atomic_theme_path . '/node_modules';
    if (is_dir($node_modules_base)) {
      $recursive_found = $this->recursiveFindTokens($node_modules_base);
      $diagnostics['recursive_search_found'] = $recursive_found ? $recursive_found : 'No';
    }
    else {
      $diagnostics['recursive_search_found'] = 'node_modules not found';
    }

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

    // Check if component library node_modules directory structure exists.
    $cl_node_modules_base = $atomic_theme_path .
      '/node_modules/@yalesites-org/component-library-twig/node_modules';
    $diagnostics['cl_node_modules_base'] = $cl_node_modules_base;
    $diagnostics['cl_node_modules_base_exists'] = is_dir($cl_node_modules_base);
    if (is_dir($cl_node_modules_base)) {
      $diagnostics['cl_node_modules_base_readable'] = is_readable($cl_node_modules_base);
      // List what's in the node_modules directory.
      $cl_node_modules_contents = @scandir($cl_node_modules_base);
      if ($cl_node_modules_contents) {
        $diagnostics['cl_node_modules_contents'] = array_filter($cl_node_modules_contents, function ($item) {
          return $item !== '.' && $item !== '..';
        });
      }
      // Check if @yalesites-org directory exists.
      $yalesites_org_dir = $cl_node_modules_base . '/@yalesites-org';
      $diagnostics['cl_node_modules_yalesites_org_exists'] = is_dir($yalesites_org_dir);
      if (is_dir($yalesites_org_dir)) {
        $yalesites_org_contents = @scandir($yalesites_org_dir);
        if ($yalesites_org_contents) {
          $diagnostics['cl_node_modules_yalesites_org_contents'] = array_filter($yalesites_org_contents, function ($item) {
            return $item !== '.' && $item !== '..';
          });
        }
        // Check if tokens directory exists.
        $tokens_dir = $yalesites_org_dir . '/tokens';
        $diagnostics['cl_node_modules_tokens_dir_exists'] = is_dir($tokens_dir);
        if (is_dir($tokens_dir)) {
          $tokens_dir_contents = @scandir($tokens_dir);
          if ($tokens_dir_contents) {
            $diagnostics['cl_node_modules_tokens_dir_contents'] = array_filter($tokens_dir_contents, function ($item) {
              return $item !== '.' && $item !== '..';
            });
          }
          // Check if build directory exists.
          $build_dir = $tokens_dir . '/build';
          $diagnostics['cl_node_modules_tokens_build_dir_exists'] = is_dir($build_dir);
          if (is_dir($build_dir)) {
            $build_dir_contents = @scandir($build_dir);
            if ($build_dir_contents) {
              $diagnostics['cl_node_modules_tokens_build_dir_contents'] = array_filter($build_dir_contents, function ($item) {
                return $item !== '.' && $item !== '..';
              });
            }
          }
        }
      }
    }

    return $diagnostics;
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

      if ($this->usingBuiltJson) {
        // Handle built JSON format (node_modules).
        // Access tokens the same way as the Twig template:
        // tokens['global-themes'].
        if (!isset($json['global-themes'])) {
          $this->logger->warning('Token JSON does not contain global-themes key.');
          return [];
        }
        return $this->parseBuiltJson($json['global-themes']);
      }
      else {
        // Handle YAML + JSON format (local dev).
        // For YAML+JSON, global-themes is in the YAML file, not JSON.
        // The JSON only contains the color values that are referenced.
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
   * Parses the YAML + JSON format (from _yale-packages).
   *
   * @param array $json
   *   The full parsed JSON data (for resolving token references).
   *
   * @return array
   *   Array of themes with their color slots and hex values.
   *   Structure matches: { theme_id: { label: "...", colors: {...} } }.
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
      // Structure matches the Twig template: values.label and values.colors.
      $theme_info = [
        'id' => $theme_id,
        'label' => $theme_data['label']['value'] ?? $theme_id,
        'colors' => [],
      ];

      if (isset($theme_data['colors'])) {
        foreach ($theme_data['colors'] as $slot => $slot_data) {
          $token_ref = $slot_data['value'] ?? '';
          $hex_value = $this->resolveTokenReference($token_ref, $json);
          // Store both the token reference and converted hex,
          // matching Twig structure.
          $theme_info['colors'][$slot] = [
            'token' => $token_ref,
            'hex' => $hex_value,
            'name' => $this->getTokenName($token_ref),
            'css_var' => "--global-themes-{$theme_id}-colors-{$slot}",
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
