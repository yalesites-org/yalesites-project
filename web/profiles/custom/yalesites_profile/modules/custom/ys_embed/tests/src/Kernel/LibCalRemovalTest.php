<?php

namespace Drupal\Tests\ys_embed\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Confirms the LibCal embed source has been fully removed.
 *
 * LibCal was an unsupported embed source with a known security vulnerability
 * (it injected author-supplied embed code and loaded remote scripts). It was
 * removed in full; this test guards against it being reintroduced as a
 * discoverable embed source.
 *
 * @group ys_embed
 * @group yalesites
 */
class LibCalRemovalTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ys_embed'];

  /**
   * The 'libcal' source is neither discoverable nor selectable.
   *
   * Asserted together in one test so the plugin manager is only booted once.
   */
  public function testLibCalEmbedSourceIsRemoved(): void {
    $manager = $this->container->get('plugin.manager.embed_source');

    // No plugin with the 'libcal' id exists at all (hard removal).
    $this->assertFalse(
      $manager->isValidSourceId('libcal'),
      'The libcal embed source must not be discoverable.'
    );

    // It is not offered in the selectable source list an editor sees.
    $this->assertArrayNotHasKey(
      'libcal',
      $manager->getSources(),
      'The libcal embed source must not appear in the source list.'
    );

    // Positive control: a retained source is still available, so a pass above
    // reflects libcal's removal rather than broken plugin discovery.
    $this->assertTrue(
      $manager->isValidSourceId('instagram'),
      'Non-LibCal embed sources must remain available.'
    );
  }

}
