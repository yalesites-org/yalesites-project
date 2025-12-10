<?php

namespace Drupal\ys_ai_system_instructions\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Service for detecting and formatting text content using CommonMark parser.
 */
class TextFormatDetectionService {

  /**
   * Format types.
   */
  const FORMAT_MARKDOWN = 'markdown';
  const FORMAT_PLAIN_TEXT = 'plain_text';

  /**
   * The CommonMark parser.
   *
   * @var \League\CommonMark\Parser\MarkdownParser
   */
  protected $parser;

  /**
   * The CommonMark environment.
   *
   * @var \League\CommonMark\Environment\Environment
   */
  protected $environment;

  /**
   * Constructor.
   */
  public function __construct() {
    // Create CommonMark environment with core extensions.
    $this->environment = new Environment();
    $this->environment->addExtension(new CommonMarkCoreExtension());

    $this->parser = new MarkdownParser($this->environment);
  }

  /**
   * Detect the format of the given text using CommonMark parsing.
   *
   * @param string $text
   *   The text to analyze.
   *
   * @return array
   *   Array with 'format' (string) and 'confidence' (float 0-1).
   */
  public function detectFormat(string $text): array {
    $text = trim($text);

    if (empty($text)) {
      return [
        'format' => self::FORMAT_PLAIN_TEXT,
        'confidence' => 1.0,
      ];
    }

    // First try parsing without preprocessing.
    try {
      $document = $this->parser->parse($text);
      $markdown_score = $this->calculateMarkdownScoreFromAst($document);

      // If we get a good score, use it.
      if ($markdown_score > 0.3) {
        return [
          'format' => self::FORMAT_MARKDOWN,
          'confidence' => $markdown_score,
        ];
      }
    }
    catch (\Exception $e) {
      // Continue to fallback methods.
    }

    // If that didn't work well, try with minimal preprocessing.
    try {
      $preprocessed_text = $this->preprocessOnelineMarkdown($text);
      $document = $this->parser->parse($preprocessed_text);
      $markdown_score = $this->calculateMarkdownScoreFromAst($document);

      if ($markdown_score > 0.3) {
        return [
          'format' => self::FORMAT_MARKDOWN,
          'confidence' => $markdown_score,
        ];
      }
    }
    catch (\Exception $e) {
      // Continue to regex fallback.
    }

    // Final fallback to regex detection.
    $markdown_score = $this->calculateMarkdownScoreFromRegex($text);

    if ($markdown_score > 0.3) {
      return [
        'format' => self::FORMAT_MARKDOWN,
        'confidence' => $markdown_score,
      ];
    }

    return [
      'format' => self::FORMAT_PLAIN_TEXT,
      'confidence' => 1.0,
    ];
  }

  /**
   * Preprocess text to handle oneliner markdown issues.
   *
   * @param string $text
   *   The text to preprocess.
   *
   * @return string
   *   Preprocessed text with better line breaks for markdown parsing.
   */
  protected function preprocessOnelineMarkdown(string $text): string {
    // Very conservative preprocessing - only fix the most obvious issues.
    // Only add breaks before headers when they're clearly separate sections
    // Pattern: "word.# Header" or "word.## Header" - clearly a new section.
    $text = preg_replace('/([.!?])\s*(#{1,6}\s+[A-Z])/', "$1\n\n$2", $text);

    // Handle headers jammed together with no space
    // Like "word.#Header" or "word.##Header".
    $text = preg_replace('/(\w\.)(#{1,6}[A-Z])/', "$1\n\n$2", $text);

    // Only break for list items that are clearly at the start of new sections
    // Conservative - only after punctuation followed by clear list markers.
    $text = preg_replace('/([.!?])\s*(\n?)(-\s+[A-Z])/', "$1\n$3", $text);

    return $text;
  }

  /**
   * Calculate markdown score from CommonMark AST.
   *
   * @param \League\CommonMark\Node\Block\Document $document
   *   The parsed document.
   *
   * @return float
   *   Score from 0 (definitely not markdown) to 1 (definitely markdown).
   */
  protected function calculateMarkdownScoreFromAst(Document $document): float {
    $score = 0.0;
    $total_nodes = 0;
    $markdown_nodes = 0;

    // Walk through the AST and count markdown-specific elements.
    $walker = $document->walker();

    while ($event = $walker->next()) {
      $node = $event->getNode();
      $total_nodes++;

      if ($node instanceof Heading) {
        $markdown_nodes++;
        // Headers are strong indicators.
        $score += 0.4;
      }
      elseif ($node instanceof ListBlock) {
        $markdown_nodes++;
        // Lists are good indicators.
        $score += 0.3;
      }
      elseif ($node instanceof ListItem) {
        // List items add to the score.
        $score += 0.1;
      }
      // Note: Paragraphs aren't counted as markdown-specific.
    }

    // If we have a good ratio of markdown nodes, boost the score.
    if ($total_nodes > 0) {
      $markdown_ratio = $markdown_nodes / $total_nodes;
      $score += $markdown_ratio * 0.3;
    }

    return min($score, 1.0);
  }

  /**
   * Fallback regex-based markdown detection.
   *
   * @param string $text
   *   The text to analyze.
   *
   * @return float
   *   Score from 0 (definitely not markdown) to 1 (definitely markdown).
   */
  protected function calculateMarkdownScoreFromRegex(string $text): float {
    $score = 0.0;

    // Check for markdown headers.
    $header_matches = preg_match_all('/#{1,6}\s+/', $text);
    if ($header_matches) {
      $score += min($header_matches * 0.3, 0.6);
    }

    // Check for markdown lists.
    $list_matches = preg_match_all('/\s*[-*+]\s+/', $text);
    if ($list_matches) {
      $score += min($list_matches * 0.1, 0.4);
    }

    // Check for numbered lists.
    $numbered_list_matches = preg_match_all('/\s*\d+\.\s+/', $text);
    if ($numbered_list_matches) {
      $score += min($numbered_list_matches * 0.1, 0.3);
    }

    // Check for bold text.
    $bold_matches = preg_match_all('/\*\*[^*]+\*\*/', $text);
    if ($bold_matches) {
      $score += min($bold_matches * 0.05, 0.2);
    }

    // Check for italic text.
    $italic_matches = preg_match_all('/(?<!\*)\*[^*]+\*(?!\*)/', $text);
    if ($italic_matches) {
      $score += min($italic_matches * 0.05, 0.2);
    }

    return min($score, 1.0);
  }

  /**
   * Format text based on its detected format.
   *
   * @param string $text
   *   The text to format.
   * @param string|null $format
   *   Optional format override. If null, format will be detected.
   *
   * @return string
   *   The formatted text.
   */
  public function formatText(string $text, ?string $format = NULL): string {
    $text = trim($text);

    if (empty($text)) {
      return '';
    }

    // Detect format if not provided.
    if ($format === NULL) {
      $detection = $this->detectFormat($text);
      $format = $detection['format'];
    }

    if ($format === self::FORMAT_MARKDOWN) {
      return $this->formatMarkdownText($text);
    }

    return $this->formatPlainText($text);
  }

  /**
   * Format text as markdown using CommonMark parsing and reconstruction.
   *
   * @param string $text
   *   The markdown text to format.
   *
   * @return string
   *   The formatted markdown text with proper spacing and structure.
   */
  protected function formatMarkdownText(string $text): string {
    $text = trim($text);

    if (empty($text)) {
      return '';
    }

    // Always use CommonMark parser - let it handle all markdown.
    return $this->formatUnescapedMarkdown($text);
  }

  /**
   * Format text assuming it's plain text.
   *
   * @param string $text
   *   The plain text to format.
   *
   * @return string
   *   The formatted plain text with improved readability.
   */
  protected function formatPlainText(string $text): string {
    $text = trim($text);

    if (empty($text)) {
      return '';
    }

    // Replace multiple spaces with single spaces.
    $text = preg_replace('/[ \t]+/', ' ', $text);

    // Add line breaks after sentences followed by capital letters
    // This helps break up run-on sentences that might be compressed.
    $text = preg_replace('/([.!?])\s+([A-Z][a-z])/', "$1\n\n$2", $text);

    // Add line breaks after colons when followed by capital letters.
    $text = preg_replace('/([:])\s+([A-Z][a-z])/', "$1\n$2", $text);

    // Break up very long lines (over 120 characters) at natural break points.
    $lines = explode("\n", $text);
    $formatted_lines = [];

    foreach ($lines as $line) {
      $line = trim($line);

      if (strlen($line) > 120) {
        // Try to break at sentence boundaries first.
        $sentences = preg_split('/([.!?])\s+/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        $current_line = '';

        for ($i = 0; $i < count($sentences); $i += 2) {
          $sentence = $sentences[$i] ?? '';
          $delimiter = $sentences[$i + 1] ?? '';

          $potential_line = $current_line . $sentence . $delimiter;

          if (strlen($potential_line) > 120 && !empty($current_line)) {
            $formatted_lines[] = trim($current_line);
            $current_line = $sentence . $delimiter;
          }
          else {
            $current_line = $potential_line;
          }
        }

        if (!empty($current_line)) {
          $formatted_lines[] = trim($current_line);
        }
      }
      else {
        if (!empty($line)) {
          $formatted_lines[] = $line;
        }
      }
    }

    $text = implode("\n", $formatted_lines);

    // Clean up excessive line breaks (more than 2)
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    // Ensure proper spacing around sections.
    $text = preg_replace('/\n\n+/', "\n\n", $text);

    return trim($text);
  }

  /**
   * Escape markdown structure for safe API transmission.
   *
   * This preserves the original markdown formatting by escaping newlines
   * and preserving indentation so it can be reconstructed perfectly.
   *
   * @param string $markdown
   *   The markdown text to escape.
   *
   * @return string
   *   The escaped markdown suitable for API transmission.
   */
  public function escapeMarkdownForApi(string $markdown): string {
    $markdown = trim($markdown);

    if (empty($markdown)) {
      return '';
    }

    // Escape newlines to preserve line structure.
    $escaped = str_replace("\n", "\\n", $markdown);

    // Escape carriage returns if present.
    $escaped = str_replace("\r", "\\r", $escaped);

    // Escape tab characters to preserve indentation.
    $escaped = str_replace("\t", "\\t", $escaped);

    return $escaped;
  }

  /**
   * Unescape markdown structure after API retrieval.
   *
   * This reconstructs the original markdown formatting by unescaping
   * newlines and restoring proper structure.
   *
   * @param string $escaped_markdown
   *   The escaped markdown from the API.
   *
   * @return string
   *   The unescaped markdown with proper formatting.
   */
  public function unescapeMarkdownFromApi(string $escaped_markdown): string {
    $escaped_markdown = trim($escaped_markdown);

    if (empty($escaped_markdown)) {
      return '';
    }

    // Unescape newlines to restore line structure.
    $unescaped = str_replace("\\n", "\n", $escaped_markdown);

    // Unescape carriage returns if present.
    $unescaped = str_replace("\\r", "\r", $unescaped);

    // Unescape tab characters to restore indentation.
    $unescaped = str_replace("\\t", "\t", $unescaped);

    return $unescaped;
  }

  /**
   * Format markdown that has been properly unescaped from API.
   *
   * This is a simpler version that works with properly structured markdown
   * rather than trying to reconstruct formatting from compressed text.
   *
   * @param string $markdown
   *   The properly structured markdown text.
   *
   * @return string
   *   The formatted markdown with any minor cleanup applied.
   */
  public function formatUnescapedMarkdown(string $markdown): string {
    // Just return the markdown as-is.
    // Let CommonMark handle it when rendering.
    // No reconstruction, no processing - preserve what the user created.
    return trim($markdown);
  }

}
