<?php

namespace Drupal\Tests\ys_markdown\Unit;

use Drupal\path_alias\AliasManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ys_markdown\PathProcessor\MarkdownPathProcessor;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the inbound .md path rewrite.
 *
 * @group ys_markdown
 * @coversDefaultClass \Drupal\ys_markdown\PathProcessor\MarkdownPathProcessor
 */
class MarkdownPathProcessorTest extends UnitTestCase {

  /**
   * Builds a processor whose alias manager knows the given aliases.
   */
  protected function processor(array $aliases = []): MarkdownPathProcessor {
    $manager = $this->createMock(AliasManagerInterface::class);
    $manager->method('getPathByAlias')->willReturnCallback(
      fn (string $alias) => $aliases[$alias] ?? $alias,
    );
    return new MarkdownPathProcessor($manager);
  }

  /**
   * Data for the path rewrite.
   */
  public static function pathProvider(): array {
    return [
      'internal path' => ['/node/5.md', '/node/5/md', ['/node/5.md' => '/node/5/md']],
      'single alias' => ['/about.md', '/node/7/md', []],
      'nested alias' => ['/a/b/c.md', '/node/9/md', []],
      'alias to non-node' => ['/search.md', '/search.md', []],
      'not md' => ['/about', '/about', []],
      'bare .md' => ['/.md', '/.md', []],
      'node-looking non-node' => ['/node/5/edit.md', '/node/5/edit.md', []],
    ];
  }

  /**
   * @covers ::processInbound
   * @dataProvider pathProvider
   */
  public function testProcessInbound(string $path, string $expected): void {
    $processor = $this->processor([
      '/about' => '/node/7',
      '/a/b/c' => '/node/9',
      '/search' => '/search/view',
    ]);
    $request = Request::create($path);
    $this->assertSame($expected, $processor->processInbound($path, $request));
  }

  /**
   * The stripped original path is recorded for the CAS check.
   *
   * @covers ::processInbound
   */
  public function testStoresOriginalPathOnRequest(): void {
    $processor = $this->processor(['/a/b/c' => '/node/9']);
    $request = Request::create('/a/b/c.md');
    $processor->processInbound('/a/b/c.md', $request);
    $this->assertSame('/a/b/c', $request->attributes->get('_ys_markdown_path'));

    $request = Request::create('/node/5.md');
    $processor->processInbound('/node/5.md', $request);
    $this->assertSame('/node/5', $request->attributes->get('_ys_markdown_path'));
  }

  /**
   * Untouched paths leave no attribute behind.
   *
   * @covers ::processInbound
   */
  public function testNoAttributeWhenNotRewritten(): void {
    $processor = $this->processor();
    $request = Request::create('/about');
    $processor->processInbound('/about', $request);
    $this->assertFalse($request->attributes->has('_ys_markdown_path'));
  }

}
