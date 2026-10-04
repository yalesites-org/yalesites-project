<?php

namespace Drupal\Tests\ys_markdown\Unit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilderInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Url;
use Drupal\Tests\UnitTestCase;
use Drupal\node\NodeInterface;
use Drupal\ys_markdown\MarkdownBuilder;
use Drupal\ys_markdown\PublicHtmlFilter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests building the Markdown document.
 *
 * @group ys_markdown
 * @coversDefaultClass \Drupal\ys_markdown\MarkdownBuilder
 */
class MarkdownBuilderTest extends UnitTestCase {

  /**
   * Builds the document for a node whose default display renders $html.
   */
  protected function build(string $html): string {
    $viewBuilder = $this->createMock(EntityViewBuilderInterface::class);
    $viewBuilder->method('view')->willReturn(['#markup' => $html]);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getViewBuilder')->willReturn($viewBuilder);
    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('renderInIsolation')->willReturn($html);
    $requestStack = new RequestStack();
    $requestStack->push(Request::create('/page.md'));

    $url = $this->createMock(Url::class);
    $url->method('toString')->willReturn((new GeneratedUrl())->setGeneratedUrl('https://example.com/page'));
    $node = $this->createMock(NodeInterface::class);
    $node->method('toUrl')->willReturn($url);
    $node->method('label')->willReturn('Page Title');
    $node->method('getChangedTime')->willReturn(gmmktime(0, 0, 0, 1, 2, 2025));

    $builder = new MarkdownBuilder(
      $entityTypeManager,
      $renderer,
      $this->createMock(AccountSwitcherInterface::class),
      $requestStack,
      new PublicHtmlFilter(),
    );
    return $builder->build($node)['markdown'];
  }

  /**
   * A page whose only content is its title yields just the header block.
   *
   * Twig debug comments survive around the removed title heading, and the
   * HTML-to-Markdown converter rejects comment-only input.
   *
   * @covers ::build
   * @dataProvider titleOnlyProvider
   */
  public function testTitleOnlyPage(string $html): void {
    $this->assertSame(
      "# Page Title\n\nSource: https://example.com/page\nLast updated: 2025-01-02\n",
      $this->build($html),
    );
  }

  /**
   * Rendered HTML that holds nothing but the title.
   */
  public static function titleOnlyProvider(): array {
    return [
      'empty' => [''],
      'title heading only' => ['<article><h1>Page Title</h1></article>'],
      'twig debug comments' => ["<!-- THEME DEBUG -->\n<article>\n  <h1> Page Title </h1>\n</article>\n<!-- END OUTPUT -->"],
    ];
  }

  /**
   * Body content follows the header after one blank line.
   *
   * @covers ::build
   */
  public function testBodyAfterHeader(): void {
    $this->assertSame(
      "# Page Title\n\nSource: https://example.com/page\nLast updated: 2025-01-02\n\nHello world.\n",
      $this->build('<!-- x --><h1>Page Title</h1><p>Hello world.</p>'),
    );
  }

  /**
   * Entities are decoded in text, link text and table cells, except < and >.
   *
   * @covers ::build
   */
  public function testEntitiesDecoded(): void {
    $markdown = $this->build('<p>Arts &amp; &quot;Humanities&quot; &#039;s &lt;b&gt;</p>'
      . '<p><a href="https://example.com/tips">Tips &amp; Good Practices</a></p>'
      . '<table><tr><th>Name</th></tr><tr><td>Profilin &amp; actophorin</td></tr></table>');
    $this->assertStringContainsString('Arts & "Humanities" \'s &lt;b&gt;', $markdown);
    $this->assertStringContainsString('[Tips & Good Practices](https://example.com/tips)', $markdown);
    $this->assertStringContainsString('| Profilin & actophorin |', $markdown);
    $this->assertStringNotContainsString('&amp;', $markdown);
    $this->assertStringNotContainsString('&quot;', $markdown);
    $this->assertStringNotContainsString('&#039;', $markdown);
  }

}
