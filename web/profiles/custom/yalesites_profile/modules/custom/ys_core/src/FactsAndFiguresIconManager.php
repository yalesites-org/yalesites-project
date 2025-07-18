<?php

namespace Drupal\ys_core;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Service for managing Facts and Figures icon options.
 *
 * Reads icon configuration from the component library YAML file and provides
 * organized icon options for Facts & Figures content.
 *
 * The component library is the single source of truth for available icons.
 */
class FactsAndFiguresIconManager {

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Cache ID for icon configuration.
   */
  const CACHE_ID = 'ys_core_facts_figures_icons';

  /**
   * Constructs a new FactsAndFiguresIconManager object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(CacheBackendInterface $cache, ModuleHandlerInterface $module_handler, LoggerChannelFactoryInterface $logger_factory) {
    $this->cache = $cache;
    $this->moduleHandler = $module_handler;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Gets the icon configuration from YAML file.
   *
   * @return array
   *   The parsed icon configuration.
   */
  protected function getIconConfig(): array {
    // Try to get from cache first.
    $cached = $this->cache->get(self::CACHE_ID);
    if ($cached && !empty($cached->data)) {
      return $cached->data;
    }

    // Load and parse the YAML file from component library.
    $config_file = DRUPAL_ROOT . '/themes/contrib/atomic/' .
      'node_modules/@yalesites-org/component-library-twig/components/02-molecules/' .
      'facts-and-figures/facts-and-figures-icons.yml';

    if (!file_exists($config_file)) {
      $this->loggerFactory->get('ys_core')->error('Facts and Figures icons configuration file not found: @file', ['@file' => $config_file]);
      return $this->getFallbackConfig();
    }

    try {
      $config = Yaml::parseFile($config_file);

      // Cache the configuration.
      $cache_tags = $config['cache']['tags'] ?? ['component_library_icons', 'facts_and_figures_icons'];
      $max_age = $config['cache']['max_age'] ?? 3600;

      $this->cache->set(
        self::CACHE_ID,
        $config,
        time() + $max_age,
        $cache_tags
      );

      return $config;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('ys_core')->error('Error parsing Facts and Figures icons configuration: @error', ['@error' => $e->getMessage()]);
      return $this->getFallbackConfig();
    }
  }

  /**
   * Gets fallback configuration if YAML file is missing or invalid.
   *
   * @return array
   *   Minimal fallback configuration.
   */
  protected function getFallbackConfig(): array {
    return [
      'config' => [
        'version' => '1.0',
        'default_value' => '_none',
        'none_label' => '- None -',
        'description' => 'Fallback icon configuration',
      ],
      'icons' => [
        'graduation-cap-solid' => 'Graduation Cap',
        'trophy-solid' => 'Trophy',
        'globe-solid' => 'Globe',
      ],
      'cache' => [
        'tags' => ['component_library_icons', 'facts_and_figures_icons'],
        'max_age' => 3600,
      ],
    ];
  }

  /**
   * Gets the complete icon library.
   *
   * @return array
   *   Associative array of icon options.
   */
  public function getIconOptions(): array {
    $config = $this->getIconConfig();
    return $config['icons'] ?? [];
  }

  /**
   * Gets a flat list of all available icon options for form select elements.
   *
   * @return array
   *   Flat associative array of icon machine names and labels.
   */
  public function getFlatIconOptions(): array {
    $config = $this->getIconConfig();
    $options = [$config['config']['default_value'] => $config['config']['none_label']];

    foreach ($config['icons'] as $icon_key => $icon_label) {
      $options[$icon_key] = $icon_label;
    }

    return $options;
  }

  /**
   * Validates if an icon key is valid.
   *
   * @param string $icon_key
   *   The icon machine name to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  public function isValidIcon(string $icon_key): bool {
    $config = $this->getIconConfig();

    if ($icon_key === $config['config']['default_value']) {
      return TRUE;
    }

    return array_key_exists($icon_key, $config['icons'] ?? []);
  }

  /**
   * Gets the human-readable label for an icon.
   *
   * @param string $icon_key
   *   The icon machine name.
   *
   * @return string|null
   *   The human-readable label or NULL if not found.
   */
  public function getIconLabel(string $icon_key): ?string {
    $config = $this->getIconConfig();

    if ($icon_key === $config['config']['default_value']) {
      return $config['config']['none_label'];
    }

    return $config['icons'][$icon_key] ?? NULL;
  }

  /**
   * Clears the icon configuration cache.
   */
  public function clearCache(): void {
    $this->cache->delete(self::CACHE_ID);
  }

}
