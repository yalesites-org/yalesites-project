<?php

namespace Drupal\Tests\ys_beacon\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards that the Beacon content index indexes items immediately on save.
 *
 * The chat answers about freshly published or edited content, so an editor's
 * save must reach the vector index without waiting for cron or a manual
 * "Index now" (issue #1335). Search API only indexes at the end of the request
 * when the index carries `options.index_directly: true` (and is not read-only).
 *
 * The index ships from the profile config-sync directory rather than this
 * module's config/install, so this test reads the synced YAML directly — the
 * same fixture approach as BeaconConfigDefaultsTest.
 *
 * @group ys_beacon
 */
class BeaconImmediateIndexingTest extends UnitTestCase {

  /**
   * Returns the parsed synced config for the ys_beacon search index.
   */
  private function indexConfig(): array {
    $path = dirname(__DIR__, 6) . '/config/sync/search_api.index.ys_beacon.yml';
    $this->assertFileExists($path);
    return Yaml::parseFile($path);
  }

  /**
   * Saving eligible content indexes it immediately, not on the next cron run.
   */
  public function testIndexDirectlyIsEnabled(): void {
    $this->assertTrue($this->indexConfig()['options']['index_directly']);
  }

  /**
   * The index must be writable for immediate indexing to reach the server.
   */
  public function testIndexIsNotReadOnly(): void {
    $this->assertFalse($this->indexConfig()['read_only']);
  }

}
