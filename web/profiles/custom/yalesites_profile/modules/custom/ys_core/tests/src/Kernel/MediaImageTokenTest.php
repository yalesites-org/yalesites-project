<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Serialization\Yaml;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\MediaInterface;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests that an image media token resolves to a bare URL, not rendered markup.
 *
 * Social-sharing metatags build og:image from the image field of the teaser
 * media, e.g. [node:field_teaser_media:entity:field_media_image]. Metatag
 * declares og:image multi-valued and splits the tag value on
 * metatag.settings:separator (a comma), so that token has to resolve to a bare
 * URL and nothing else.
 *
 * The media image "token" view display is what guarantees that: the token
 * module only uses it when the display is enabled, otherwise it falls back to
 * core's default image formatter and the token resolves to a whole <img> tag
 * with the alt text inside it.
 *
 * @group ys_core
 * @group yalesites
 */
class MediaImageTokenTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * Alt text from the reported case: its commas are what split the tag.
   */
  private const ALT_TEXT = 'Red, yellow, and white sculpture against a wintery background';

  /**
   * The file name of the fixture image.
   */
  private const FILE_NAME = 'sculpture-in-snow.jpg';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'field',
    'image',
    'media',
    'token',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', 'file_usage');
    $this->installConfig(['system', 'field', 'file', 'media']);

    $this->createMediaType('image', ['id' => 'image']);

    // Install the profile's own view mode and display rather than a fixture
    // copy, so this test fails if the shipped config stops resolving tokens to
    // a bare URL. The view mode is required: saving the display resolves its
    // config dependency on the mode entity.
    $this->installProfileConfig('entity_view_mode', 'core.entity_view_mode.media.token');
    $this->installProfileConfig('entity_view_display', 'core.entity_view_display.media.image.token');

    // Rendering an image field checks view access on the referenced file.
    $this->container->get('current_user')
      ->setAccount($this->createUser(['access content']));
  }

  /**
   * Reads a config file from the profile's sync directory.
   */
  private function readConfig(string $name): array {
    return Yaml::decode(file_get_contents(
      \Drupal::root() . '/profiles/custom/yalesites_profile/config/sync/' . $name . '.yml'
    ));
  }

  /**
   * Creates a config entity of the given type from the profile's own export.
   */
  private function installProfileConfig(string $entity_type_id, string $config_name): void {
    $data = $this->readConfig($config_name);
    // Layout Builder's per-display settings have no bearing on how a token
    // renders, and validating their schema would mean installing Layout Builder
    // and its three dependencies into this test.
    unset($data['_core'], $data['third_party_settings']);
    $this->container->get('entity_type.manager')
      ->getStorage($entity_type_id)
      ->create($data)
      ->save();
  }

  /**
   * Creates an image media whose alt text contains commas.
   */
  private function createImageMedia(): MediaInterface {
    $file = File::create([
      'uri' => 'public://' . self::FILE_NAME,
      'filename' => self::FILE_NAME,
    ]);
    $file->save();

    $media = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->create([
        'bundle' => 'image',
        'name' => self::FILE_NAME,
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt' => self::ALT_TEXT,
        ],
      ]);
    $media->save();

    return $media;
  }

  /**
   * Replaces the given token for the given media.
   */
  private function replaceImageToken(MediaInterface $media, string $token): string {
    return (string) $this->container->get('token')
      ->replace($token, ['media' => $media]);
  }

  /**
   * The image token resolves to a bare URL, not markup carrying alt text.
   *
   * The separator assertion is the invariant that actually failed: og:image is
   * split on it, so a value containing one becomes several malformed tags.
   */
  public function testImageTokenResolvesToBareUrl(): void {
    $separator = $this->readConfig('metatag.settings')['separator'];
    $this->assertNotEmpty($separator, 'Metatag should define a multi-value separator.');
    $this->assertStringContainsString($separator, self::ALT_TEXT, 'The fixture alt text should contain the separator.');

    $output = trim($this->replaceImageToken($this->createImageMedia(), '[media:field_media_image]'));

    // Asserted first so an empty replacement fails here rather than passing the
    // negative assertions vacuously.
    $this->assertStringContainsString(self::FILE_NAME, $output, 'The token should resolve to the image URL.');
    $this->assertStringNotContainsString('<', $output, 'The token should resolve to a URL, not rendered markup.');
    $this->assertStringNotContainsString($separator, $output, 'The URL must not contain the multi-value separator.');
  }

  /**
   * The alt property token still resolves, so og:image:alt keeps working.
   *
   * Property tokens do not route through a view display, so this guards against
   * a wrong fix that strips alt text rather than against this config changing.
   */
  public function testAltPropertyTokenStillResolves(): void {
    $this->assertSame(
      self::ALT_TEXT,
      trim($this->replaceImageToken($this->createImageMedia(), '[media:field_media_image:alt]')),
    );
  }

}
