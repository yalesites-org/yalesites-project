<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\Bluesky;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\Bluesky
 *
 * @group yalesites
 */
class BlueskyTest extends UnitTestCase {

  /**
   * The Bluesky plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\Bluesky
   */
  protected $plugin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $configFactory = $this->getConfigFactoryStub([
      'media.settings' => ['icon_base_uri' => 'public://media-icons'],
    ]);
    $this->plugin = new Bluesky([], 'bluesky', [], $configFactory);
  }

  /**
   * @covers ::build
   */
  public function testBuildFallsBackToDefaultTitleWhenBlank(): void {
    $params = ['title' => ''];
    $build = $this->plugin->build($params);
    $this->assertSame('Bluesky post embed', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildUsesProvidedTitleOverDefault(): void {
    $params = ['title' => 'Custom Bluesky title'];
    $build = $this->plugin->build($params);
    $this->assertSame('Custom Bluesky title', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildPreservesLiteralZeroTitle(): void {
    $params = ['title' => '0'];
    $build = $this->plugin->build($params);
    $this->assertSame('0', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildFallsBackToDefaultTitleWhenWhitespaceOnly(): void {
    $params = ['title' => '   '];
    $build = $this->plugin->build($params);
    $this->assertSame('Bluesky post embed', $build['#title']);
  }

}
