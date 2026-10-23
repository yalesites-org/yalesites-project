<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\AiReadabilitySettings;

/**
 * Tests that a missing AI readability key reads as ON.
 *
 * @group ys_core
 */
class AiReadabilitySettingsTest extends UnitTestCase {

  /**
   * Missing and TRUE read as ON; only an explicit FALSE reads as OFF.
   */
  public function testIsEnabled(): void {
    $key = AiReadabilitySettings::MARKDOWN_ENABLED;
    $config = $this->getConfigFactoryStub(['ys_core.site' => []])->get('ys_core.site');
    $this->assertTrue(AiReadabilitySettings::isEnabled($config, $key), 'Missing key.');

    foreach ([TRUE, FALSE] as $value) {
      $config = $this->getConfigFactoryStub([
        'ys_core.site' => ['ai_readability' => ['markdown_enabled' => $value]],
      ])->get('ys_core.site');
      $this->assertSame($value, AiReadabilitySettings::isEnabled($config, $key));
    }
  }

}
