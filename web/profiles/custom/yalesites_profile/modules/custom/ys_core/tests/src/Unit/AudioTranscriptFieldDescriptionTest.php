<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Audio Player block tells editors that speech audio needs a transcript.
 *
 * WCAG 2.1 AA needs a text alternative for prerecorded audio (1.2.1), but the
 * block form said nothing about it, so editors published speech audio with no
 * transcript and only found out at accessibility review (#1769). The sibling
 * Audio label field on this same block already carries a description, so the
 * copy renders in the Layout Builder form with no other change.
 *
 * Asserted against the exported YAML rather than a loaded FieldConfig, for the
 * reason BannerWidthFieldDescriptionTest and InlineMessageIconFieldTest give:
 * config/sync is what a deploy imports, so a re-export taken from a database
 * that never imported this change would silently drop the copy again.
 *
 * @group ys_core
 * @group yalesites
 */
class AudioTranscriptFieldDescriptionTest extends UnitTestCase {

  /**
   * Absolute path to the profile's exported config/sync directory.
   */
  protected function configSyncDir(): string {
    return dirname(__DIR__, 6) . '/config/sync';
  }

  /**
   * The Audio file field explains the transcript expectation.
   *
   * Substring assertions rather than an exact match. The copy itself is fixed,
   * approved wording, so it is review that keeps it intact, not this test. What
   * the test has to catch is the copy being dropped or gutted by a re-export,
   * without failing on the punctuation or casing an export may normalise.
   */
  public function testAudioFileFieldExplainsTranscriptExpectation(): void {
    $file = $this->configSyncDir()
      . '/field.field.block_content.audio.field_media.yml';
    $this->assertFileExists($file);

    $description = Yaml::parseFile($file)['description'] ?? '';
    $this->assertNotSame(
      '',
      $description,
      'The Audio file field has no description, so nothing on the block form '
      . 'tells an editor that audio containing speech needs a transcript.'
    );
    $this->assertStringContainsStringIgnoringCase('transcript', $description);
    $this->assertStringContainsStringIgnoringCase(
      'described in text',
      $description,
      'The copy should cover all audio, not only audio containing speech.'
    );
  }

}
