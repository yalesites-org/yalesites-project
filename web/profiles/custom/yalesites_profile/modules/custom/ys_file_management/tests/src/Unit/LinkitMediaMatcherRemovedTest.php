<?php

namespace Drupal\Tests\ys_file_management\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the Linkit "default" profile against re-introducing a media matcher.
 *
 * A "media" link resolves to the media entity's canonical route, which for a
 * document is /media/{id}/edit and is forbidden to anonymous visitors, so
 * linking a document via "media" produced an inaccessible page. Because every
 * CKEditor instance and Linkit link-field widget shares this single "default"
 * profile, issue #835 removed the entity:media matcher so documents can only be
 * linked via the working file matcher. This test fails if the exported profile
 * regains a media matcher.
 *
 * @group yalesites
 */
class LinkitMediaMatcherRemovedTest extends UnitTestCase {

  /**
   * Absolute path to the profile's exported config/sync directory.
   */
  protected function configSyncDir(): string {
    return dirname(__DIR__, 6) . '/config/sync';
  }

  /**
   * The default Linkit profile must not offer a media matcher.
   */
  public function testDefaultProfileHasNoMediaMatcher(): void {
    $file = $this->configSyncDir() . '/linkit.linkit_profile.default.yml';
    $this->assertFileExists($file);
    $profile = Yaml::parseFile($file);

    $this->assertArrayHasKey('matchers', $profile, 'The Linkit "default" profile must define matchers.');

    foreach ($profile['matchers'] as $uuid => $matcher) {
      $this->assertNotSame('entity:media', $matcher['id'] ?? NULL, sprintf('The Linkit "default" profile must not offer a media link matcher (found matcher %s). Media links resolve to an inaccessible page; link documents via the file matcher instead (issue #835).', $uuid));
    }
  }

}
