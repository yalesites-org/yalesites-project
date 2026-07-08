<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\SocialLinksManager;

/**
 * Characterisation tests for SocialLinksManager.
 *
 * Pins the current behaviour of the ys_core.social_links reader ahead of the
 * #579 module extraction: only networks with a configured URL are rendered,
 * in the SITES declaration order, each with its URL, name and icon id.
 *
 * @coversDefaultClass \Drupal\ys_core\SocialLinksManager
 *
 * @group ys_core
 */
class SocialLinksManagerTest extends UnitTestCase {

  /**
   * Builds a manager whose ys_core.social_links config returns $values.
   *
   * @param array $values
   *   Map of social network id to configured URL.
   *
   * @return \Drupal\ys_core\SocialLinksManager
   *   The manager under test.
   */
  protected function managerWith(array $values): SocialLinksManager {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn($key) => $values[$key] ?? NULL);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('ys_core.social_links')->willReturn($config);
    return new SocialLinksManager($factory);
  }

  /**
   * No configured links yields no renderable links.
   *
   * @covers ::buildRenderableLinks
   * @covers ::isSocialLinkSet
   */
  public function testNoLinksConfigured(): void {
    $this->assertSame([], $this->managerWith([])->buildRenderableLinks());
  }

  /**
   * Only configured networks render, in SITES declaration order.
   *
   * @covers ::buildRenderableLinks
   */
  public function testOnlyConfiguredLinksInSiteOrder(): void {
    // Set out of SITES order to prove the output is ordered by SITES.
    $links = $this->managerWith([
      'linkedin' => 'https://linkedin.com/example',
      'facebook' => 'https://facebook.com/example',
    ])->buildRenderableLinks();

    $this->assertSame(
      [
        ['url' => 'https://facebook.com/example', 'name' => 'Facebook', 'icon' => 'facebook'],
        ['url' => 'https://linkedin.com/example', 'name' => 'LinkedIn', 'icon' => 'linkedin'],
      ],
      $links
    );
  }

  /**
   * A falsy configured value excludes that network.
   *
   * @covers ::buildRenderableLinks
   * @covers ::isSocialLinkSet
   */
  public function testFalsyValueExcluded(): void {
    $links = $this->managerWith([
      'facebook' => '',
      'instagram' => 'https://instagram.com/example',
    ])->buildRenderableLinks();

    $this->assertCount(1, $links);
    $this->assertSame('instagram', $links[0]['icon']);
  }

  /**
   * Returns the configured URL for a network, or NULL when unset.
   *
   * @covers ::getSocialLinkUrl
   */
  public function testGetSocialLinkUrl(): void {
    $manager = $this->managerWith(['youtube' => 'https://youtube.com/@example']);
    $this->assertSame('https://youtube.com/@example', $manager->getSocialLinkUrl('youtube'));
    $this->assertNull($manager->getSocialLinkUrl('weibo'));
  }

  /**
   * Every supported network renders when all are configured.
   *
   * @covers ::buildRenderableLinks
   */
  public function testAllNetworksSupported(): void {
    $links = $this->managerWith([
      'facebook' => 'https://facebook.com/x',
      'instagram' => 'https://instagram.com/x',
      'x-twitter' => 'https://x.com/x',
      'youtube' => 'https://youtube.com/x',
      'weibo' => 'https://weibo.com/x',
      'linkedin' => 'https://linkedin.com/x',
      'bluesky' => 'https://bsky.app/x',
    ])->buildRenderableLinks();

    $this->assertCount(7, $links);
    $this->assertSame(
      ['facebook', 'instagram', 'x-twitter', 'youtube', 'weibo', 'linkedin', 'bluesky'],
      array_column($links, 'icon')
    );
    // The X label preserves the source's exact (misspelled) string.
    $this->assertSame('X (formally Twitter)', $links[2]['name']);
  }

}
