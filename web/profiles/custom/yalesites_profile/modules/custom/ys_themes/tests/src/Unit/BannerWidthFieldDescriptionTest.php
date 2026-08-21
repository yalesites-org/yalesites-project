<?php

namespace Drupal\Tests\ys_themes\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Banner Width dial tells editors when Full Width actually looks different.
 *
 * "Contained" caps the banner at --break-max-width (2400px) while "Full Width"
 * goes to 100vw, so on a narrower viewport the two render identically. Measured
 * on the rendered page: at 1440px and 1920px both are 1440/1920 wide at left 0,
 * while at 2560px Full Width is 2560 and Contained is 2400 centred at left 80.
 * Without a field description an editor on an ordinary monitor changes the
 * dial, sees nothing happen, and concludes the setting is broken (#1502).
 *
 * Asserted against the exported YAML rather than a loaded FieldConfig, for the
 * reason InlineMessageIconFieldTest gives in
 * testExportedFieldConfigDefaultsToLegacyIcon(): config/sync is what a deploy
 * imports, so a re-export taken from a database that never imported this change
 * would silently drop the copy again. The path idiom is ys_mail
 * MailchimpConfigKeysTest::configSyncDir(); that Kernel test resolves the same
 * directory through extension.list.profile instead.
 *
 * Deliberately scoped to the three banners that share the 2400px threshold.
 * video_banner also offers Contained/Full Width, but its "Contained" resolves
 * to --size-component-layout-width-max (100rem/1600px, see
 * components/02-molecules/banner/video/yds-video-banner.scss), so it diverges
 * on an ordinary 1920px monitor and this copy would be wrong there.
 *
 * @group ys_themes
 * @group yalesites
 */
class BannerWidthFieldDescriptionTest extends UnitTestCase {

  /**
   * Block types whose Banner Width dial caps "Contained" at 2400px.
   */
  const BANNER_BUNDLES = ['cta_banner', 'grand_hero', 'image_banner'];

  /**
   * Absolute path to the profile's exported config/sync directory.
   */
  protected function configSyncDir(): string {
    return dirname(__DIR__, 6) . '/config/sync';
  }

  /**
   * Every banner width dial carries the same explanation of its two options.
   *
   * Asserted together rather than as separate cases because a per-bundle check
   * alone passes while all three are empty, and a consistency check alone
   * passes while all three are identically empty.
   */
  public function testBannerWidthFieldsExplainBothOptionsConsistently(): void {
    $descriptions = [];

    foreach (self::BANNER_BUNDLES as $bundle) {
      $file = $this->configSyncDir()
        . "/field.field.block_content.$bundle.field_style_width.yml";
      $this->assertFileExists($file);

      $description = Yaml::parseFile($file)['description'] ?? '';
      $this->assertNotSame(
        '',
        $description,
        "The $bundle Banner Width dial has no description, so an editor who " .
        'sees no visual change has nothing telling them that is expected.'
      );
      $this->assertStringContainsString('Full Width', $description);
      $this->assertStringContainsString('Contained', $description);

      $descriptions[$bundle] = $description;
    }

    // One threshold and one pair of option labels, so letting the copy drift
    // apart would teach editors three stories about the same control.
    $this->assertCount(
      1,
      array_unique($descriptions),
      'Every banner width dial should carry the same explanation: '
      . print_r($descriptions, TRUE)
    );
  }

}
