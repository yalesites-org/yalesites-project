<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\Broken;

/**
 * Tests the Broken fallback EmbedSource plugin's real, by-design behavior.
 *
 * Broken is an intentional fallback plugin (not a GAP to fix): it never
 * matches user input and always renders the "broken" theme regardless of
 * the params it is given.
 *
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\Broken
 *
 * @group yalesites
 * @group ys_embed
 */
class BrokenTest extends UnitTestCase {

  /**
   * The Broken plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\Broken
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
    $this->plugin = new Broken([], 'broken', [], $configFactory);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAlwaysReturnsFalseForEmptyString(): void {
    $this->assertFalse(Broken::isValid(''));
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidAlwaysReturnsFalseForArbitraryInput(): void {
    // isValid() is overridden to unconditionally return FALSE, so Broken
    // never matches even though its own $pattern ('/^$/') would technically
    // match an empty string if the base class implementation were used.
    $this->assertFalse(Broken::isValid('anything at all'));
    $this->assertFalse(Broken::isValid(NULL));
  }

  /**
   * @covers ::build
   */
  public function testBuildReturnsBrokenThemeRegardlessOfParams(): void {
    $this->assertSame(['#theme' => 'broken'], $this->plugin->build([]));
    $this->assertSame(['#theme' => 'broken'], $this->plugin->build(['title' => 'ignored', 'url' => 'ignored']));
  }

  /**
   * @covers ::getInstructions
   * @covers ::getExample
   */
  public function testInstructionsAndExampleAreBothLiterallyBrokenMissing(): void {
    $this->assertSame('Broken/Missing', Broken::getInstructions());
    $this->assertSame('Broken/Missing', Broken::getExample());
  }

}
