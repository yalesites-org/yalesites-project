<?php

declare(strict_types=1);

namespace Drupal\Tests\ys_ai_tester\Kernel;

use Drupal\Tests\ys_core\Kernel\YsKernelTestBase;

/**
 * Tests that the AI Tester's templates escape the values handed to them.
 *
 * These three templates took over markup the controller used to build as a
 * string, and with it the escaping: the host and the source filename were
 * escaped by t()'s @-placeholder, and a citation URL by an explicit
 * Html::escape(), and all three are now escaped by Twig instead. The unit
 * tests beside this one pin the other half of that contract — that the values
 * reach the template as plain strings rather than as MarkupInterface, which is
 * the condition under which Twig escapes them at all — but nothing there
 * renders a template, so a later `|raw`, an `{% autoescape false %}`, or a
 * preprocess that marked a value safe would ship stored XSS with the unit
 * suite green.
 *
 * The values are not ours to trust. A host is parsed out of a citation URL,
 * which on a borrowed index came from another site's stored field; a source
 * filename is whatever an operator uploaded.
 *
 * The templates are compiled straight from their files rather than through
 * their theme hooks, so this does not have to install ys_ai_tester and drag in
 * Beacon, Search API, the AI modules and Key. What is compiled is the exact
 * bytes that ship, through Drupal's own Twig environment, which is what the
 * escaping depends on.
 *
 * @group ys_ai_tester
 * @group ys_beacon
 */
class AiTesterTemplateEscapingTest extends YsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * A value that is unmistakably markup if it is ever not escaped.
   */
  protected const HOSTILE = '<script>alert(1)</script>';

  /**
   * Renders one of the module's templates with the given variables.
   *
   * Named renderTemplate() rather than render() because KernelTestBase already
   * declares an incompatible render().
   *
   * @param string $template
   *   The template's filename.
   * @param array $context
   *   The template variables.
   *
   * @return string
   *   The rendered markup.
   */
  protected function renderTemplate(string $template, array $context): string {
    // Four levels up from tests/src/Kernel is the module root. Derived rather
    // than asked of the extension list, which would need the module installed.
    $path = dirname(__DIR__, 3) . '/templates/' . $template;
    $this->assertFileExists($path);

    $build = [
      '#type' => 'inline_template',
      '#template' => file_get_contents($path),
      '#context' => $context,
    ];

    return (string) \Drupal::service('renderer')->renderInIsolation($build);
  }

  /**
   * Every untrusted value the comparison header shows is escaped.
   *
   * @dataProvider provideCompareRunMetaFields
   */
  public function testCompareRunMetaEscapesUntrustedValues(string $field): void {
    $context = [
      'label' => 'Run A',
      'id' => 7,
      'date' => '2026-09-19',
      'file' => 'questions.txt',
      'backend' => 'Beacon',
      'status' => 'complete',
      'host' => 'example.com',
    ];
    $context[$field] = self::HOSTILE;

    $html = $this->renderTemplate('ys-ai-tester-compare-run-meta.html.twig', $context);

    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('&lt;script&gt;', $html);
  }

  /**
   * The fields of the comparison header that carry untrusted values.
   *
   * The host is parsed from a citation URL and the filename is whatever was
   * uploaded; the rest are code-controlled, and are covered anyway because the
   * whole sentence is one translatable string.
   */
  public static function provideCompareRunMetaFields(): array {
    return [
      'host' => ['host'],
      'source filename' => ['file'],
      'assistant label' => ['backend'],
      'status' => ['status'],
    ];
  }

  /**
   * The single-run header escapes the uploaded filename.
   */
  public function testRunSummaryEscapesTheSourceFilename(): void {
    $html = $this->renderTemplate('ys-ai-tester-run-summary.html.twig', [
      'id' => 7,
      'date' => '2026-09-19',
      'file' => self::HOSTILE,
      'backend' => 'Beacon',
      'status' => 'complete',
    ]);

    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('&lt;script&gt;', $html);
  }

  /**
   * Both headers keep the markup that is theirs to emit.
   *
   * Asserted alongside the escaping so a fix for one cannot quietly escape the
   * other: the sentence is meant to carry a <strong> and, in the comparison,
   * line breaks.
   */
  public function testHeadersKeepTheirOwnMarkup(): void {
    $summary = $this->renderTemplate('ys-ai-tester-run-summary.html.twig', [
      'id' => 7,
      'date' => '2026-09-19',
      'file' => 'questions.txt',
      'backend' => 'Beacon',
      'status' => 'complete',
    ]);
    $this->assertStringContainsString('<strong>Run #7</strong>', $summary);
    $this->assertStringNotContainsString('<br>', $summary);

    $meta = $this->renderTemplate('ys-ai-tester-compare-run-meta.html.twig', [
      'label' => 'Run A',
      'id' => 7,
      'date' => '2026-09-19',
      'file' => 'questions.txt',
      'backend' => 'Beacon',
      'status' => 'complete',
      'host' => 'example.com',
    ]);
    $this->assertStringContainsString('<strong>Run A — Run #7</strong>', $meta);
    $this->assertStringContainsString('Host: example.com', $meta);
    $this->assertStringContainsString('<br>', $meta);
  }

  /**
   * A citation's URL is escaped, ampersands included.
   *
   * This one replaced an explicit Html::escape() in PHP. A citation URL
   * routinely carries query separators, so the ampersand case is the one that
   * would go wrong quietly rather than visibly.
   */
  public function testCitationEscapesTheUrl(): void {
    $html = $this->renderTemplate('ys-ai-tester-citation.html.twig', [
      'link' => ['#plain_text' => 'A source'],
      'flag' => 'cited',
      'only_here' => FALSE,
      'url' => 'https://example.com/a?b=1&c=2"><script>alert(1)</script>',
    ]);

    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringContainsString('&amp;c=2', $html);
  }

  /**
   * A citation renders as one line, with nothing between its parts.
   *
   * The template's whitespace control is load-bearing: item_list puts this
   * straight inside an <li>, so a newline left between the flag and the badge
   * becomes a rendered space in the middle of the line. Asserted as an exact
   * string because that is the only way a stray space shows up as a failure.
   */
  public function testCitationRendersAsOneUnbrokenLine(): void {
    $html = $this->renderTemplate('ys-ai-tester-citation.html.twig', [
      'link' => ['#plain_text' => 'A source'],
      'flag' => 'cited',
      'only_here' => TRUE,
      'url' => 'https://example.com/a',
    ]);

    $this->assertSame(
      'A source — <em>cited</em>'
      . '<span class="ys-compare-badge ys-compare-badge--only_here">only in this run</span>'
      . '<br><small>https://example.com/a</small>',
      $html
    );
  }

  /**
   * A citation with no URL and no badge renders neither.
   */
  public function testCitationOmitsTheBadgeAndUrlWhenItHasNeither(): void {
    $html = $this->renderTemplate('ys-ai-tester-citation.html.twig', [
      'link' => ['#plain_text' => 'A source'],
      'flag' => 'retrieved, not cited',
      'only_here' => FALSE,
      'url' => '',
    ]);

    $this->assertSame('A source — <em>retrieved, not cited</em>', $html);
  }

}
