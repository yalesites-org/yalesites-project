<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\Instagram;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\Instagram
 *
 * @group yalesites
 */
class InstagramTest extends UnitTestCase {

  /**
   * The Instagram plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\Instagram
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
    $this->plugin = new Instagram([], 'instagram', [], $configFactory);
  }

  /**
   * @covers ::build
   */
  public function testBuildFallsBackToDefaultTitleWhenBlank(): void {
    $params = ['title' => ''];
    $build = $this->plugin->build($params);
    $this->assertSame('Instagram post embed', $build['#title']);
  }

  /**
   * @covers ::build
   */
  public function testBuildUsesProvidedTitleOverDefault(): void {
    $params = ['title' => 'Custom Instagram title'];
    $build = $this->plugin->build($params);
    $this->assertSame('Custom Instagram title', $build['#title']);
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
    $this->assertSame('Instagram post embed', $build['#title']);
  }

}
