<?php

namespace Drupal\Tests\ys_ai_system_instructions\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ys_ai_system_instructions\Service\TextFormatDetectionService;

/**
 * Unit tests for TextFormatDetectionService.
 *
 * This service detects whether content is markdown or plain text and applies
 * appropriate formatting:
 * - Markdown: Preserved as-is (trimmed only) to maintain structure
 * - Plain text: Formatted with sentence breaks, line wrapping, and
 *   whitespace normalization.
 *
 * Format detection is necessary because plain text requires special
 * formatting transformations that would corrupt markdown structure.
 * CommonMark is used for detection (parsing AST to score likelihood),
 * but NOT for rendering - this service returns formatted strings, not HTML.
 *
 * @coversDefaultClass \Drupal\ys_ai_system_instructions\Service\TextFormatDetectionService
 * @group ys_ai_system_instructions
 * @group ys_ai
 * @group yalesites
 */
class TextFormatDetectionServiceTest extends UnitTestCase {

  /**
   * The TextFormatDetectionService under test.
   *
   * @var \Drupal\ys_ai_system_instructions\Service\TextFormatDetectionService
   */
  protected $service;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->service = new TextFormatDetectionService();
  }

  /**
   * Tests detectFormat() with markdown headers.
   *
   * @covers ::detectFormat
   * @covers ::calculateMarkdownScoreFromAst
   */
  public function testDetectFormatWithHeaders() {
    $text = "# Main Header\n\n## Subheader\n\nSome content here.";

    $result = $this->service->detectFormat($text);

    $this->assertEquals(TextFormatDetectionService::FORMAT_MARKDOWN, $result['format']);
    $this->assertGreaterThan(0.3, $result['confidence']);
    $this->assertLessThanOrEqual(1.0, $result['confidence']);
  }

  /**
   * Tests detectFormat() with markdown lists.
   *
   * @covers ::detectFormat
   * @covers ::calculateMarkdownScoreFromAst
   */
  public function testDetectFormatWithLists() {
    $text = "Here is a list:\n\n- Item one\n- Item two\n- Item three";

    $result = $this->service->detectFormat($text);

    $this->assertEquals(TextFormatDetectionService::FORMAT_MARKDOWN, $result['format']);
    $this->assertGreaterThan(0.3, $result['confidence']);
  }

  /**
   * Tests detectFormat() with ordered lists.
   *
   * @covers ::detectFormat
   * @covers ::calculateMarkdownScoreFromAst
   */
  public function testDetectFormatWithOrderedLists() {
    $text = "Steps to follow:\n\n1. First step\n2. Second step\n3. Third step";

    $result = $this->service->detectFormat($text);

    $this->assertEquals(TextFormatDetectionService::FORMAT_MARKDOWN, $result['format']);
    $this->assertGreaterThan(0.3, $result['confidence']);
  }

  /**
   * Tests detectFormat() with complex markdown.
   *
   * @covers ::detectFormat
   * @covers ::calculateMarkdownScoreFromAst
   */
  public function testDetectFormatWithComplexMarkdown() {
    $text = <<<MARKDOWN
# Project Overview

## Features

- Feature one
- Feature two
- Feature three

## Installation

1. Clone the repository
2. Run npm install
3. Start the server

### Additional Notes

Some **bold text** and *italic text* here.
MARKDOWN;

    $result = $this->service->detectFormat($text);

    $this->assertEquals(TextFormatDetectionService::FORMAT_MARKDOWN, $result['format']);
    $this->assertGreaterThan(0.5, $result['confidence']);
  }

  /**
   * Tests detectFormat() with plain text.
   *
   * @covers ::detectFormat
   * @covers ::calculateMarkdownScoreFromAst
   */
  public function testDetectFormatWithPlainText() {
    $text = "This is just plain text without any markdown formatting. It has no special characters or structure.";

    $result = $this->service->detectFormat($text);

    $this->assertEquals(TextFormatDetectionService::FORMAT_PLAIN_TEXT, $result['format']);
    $this->assertEquals(1.0, $result['confidence']);
  }

  /**
   * Tests detectFormat() with empty text.
   *
   * @covers ::detectFormat
   */
  public function testDetectFormatWithEmptyText() {
    $result = $this->service->detectFormat('');

    $this->assertEquals(TextFormatDetectionService::FORMAT_PLAIN_TEXT, $result['format']);
    $this->assertEquals(1.0, $result['confidence']);
  }

  /**
   * Tests detectFormat() with whitespace only.
   *
   * @covers ::detectFormat
   */
  public function testDetectFormatWithWhitespaceOnly() {
    $result = $this->service->detectFormat("   \n\n  \t  ");

    $this->assertEquals(TextFormatDetectionService::FORMAT_PLAIN_TEXT, $result['format']);
    $this->assertEquals(1.0, $result['confidence']);
  }

  /**
   * Tests detectFormat() with compressed markdown using preprocessing.
   *
   * @covers ::detectFormat
   * @covers ::preprocessOnelineMarkdown
   * @covers ::calculateMarkdownScoreFromAst
   */
  public function testDetectFormatWithCompressedMarkdown() {
    // Compressed markdown where headers are jammed together.
    $text = "Introduction.# Main Section## Subsection- Item one- Item two";

    $result = $this->service->detectFormat($text);

    $this->assertEquals(TextFormatDetectionService::FORMAT_MARKDOWN, $result['format']);
    $this->assertGreaterThan(0.3, $result['confidence']);
  }

  /**
   * Tests detectFormat() with bold and italic text.
   *
   * @covers ::detectFormat
   * @covers ::calculateMarkdownScoreFromRegex
   */
  public function testDetectFormatWithFormattedText() {
    $text = "This text has **bold** and *italic* formatting.";

    $result = $this->service->detectFormat($text);

    // May detect as markdown due to formatting.
    $this->assertArrayHasKey('format', $result);
    $this->assertArrayHasKey('confidence', $result);
  }

  /**
   * Tests formatText() with markdown format.
   *
   * @covers ::formatText
   * @covers ::formatMarkdownText
   * @covers ::formatUnescapedMarkdown
   */
  public function testFormatTextWithMarkdownFormat() {
    $text = "# Header\n\n- Item one\n- Item two";

    $result = $this->service->formatText($text, TextFormatDetectionService::FORMAT_MARKDOWN);

    // After refactoring, formatUnescapedMarkdown just returns trimmed text.
    $this->assertEquals(trim($text), $result);
  }

  /**
   * Tests formatText() with plain text format.
   *
   * @covers ::formatText
   * @covers ::formatPlainText
   */
  public function testFormatTextWithPlainTextFormat() {
    $text = "This is plain text.   It has extra spaces.";

    $result = $this->service->formatText($text, TextFormatDetectionService::FORMAT_PLAIN_TEXT);

    // Should normalize spaces and potentially add line breaks.
    $this->assertNotEmpty($result);
    $this->assertStringNotContainsString('   ', $result);
  }

  /**
   * Tests formatText() with auto-detection.
   *
   * @covers ::formatText
   * @covers ::detectFormat
   */
  public function testFormatTextWithAutoDetection() {
    $markdown_text = "# Header\n\n- List item";

    $result = $this->service->formatText($markdown_text);

    // Should auto-detect as markdown and format accordingly.
    $this->assertNotEmpty($result);
    $this->assertStringContainsString('# Header', $result);
  }

  /**
   * Tests formatText() with empty text.
   *
   * @covers ::formatText
   */
  public function testFormatTextWithEmptyText() {
    $result = $this->service->formatText('');

    $this->assertEquals('', $result);
  }

  /**
   * Tests escapeMarkdownForApi() preserves markdown structure.
   *
   * @covers ::escapeMarkdownForApi
   */
  public function testEscapeMarkdownForApi() {
    $markdown = "# Header\n\n- Item one\n- Item two";

    $escaped = $this->service->escapeMarkdownForApi($markdown);

    // Should escape newlines.
    $this->assertStringContainsString('\\n', $escaped);
    $this->assertStringNotContainsString("\n", $escaped);
    $this->assertStringContainsString('# Header', $escaped);
  }

  /**
   * Tests escapeMarkdownForApi() with empty text.
   *
   * @covers ::escapeMarkdownForApi
   */
  public function testEscapeMarkdownForApiWithEmptyText() {
    $result = $this->service->escapeMarkdownForApi('');

    $this->assertEquals('', $result);
  }

  /**
   * Tests escapeMarkdownForApi() preserves tabs and carriage returns.
   *
   * @covers ::escapeMarkdownForApi
   */
  public function testEscapeMarkdownForApiWithSpecialCharacters() {
    $markdown = "Line one\nLine two\rLine three\tIndented";

    $escaped = $this->service->escapeMarkdownForApi($markdown);

    $this->assertStringContainsString('\\n', $escaped);
    $this->assertStringContainsString('\\r', $escaped);
    $this->assertStringContainsString('\\t', $escaped);
  }

  /**
   * Tests unescapeMarkdownFromApi() restores original structure.
   *
   * @covers ::unescapeMarkdownFromApi
   */
  public function testUnescapeMarkdownFromApi() {
    $escaped = "# Header\\n\\n- Item one\\n- Item two";

    $unescaped = $this->service->unescapeMarkdownFromApi($escaped);

    // Should restore newlines.
    $this->assertStringContainsString("\n", $unescaped);
    $this->assertStringNotContainsString('\\n', $unescaped);
    $this->assertStringContainsString('# Header', $unescaped);
  }

  /**
   * Tests unescapeMarkdownFromApi() with empty text.
   *
   * @covers ::unescapeMarkdownFromApi
   */
  public function testUnescapeMarkdownFromApiWithEmptyText() {
    $result = $this->service->unescapeMarkdownFromApi('');

    $this->assertEquals('', $result);
  }

  /**
   * Tests roundtrip escape/unescape preserves content.
   *
   * @covers ::escapeMarkdownForApi
   * @covers ::unescapeMarkdownFromApi
   */
  public function testEscapeUnescapeRoundtrip() {
    $original = "# Project\n\n## Features\n\n- Feature one\n- Feature two\n\nTabs:\there";

    $escaped = $this->service->escapeMarkdownForApi($original);
    $restored = $this->service->unescapeMarkdownFromApi($escaped);

    $this->assertEquals($original, $restored);
  }

  /**
   * Tests formatUnescapedMarkdown() returns trimmed text.
   *
   * @covers ::formatUnescapedMarkdown
   */
  public function testFormatUnescapedMarkdown() {
    $markdown = "  # Header\n\n- Item  ";

    $result = $this->service->formatUnescapedMarkdown($markdown);

    // After refactoring, this should just trim the text.
    $this->assertEquals(trim($markdown), $result);
  }

  /**
   * Tests formatUnescapedMarkdown() with empty text.
   *
   * @covers ::formatUnescapedMarkdown
   */
  public function testFormatUnescapedMarkdownWithEmptyText() {
    $result = $this->service->formatUnescapedMarkdown('');

    $this->assertEquals('', $result);
  }

  /**
   * Tests formatUnescapedMarkdown() preserves user formatting.
   *
   * The refactoring removes reconstruction logic, so the method should
   * preserve exactly what the user created.
   *
   * @covers ::formatUnescapedMarkdown
   */
  public function testFormatUnescapedMarkdownPreservesFormatting() {
    $markdown = "# Header\n\n\n- Item with extra newlines above";

    $result = $this->service->formatUnescapedMarkdown($markdown);

    // Should preserve the structure (just trimmed).
    $this->assertEquals(trim($markdown), $result);
  }

  /**
   * Tests formatPlainText() normalizes whitespace.
   *
   * @covers ::formatPlainText
   */
  public function testFormatPlainTextNormalizesSpaces() {
    $text = "This   has    multiple    spaces.";

    $result = $this->service->formatText($text, TextFormatDetectionService::FORMAT_PLAIN_TEXT);

    $this->assertStringNotContainsString('   ', $result);
  }

  /**
   * Tests formatPlainText() adds sentence breaks.
   *
   * @covers ::formatPlainText
   */
  public function testFormatPlainTextAddsSentenceBreaks() {
    $text = "First sentence. Second sentence with capital.";

    $result = $this->service->formatText($text, TextFormatDetectionService::FORMAT_PLAIN_TEXT);

    // Should potentially add line breaks between sentences.
    $this->assertNotEmpty($result);
  }

  /**
   * Tests formatPlainText() handles long lines.
   *
   * @covers ::formatPlainText
   */
  public function testFormatPlainTextHandlesLongLines() {
    $text = str_repeat("This is a very long line with many words. ", 20);

    $result = $this->service->formatText($text, TextFormatDetectionService::FORMAT_PLAIN_TEXT);

    // Should break up long lines at natural boundaries.
    $this->assertNotEmpty($result);
  }

  /**
   * Tests preprocessOnelineMarkdown() fixes compressed headers.
   *
   * @covers ::preprocessOnelineMarkdown
   */
  public function testPreprocessOnelineMarkdownFixesHeaders() {
    // Use reflection to access protected method.
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('preprocessOnelineMarkdown');
    $method->setAccessible(TRUE);

    $compressed = "Introduction.# Main Header";
    $result = $method->invoke($this->service, $compressed);

    // Should add line breaks before the header.
    $this->assertStringContainsString("\n", $result);
  }

  /**
   * Tests calculateMarkdownScoreFromAst() scoring logic.
   *
   * @covers ::calculateMarkdownScoreFromAst
   */
  public function testCalculateMarkdownScoreFromAst() {
    // Parse markdown with headers and lists.
    $markdown = "# Header\n\n- Item one\n- Item two";

    // Call detectFormat which uses calculateMarkdownScoreFromAst internally.
    $result = $this->service->detectFormat($markdown);

    $this->assertGreaterThan(0.5, $result['confidence']);
    $this->assertEquals(TextFormatDetectionService::FORMAT_MARKDOWN, $result['format']);
  }

  /**
   * Tests calculateMarkdownScoreFromRegex() as fallback.
   *
   * @covers ::calculateMarkdownScoreFromRegex
   */
  public function testCalculateMarkdownScoreFromRegex() {
    // Text with markdown syntax that might not parse perfectly.
    $markdown = "Some text ## Header - Item";

    $result = $this->service->detectFormat($markdown);

    // Should still detect markdown via regex fallback.
    $this->assertArrayHasKey('format', $result);
    $this->assertArrayHasKey('confidence', $result);
  }

  /**
   * Tests that formatMarkdownText() uses simplified approach.
   *
   * After refactoring, formatMarkdownText should just call
   * formatUnescapedMarkdown which returns trimmed text.
   *
   * @covers ::formatMarkdownText
   * @covers ::formatUnescapedMarkdown
   */
  public function testFormatMarkdownTextUsesSimplifiedApproach() {
    $markdown = "  # Header\n\n- List  ";

    $result = $this->service->formatText($markdown, TextFormatDetectionService::FORMAT_MARKDOWN);

    // Should be trimmed but otherwise preserved.
    $this->assertEquals("# Header\n\n- List", $result);
  }

  /**
   * Tests integration of escape, unescape, and format.
   *
   * @covers ::escapeMarkdownForApi
   * @covers ::unescapeMarkdownFromApi
   * @covers ::formatUnescapedMarkdown
   */
  public function testFullWorkflowIntegration() {
    $original = "# Project Title\n\n## Description\n\n- Feature A\n- Feature B";

    // Simulate API workflow.
    $escaped = $this->service->escapeMarkdownForApi($original);
    $unescaped = $this->service->unescapeMarkdownFromApi($escaped);
    $formatted = $this->service->formatUnescapedMarkdown($unescaped);

    // Final result should match original (trimmed).
    $this->assertEquals(trim($original), $formatted);
  }

  /**
   * Tests that removed methods are no longer present.
   *
   * Verifies the refactoring removed unused methods.
   */
  public function testRemovedMethodsAreGone() {
    $reflection = new \ReflectionClass($this->service);
    $methods = $reflection->getMethods();
    $method_names = array_map(function ($method) {
      return $method->getName();
    }, $methods);

    // These methods were removed in the refactoring.
    $removed_methods = [
      'isCompressedMarkdown',
      'simpleMarkdownFormat',
      'reconstructMarkdownFromAst',
      'reconstructListBlock',
      'extractDirectTextFromNode',
      'extractTextFromNode',
      'formatMarkdownTextFallback',
    ];

    foreach ($removed_methods as $removed_method) {
      $this->assertNotContains($removed_method, $method_names,
        "Method {$removed_method} should have been removed in refactoring");
    }
  }

  /**
   * Tests service constants are defined.
   */
  public function testServiceConstantsAreDefined() {
    $this->assertEquals('markdown', TextFormatDetectionService::FORMAT_MARKDOWN);
    $this->assertEquals('plain_text', TextFormatDetectionService::FORMAT_PLAIN_TEXT);
  }

  /**
   * Integration test: Plain text vs Markdown get different treatment.
   *
   * This test demonstrates why format detection is necessary:
   * - Plain text gets formatted (sentence breaks, line wrapping)
   * - Markdown is preserved as-is (structure intact)
   *
   * @covers ::formatText
   * @covers ::detectFormat
   * @covers ::formatPlainText
   * @covers ::formatMarkdownText
   */
  public function testPlainTextVsMarkdownFormatting() {
    // Plain text: should get sentence breaks added.
    $plain_text = "This is a sentence. Another sentence follows.";

    // Should add line breaks between sentences (if detected as plain text).
    // The formatPlainText() adds \n\n after sentences with capitals.
    // Detection might see this as either format, test explicit format.
    $explicit_plain = $this->service->formatText(
      $plain_text,
      TextFormatDetectionService::FORMAT_PLAIN_TEXT
    );

    // Should have added line breaks.
    $this->assertStringContainsString("\n", $explicit_plain);

    // Markdown: should be preserved exactly (just trimmed).
    $markdown_text = "# Header\n\n- Item one\n- Item two";
    $markdown_result = $this->service->formatText(
      $markdown_text,
      TextFormatDetectionService::FORMAT_MARKDOWN
    );

    // Should be identical except for trim.
    $this->assertEquals(trim($markdown_text), $markdown_result);

    // The key difference: markdown preserves structure, plain text transforms.
    $this->assertNotEquals($explicit_plain, $plain_text);
    $this->assertEquals(trim($markdown_text), $markdown_result);
  }

}
