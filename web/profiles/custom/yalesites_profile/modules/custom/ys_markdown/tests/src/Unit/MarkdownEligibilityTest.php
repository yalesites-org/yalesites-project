<?php

namespace Drupal\Tests\ys_markdown\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Path\PathMatcher;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\ys_beacon\Service\BeaconIndexability;
use Drupal\ys_markdown\MarkdownEligibility;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests every eligibility rule for a node's Markdown version.
 *
 * @group ys_markdown
 * @coversDefaultClass \Drupal\ys_markdown\MarkdownEligibility
 */
class MarkdownEligibilityTest extends UnitTestCase {

  /**
   * Builds the service under test.
   *
   * @param array $options
   *   Keys: enabled, indexable, cas (bool), negate, pages, alias.
   */
  protected function eligibility(array $options = []): MarkdownEligibility {
    $options += [
      'enabled' => TRUE,
      'indexable' => TRUE,
      'cas' => FALSE,
      'negate' => FALSE,
      'pages' => '',
      'alias' => '/about',
    ];
    $config = $this->getConfigFactoryStub([
      'ys_core.site' => ['ai_readability.markdown_enabled' => $options['enabled']],
      'cas.settings' => [
        'forced_login.enabled' => $options['cas'],
        'forced_login.paths' => ['negate' => $options['negate'], 'pages' => $options['pages']],
      ],
      'system.site' => ['page.front' => '/node'],
    ]);
    $indexability = $this->createMock(BeaconIndexability::class);
    $indexability->method('isIndexable')->willReturn($options['indexable']);
    $aliases = $this->createMock(AliasManagerInterface::class);
    $aliases->method('getAliasByPath')->willReturn($options['alias']);
    return new MarkdownEligibility(
      $config,
      $indexability,
      $aliases,
      new PathMatcher($config, $this->createMock('Drupal\Core\Routing\RouteMatchInterface')),
    );
  }

  /**
   * Builds a node mock.
   */
  protected function node(string $bundle = 'page', ?bool $filled = NULL, bool $default = TRUE): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(5);
    $node->method('bundle')->willReturn($bundle);
    $node->method('isDefaultRevision')->willReturn($default);
    $node->method('hasField')->willReturn($filled !== NULL);
    $field = $this->createMock(FieldItemListInterface::class);
    $field->method('isEmpty')->willReturn($filled === FALSE);
    $node->method('get')->willReturn($field);
    $node->method('getCacheTags')->willReturn(['node:5']);
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheMaxAge')->willReturn(-1);
    return $node;
  }

  /**
   * @covers ::isEligible
   */
  public function testEligibleByDefault(): void {
    $this->assertTrue($this->eligibility()->isEligible($this->node()));
  }

  /**
   * @covers ::isEligible
   */
  public function testSettingOff(): void {
    $this->assertFalse($this->eligibility(['enabled' => FALSE])->isEligible($this->node()));
  }

  /**
   * @covers ::isEligible
   */
  public function testNotIndexable(): void {
    $this->assertFalse($this->eligibility(['indexable' => FALSE])->isEligible($this->node()));
  }

  /**
   * @covers ::isEligible
   */
  public function testNonDefaultRevision(): void {
    $this->assertFalse($this->eligibility()->isEligible($this->node('page', NULL, FALSE)));
  }

  /**
   * @covers ::isEligible
   */
  public function testExternalSource(): void {
    $e = $this->eligibility();
    // Field present and filled: the page redirects away, so no Markdown.
    $this->assertFalse($e->isEligible($this->node('page', TRUE)));
    // Field present and empty, or absent: fine.
    $this->assertTrue($e->isEligible($this->node('page', FALSE)));
    $this->assertTrue($e->isEligible($this->node('page', NULL)));
    // Resource pages do not redirect, so they stay eligible.
    $this->assertTrue($e->isEligible($this->node('resource', TRUE)));
  }

  /**
   * CAS forced login data.
   */
  public static function casProvider(): array {
    return [
      'disabled ignores paths' => [['cas' => FALSE, 'pages' => '/about'], NULL, TRUE],
      'alias match' => [['cas' => TRUE, 'pages' => '/about'], NULL, FALSE],
      'alias match case-insensitive' => [['cas' => TRUE, 'pages' => "/ABOUT"], NULL, FALSE],
      'wildcard' => [['cas' => TRUE, 'pages' => '/ab*'], NULL, FALSE],
      'internal path match' => [['cas' => TRUE, 'pages' => '/node/5'], NULL, FALSE],
      'request path match' => [['cas' => TRUE, 'pages' => '/typed/*', 'alias' => '/other'], '/typed/page', FALSE],
      'no match' => [['cas' => TRUE, 'pages' => '/private/*'], NULL, TRUE],
      'negate, match is exempt' => [['cas' => TRUE, 'negate' => TRUE, 'pages' => '/about'], NULL, TRUE],
      'negate, no match is forced' => [['cas' => TRUE, 'negate' => TRUE, 'pages' => '/public/*'], NULL, FALSE],
    ];
  }

  /**
   * @covers ::isEligible
   * @dataProvider casProvider
   */
  public function testCasForcedLogin(array $options, ?string $requestPath, bool $expected): void {
    $this->assertSame($expected, $this->eligibility($options)->isEligible($this->node(), $requestPath));
  }

  /**
   * The cacheability names every config and node the answer depends on.
   *
   * @covers ::getCacheability
   */
  public function testCacheability(): void {
    $contexts = $this->createMock('Drupal\Core\Cache\Context\CacheContextsManager');
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
    $cacheability = $this->eligibility()->getCacheability($this->node());
    $tags = $cacheability->getCacheTags();
    $this->assertContains('config:ys_core.site', $tags);
    $this->assertContains('config:cas.settings', $tags);
    $this->assertContains('node:5', $tags);
  }

}
