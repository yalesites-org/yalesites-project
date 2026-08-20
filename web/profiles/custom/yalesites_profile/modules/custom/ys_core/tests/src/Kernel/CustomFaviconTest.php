<?php

namespace Drupal\Tests\ys_core\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\file\Entity\File;
use Drupal\ys_core\YaleSitesMediaManager;

/**
 * Tests the custom-favicon branch of YaleSitesMediaManager::getFavicons().
 *
 * The unit test for this branch stubs a single image style for all four sizes,
 * so it cannot show that each size resolves to its own distinct URL. Proving
 * that needs the real shipped image styles and a real saved `custom_favicon`
 * value, which means saving ys_core config -- impossible until this module's
 * settings objects had a config schema (yalesites-org/YaleSites-Internal#1563).
 *
 * The image styles are loaded from the profile's own config/sync rather than a
 * fixture, so this fails if the shipped styles stop producing four distinct
 * derivatives.
 *
 * @group ys_core
 * @group yalesites
 */
class CustomFaviconTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'image', 'ys_core'];

  /**
   * The image styles getFavicons() renders each favicon size through.
   */
  private const STYLES = [
    'favicon_180x180',
    'favicon_32x32',
    'favicon_16x16',
    'favicon_16x16_ico',
  ];

  /**
   * The uploaded favicon.
   */
  protected File $favicon;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installConfig(['system', 'file', 'image']);

    foreach (self::STYLES as $style) {
      $this->installProfileImageStyle($style);
    }

    $this->favicon = $this->uploadFavicon();
  }

  /**
   * A saved custom favicon overrides every fallback with its own derivative.
   */
  public function testEverySizeResolvesToItsOwnDerivative(): void {
    $this->saveCustomFavicon();

    $favicons = $this->mediaManager()->getFavicons();

    $this->assertSame(
      ['apple-touch-icon', 'icon-32', 'icon-16', 'icon-ico'],
      array_keys($favicons),
      'A resolvable custom favicon keeps all four sizes.'
    );

    $hrefs = [];
    foreach ($favicons as $key => $favicon) {
      $href = $favicon['#attributes']['href'];
      $this->assertStringContainsString(
        '/styles/' . $favicon['#image_style'] . '/',
        $href,
        "$key was not rendered through its own image style."
      );
      $this->assertStringNotContainsString(
        '/images/favicons/',
        $href,
        "$key still points at the shipped fallback file."
      );
      $hrefs[$key] = $href;
    }

    // The whole point of four image styles: four distinct derivative URLs.
    $this->assertCount(4, array_unique($hrefs), 'Two sizes resolved to the same URL.');
  }

  /**
   * With no custom favicon saved, the shipped fallback files are used.
   */
  public function testFallbackIsUsedWhenNoCustomFaviconIsSaved(): void {
    $favicons = $this->mediaManager()->getFavicons();

    $this->assertCount(4, $favicons);
    foreach ($favicons as $key => $favicon) {
      $this->assertStringContainsString(
        '/images/favicons/',
        $favicon['#attributes']['href'],
        "$key did not fall back to a shipped favicon file."
      );
    }
  }

  /**
   * A size whose image style is missing is dropped rather than left stale.
   */
  public function testSizeIsDroppedWhenItsImageStyleIsMissing(): void {
    $this->saveCustomFavicon();

    $this->container->get('entity_type.manager')
      ->getStorage('image_style')
      ->load('favicon_16x16_ico')
      ->delete();

    $favicons = $this->mediaManager()->getFavicons();

    $this->assertArrayNotHasKey('icon-ico', $favicons);
    $this->assertCount(3, $favicons);
  }

  /**
   * Points the site's custom favicon setting at the uploaded file.
   */
  private function saveCustomFavicon(): void {
    $this->config('ys_core.site')
      ->set('custom_favicon', [(int) $this->favicon->id()])
      ->save();
  }

  /**
   * The media manager service under test.
   */
  private function mediaManager(): YaleSitesMediaManager {
    // The service is shared, and it holds the immutable Config object it read
    // at construction - but ConfigFactory re-initialises that same object's
    // data on every save, so it does see a newly saved favicon.
    return $this->container->get('ys_core.media_manager');
  }

  /**
   * Copies a shipped favicon into the file system and registers it as a file.
   */
  private function uploadFavicon(): File {
    $source = \Drupal::root() . '/'
      . \Drupal::service('extension.list.module')->getPath('ys_core')
      . '/images/favicons/apple-touch-icon.png';

    $file_system = $this->container->get('file_system');
    $directory = 'public://favicons';
    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $uri = $file_system->copy($source, $directory . '/custom-favicon.png');

    $file = File::create(['uri' => $uri]);
    $file->setPermanent();
    $file->save();

    return $file;
  }

  /**
   * Creates one of the profile's exported image styles.
   */
  private function installProfileImageStyle(string $name): void {
    $path = \Drupal::root() . '/'
      . \Drupal::service('extension.list.profile')->getPath('yalesites_profile')
      . '/config/sync/image.style.' . $name . '.yml';
    $data = Yaml::decode(file_get_contents($path));
    unset($data['_core']);

    $this->container->get('entity_type.manager')
      ->getStorage('image_style')
      ->create($data)
      ->save();
  }

}
