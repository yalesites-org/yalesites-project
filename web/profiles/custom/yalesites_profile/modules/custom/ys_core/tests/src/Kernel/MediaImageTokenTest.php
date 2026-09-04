<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\Serialization\Yaml;
use Drupal\file\Entity\File;
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
 * The separator can also arrive from the uploaded file's own name, which
 * nothing sanitizes, so this also carries the chain through metatag's og:image
 * plugin and asserts on the tags it actually emits.
 *
 * @group ys_core
 * @group yalesites
 */
class MediaImageTokenTest extends YsKernelTestBase {

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
   * A file name containing metatag's separator, which nothing sanitizes away.
   *
   * Every option under file.settings:filename_sanitization is off, so an editor
   * can upload this name verbatim.
   */
  private const SEPARATOR_FILE_NAME = 'sunset, beach.jpg';

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
    'metatag',
    'metatag_open_graph',
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

    // Metatag ships a blank separator that falls back to a comma. Pin the value
    // the platform actually exports, so separator() below and metatag's own
    // plugin both read production's value rather than contrib's default. Its
    // remaining settings stay unset and so read as NULL, which makes
    // MetaNameBase::trimValue() short-circuit -- as it also does in production,
    // where no trim maxlength is configured for og:image.
    $this->config('metatag.settings')
      ->set('separator', $this->readConfig('metatag.settings')['separator'])
      ->save();

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
  private function createImageMedia(string $file_name = self::FILE_NAME): MediaInterface {
    $file = File::create([
      'uri' => 'public://' . $file_name,
      'filename' => $file_name,
    ]);
    $file->save();

    $media = $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->create([
        'bundle' => 'image',
        'name' => $file_name,
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt' => self::ALT_TEXT,
        ],
      ]);
    $media->save();

    return $media;
  }

  /**
   * Returns the separator metatag splits multi-valued tags like og:image on.
   *
   * Read from active config, which setUp() pinned to the platform's own export.
   * Metatag's own getSeparator() substitutes a comma when that value is blank,
   * so the pin is what keeps this helper and metatag in agreement.
   */
  private function separator(): string {
    return $this->config('metatag.settings')->get('separator');
  }

  /**
   * Renders the given value through metatag's real og:image plugin.
   *
   * Asserting on the token value alone would not be enough: og:image's value
   * also passes through parseImageUrl(), whitespace tidying, absolute-URL
   * handling and trimming, any of which could mangle an encoded URL. This goes
   * through the plugin manager rather than instantiating the class so the
   * plugin's own annotation, which is what marks og:image multi-valued, is the
   * thing under test.
   *
   * @return array
   *   The render array elements metatag would emit for this value.
   */
  private function ogImageOutput(string $value): array {
    $tag = $this->container->get('plugin.manager.metatag.tag')
      ->createInstance('og_image');
    $tag->setValue($value);

    return $tag->output();
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
    $separator = $this->separator();
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

  /**
   * A separator in the uploaded file's own name still yields one og:image tag.
   *
   * The token resolving to a bare URL is only half the guarantee: the URL
   * itself has to be free of metatag's separator, or metatag's multi-value
   * splitting would shatter it the same way the alt text did. Core
   * percent-encodes the file path when it builds the URL, which is what keeps
   * that true, so this asserts the whole chain rather than the encoding alone:
   * it would fail if a future formatter, image style, or stream wrapper emitted
   * the raw name.
   */
  public function testSeparatorInFileNameYieldsOneOgImageTag(): void {
    $this->assertStringContainsString($this->separator(), self::SEPARATOR_FILE_NAME, 'The fixture file name should contain the separator.');

    $media = $this->createImageMedia(self::SEPARATOR_FILE_NAME);
    $url = trim($this->replaceImageToken($media, '[media:field_media_image]'));
    $this->assertNotEmpty($url, 'The token should resolve to the image URL.');

    $elements = $this->ogImageOutput($url);

    // Each assertion catches a different way this has failed or could fail: the
    // reported symptom was several tags; a naive fix could leave one truncated
    // tag; and og:image is useless to a crawler if it is not absolute.
    $this->assertCount(1, $elements, 'A separator in the file name must not split og:image into several tags.');
    $content = $elements[0]['#attributes']['content'];
    $this->assertStringEndsWith(rawurlencode(self::SEPARATOR_FILE_NAME), $content, 'The emitted URL should end in the whole encoded file name.');
    $this->assertStringStartsWith('http', $content, 'og:image must be an absolute URL.');
  }

  /**
   * Control for the test above: an unencoded separator really does split.
   *
   * Without this, the one-tag assertion could pass vacuously. Should metatag
   * stop splitting values this way, this fails and that guard can be relaxed.
   */
  public function testMetatagSplitsAnUnencodedSeparator(): void {
    $elements = $this->ogImageOutput('http://localhost/files/' . self::SEPARATOR_FILE_NAME);

    $this->assertGreaterThan(1, count($elements), sprintf("Metatag is expected to split og:image on '%s'.", $this->separator()));
  }

}
