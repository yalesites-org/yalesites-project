<?php

namespace Drupal\Tests\ys_embed\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_embed\Plugin\EmbedSource\GitHubApplet;

/**
 * @coversDefaultClass \Drupal\ys_embed\Plugin\EmbedSource\GitHubApplet
 *
 * @group yalesites
 */
class GitHubAppletTest extends UnitTestCase {

  /**
   * The GitHubApplet plugin instance.
   *
   * @var \Drupal\ys_embed\Plugin\EmbedSource\GitHubApplet
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
    $this->plugin = new GitHubApplet([], 'github_applet', [], $configFactory);
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsWithNoQueryString(): void {
    $params = $this->plugin->getParams(
      'https://yalesites-org.github.io/storybook-docs-embed/assets'
    );
    $this->assertSame('storybook-docs-embed', $params['repo_name']);
    $this->assertSame('assets', $params['app_directory']);
    $this->assertSame([], $params['data_attrs']);
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsWithTrailingSlash(): void {
    $params = $this->plugin->getParams(
      'https://yalesites-org.github.io/storybook-docs-embed/assets/'
    );
    $this->assertSame('storybook-docs-embed', $params['repo_name']);
    $this->assertSame('assets', $params['app_directory']);
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsExtractsQueryParams(): void {
    $params = $this->plugin->getParams(
      'https://yalesites-org.github.io/storybook-docs-embed/assets?story=introduction-welcome--docs&height=1200'
    );
    $this->assertSame('storybook-docs-embed', $params['repo_name']);
    $this->assertSame('assets', $params['app_directory']);
    $this->assertSame('introduction-welcome--docs', $params['data_attrs']['story']);
    $this->assertSame('1200', $params['data_attrs']['height']);
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsDecodesUrlEncodedValues(): void {
    $netlify = 'https://deploy-preview-633--dev-component-library-twig.netlify.app';
    $params = $this->plugin->getParams(
      'https://yalesites-org.github.io/storybook-docs-embed/assets?base=' . urlencode($netlify)
    );
    $this->assertSame($netlify, $params['data_attrs']['base']);
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsDecodesSpacesInValues(): void {
    $params = $this->plugin->getParams(
      'https://yalesites-org.github.io/storybook-docs-embed/assets?label=Welcome+to+YaleSites'
    );
    $this->assertSame('Welcome to YaleSites', $params['data_attrs']['label']);
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsIgnoresEmptyValues(): void {
    $params = $this->plugin->getParams(
      'https://yalesites-org.github.io/storybook-docs-embed/assets?story=&height=1200'
    );
    $this->assertArrayNotHasKey('story', $params['data_attrs']);
    $this->assertSame('1200', $params['data_attrs']['height']);
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsAcceptsArbitraryQueryParams(): void {
    $params = $this->plugin->getParams(
      'https://yalesites-org.github.io/storybook-docs-embed/assets?story=intro--docs&custom-param=value123'
    );
    $this->assertSame('intro--docs', $params['data_attrs']['story']);
    $this->assertSame('value123', $params['data_attrs']['custom-param']);
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsSanitizesKeysToSafeCharacters(): void {
    $params = $this->plugin->getParams(
      'https://yalesites-org.github.io/storybook-docs-embed/assets?UPPER=val&under_score=val2'
    );
    $this->assertArrayHasKey('upper', $params['data_attrs']);
    $this->assertArrayNotHasKey('UPPER', $params['data_attrs']);
    // Underscores are stripped; key becomes 'underscore'.
    $this->assertArrayHasKey('underscore', $params['data_attrs']);
  }

  /**
   * @covers ::getParams
   */
  public function testGetParamsBlocksAttributeInjectionViaKeys(): void {
    $params = $this->plugin->getParams(
      'https://yalesites-org.github.io/storybook-docs-embed/assets?x%20onmouseover%3Dalert(1)=y'
    );
    // Space, =, (, ) are all stripped from the key.
    $this->assertArrayNotHasKey('x onmouseover=alert(1)', $params['data_attrs']);
    // The sanitized key would be 'xonmouseoveralert1'.
    $this->assertArrayHasKey('xonmouseoveralert1', $params['data_attrs']);
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidMatchesWithoutQueryString(): void {
    $this->assertTrue(
      GitHubApplet::isValid('https://yalesites-org.github.io/storybook-docs-embed/assets')
    );
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidMatchesWithQueryString(): void {
    $this->assertTrue(
      GitHubApplet::isValid('https://yalesites-org.github.io/storybook-docs-embed/assets?story=intro--docs')
    );
  }

  /**
   * @covers ::isValid
   */
  public function testIsValidRejectsNonGithubPagesUrl(): void {
    $this->assertFalse(
      GitHubApplet::isValid('https://example.com/storybook-docs-embed/assets')
    );
  }

  /**
   * @covers ::build
   */
  public function testBuildRendersDataAttrsInTemplate(): void {
    $params = $this->plugin->getParams(
      'https://yalesites-org.github.io/storybook-docs-embed/assets?story=introduction-welcome--docs&height=1200'
    );
    $params['title'] = 'Test embed';
    $build = $this->plugin->build($params);
    $this->assertStringContainsString('data-{{ key }}="{{ value }}"', $build['#embedSource']['#template']);
    $this->assertSame('introduction-welcome--docs', $build['#embedSource']['#context']['data_attrs']['story']);
    $this->assertSame('1200', $build['#embedSource']['#context']['data_attrs']['height']);
  }

  /**
   * @covers ::build
   */
  public function testBuildOmitsDataAttrsWhenNonePresent(): void {
    $params = $this->plugin->getParams(
      'https://yalesites-org.github.io/storybook-docs-embed/assets'
    );
    $params['title'] = 'Test embed';
    $build = $this->plugin->build($params);
    $this->assertSame([], $build['#embedSource']['#context']['data_attrs']);
  }

  /**
   * @covers ::getParams
   */
  public function testAppDirectoryNotCorruptedByQueryString(): void {
    $params = $this->plugin->getParams(
      'https://yalesites-org.github.io/storybook-docs-embed/assets?story=intro--docs'
    );
    $this->assertSame('assets', $params['app_directory']);
  }

}
