<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the shipped default values in the Beacon install config.
 *
 * Guards the platform defaults that drive UX expectations: content metadata
 * fields are shown by default, and the fallback system prompt ships with the
 * full YaleSites system instruction rather than a placeholder.
 *
 * @group ys_beacon
 */
class BeaconConfigDefaultsTest extends UnitTestCase {

  /**
   * Returns the parsed install config for ys_beacon.settings.
   */
  private function installSettings(): array {
    $path = dirname(__DIR__, 3) . '/config/install/ys_beacon.settings.yml';
    $this->assertFileExists($path);
    return Yaml::parseFile($path);
  }

  /**
   * Content metadata fields are exposed on content forms by default.
   */
  public function testContentMetadataIsOnByDefault(): void {
    $this->assertTrue($this->installSettings()['enable_metadata_fields']);
  }

  /**
   * The fallback prompt ships with the full YaleSites system instruction.
   */
  public function testFallbackPromptShipsYaleSitesInstruction(): void {
    $prompt = $this->installSettings()['fallback_system_prompt'];
    $this->assertStringStartsWith('# YaleSites AI System Instruction', $prompt);
    // A placeholder would be a single short sentence; the real instruction is
    // a multi-section document.
    $this->assertStringContainsString('## Identity & Purpose', $prompt);
    $this->assertStringContainsString('## Ethical Guidelines', $prompt);
  }

}
