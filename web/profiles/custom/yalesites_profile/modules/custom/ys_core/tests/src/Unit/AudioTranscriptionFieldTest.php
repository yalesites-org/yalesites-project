<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Audio Player block has an optional Transcription field.
 *
 * WCAG 2.1 Level A (SC 1.2.1) needs a text alternative for prerecorded audio,
 * and Editoria11y asks editors "Does this audio have a transcript?" on every
 * Audio Player, but the block gave them nowhere to put one (#1507). The block
 * now carries a dedicated rich-text Transcription field, deliberately not the
 * shared field_text, which on Video carries a 200-character counter sized for
 * a short description.
 *
 * Asserted against the exported YAML, like AudioTranscriptFieldDescriptionTest:
 * config/sync is what a deploy imports.
 *
 * @group ys_core
 * @group yalesites
 */
class AudioTranscriptionFieldTest extends UnitTestCase {

  /**
   * Parses one exported config file from the profile's config/sync.
   */
  protected function syncConfig(string $name): array {
    $file = dirname(__DIR__, 6) . '/config/sync/' . $name . '.yml';
    $this->assertFileExists($file);
    return Yaml::parseFile($file);
  }

  /**
   * The field is a dedicated, optional, rich-text field labeled Transcription.
   */
  public function testTranscriptionFieldIsOptionalRichText(): void {
    $storage = $this->syncConfig('field.storage.block_content.field_transcription');
    $this->assertSame('text_long', $storage['type']);

    $field = $this->syncConfig('field.field.block_content.audio.field_transcription');
    $this->assertSame('audio', $field['bundle']);
    $this->assertSame('Transcription', $field['label']);
    $this->assertFalse($field['required'], 'Audio without a transcript must still publish.');
    $this->assertSame(['restricted_html'], $field['settings']['allowed_formats']);
    $this->assertStringContainsStringIgnoringCase('transcript', $field['description']);
  }

  /**
   * The form shows a large text box with no length counter.
   */
  public function testFormDisplayHasLargeBoxWithoutLengthLimit(): void {
    $display = $this->syncConfig('core.entity_form_display.block_content.audio.default');
    $widget = $display['content']['field_transcription'] ?? NULL;
    $this->assertNotNull($widget, 'Transcription is missing from the Audio block form.');
    $this->assertSame('text_textarea', $widget['type']);
    $this->assertGreaterThanOrEqual(10, $widget['settings']['rows']);
    $this->assertArrayNotHasKey('maxlength', $widget['third_party_settings']);
    $this->assertArrayNotHasKey('field_group', $display['third_party_settings'] ?? [], 'The Audio form must stay flat, with no tabs.');
  }

  /**
   * The transcript renders on the page.
   */
  public function testViewDisplayRendersTranscription(): void {
    $display = $this->syncConfig('core.entity_view_display.block_content.audio.default');
    $formatter = $display['content']['field_transcription'] ?? NULL;
    $this->assertNotNull($formatter, 'Transcription is hidden on the Audio block view display.');
    $this->assertSame('text_default', $formatter['type']);
  }

  /**
   * Editoria11y stops asking for a transcript once one is supplied.
   *
   * The component marks a player that has a transcript with the
   * audio-embed--has-transcript modifier; Editoria11y skips audio inside it.
   */
  public function testEditoria11ySkipsAudioWithTranscript(): void {
    $settings = $this->syncConfig('editoria11y.settings');
    $selectors = array_map('trim', explode(',', $settings['ignore_elements']));
    $this->assertContains('.audio-embed--has-transcript audio', $selectors);
  }

}
