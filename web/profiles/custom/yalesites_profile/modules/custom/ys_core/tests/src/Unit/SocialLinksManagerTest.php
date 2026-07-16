<?php

namespace Drupal\Tests\ys_core\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_core\SocialLinksManager;

/**
 * Tests SocialLinksManager's social link resolution.
 *
 * @coversDefaultClass \Drupal\ys_core\SocialLinksManager
 *
 * @group ys_core
 * @group yalesites
 */
class SocialLinksManagerTest extends UnitTestCase {

  /**
   * Builds a SocialLinksManager backed by a config mock with given values.
   *
   * @param array $values
   *   Map of social network id to configured URL (or NULL/empty if unset).
   *
   * @return \Drupal\ys_core\SocialLinksManager
   *   The manager under test.
   */
  protected function managerWithLinks(array $values): SocialLinksManager {
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnCallback(function ($id) use ($values) {
      return $values[$id] ?? NULL;
    });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('ys_core.social_links')
      ->willReturn($config);

    return new SocialLinksManager($configFactory);
  }

  /**
   * @covers ::buildRenderableLinks
   */
  public function testBuildRenderableLinksReturnsEmptyWhenNoneConfigured(): void {
    $manager = $this->managerWithLinks([]);
    $this->assertSame([], $manager->buildRenderableLinks());
  }

  /**
   * @covers ::buildRenderableLinks
   */
  public function testBuildRenderableLinksOnlyIncludesConfiguredSites(): void {
    $manager = $this->managerWithLinks([
      'facebook' => 'https://facebook.com/yale',
      'bluesky' => 'https://bsky.app/yale',
      // Empty string is falsy, so instagram should be excluded.
      'instagram' => '',
    ]);

    $links = $manager->buildRenderableLinks();

    // Order follows SocialLinksManager::SITES, not the values() insertion.
    $this->assertSame([
      ['url' => 'https://facebook.com/yale', 'name' => 'Facebook', 'icon' => 'facebook'],
      ['url' => 'https://bsky.app/yale', 'name' => 'Bluesky', 'icon' => 'bluesky'],
    ], $links);
  }

  /**
   * @covers ::getSocialLinkUrl
   */
  public function testGetSocialLinkUrlReturnsConfiguredValue(): void {
    $manager = $this->managerWithLinks(['x-twitter' => 'https://x.com/yale']);
    $this->assertSame('https://x.com/yale', $manager->getSocialLinkUrl('x-twitter'));
  }

  /**
   * @covers ::getSocialLinkUrl
   */
  public function testGetSocialLinkUrlReturnsNullWhenUnset(): void {
    $manager = $this->managerWithLinks([]);
    $this->assertNull($manager->getSocialLinkUrl('youtube'));
  }

}
