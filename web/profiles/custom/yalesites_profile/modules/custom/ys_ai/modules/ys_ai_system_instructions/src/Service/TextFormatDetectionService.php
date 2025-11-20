<?php

namespace Drupal\ys_ai_system_instructions\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
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

    // Check if this looks like compressed/oneliner content.
    $is_compressed = $this->isCompressedMarkdown($text);

    if ($is_compressed) {
      // Use the regex-based approach for compressed content.
      $simple_formatted = $this->simpleMarkdownFormat($text);

      try {
        // Then try CommonMark parsing if the simple approach helps.
        $document = $this->parser->parse($simple_formatted);
        $formatted_text = $this->reconstructMarkdownFromAst($document);

        // Only use the AST result if it's significantly better.
        if (strlen($formatted_text) > strlen($simple_formatted) * 0.8) {
          return trim($formatted_text);
        }
      }
      catch (\Exception $e) {
        // If parsing fails, use the simple formatted version.
      }

      return trim($simple_formatted);
    }
    else {
      // For properly formatted content, just use CommonMark directly.
      return $this->formatUnescapedMarkdown($text);
    }
  }

  /**
   * Check if markdown appears to be compressed/oneliner format.
   *
   * @param string $text
   *   The text to check.
   *
   * @return bool
   *   TRUE if the text appears to be compressed markdown.
   */
  protected function isCompressedMarkdown(string $text): bool {
    // Look for signs of compressed markdown:
    // 1. Headers immediately following other content without line breaks.
    $has_jammed_headers = preg_match('/[a-zA-Z0-9.!?]\s*#{1,6}\s+/', $text);

    // 2. Headers with embedded bullet points
    $has_embedded_bullets = preg_match('/(#{1,6}\s+[^#\n]*?)(-\s+)/', $text);

    // 3. Very long lines with multiple markdown elements
    $lines = explode("\n", $text);
    $has_long_mixed_lines = FALSE;
    foreach ($lines as $line) {
      if (strlen($line) > 200 && preg_match('/#{1,6}.*-\s+/', $line)) {
        $has_long_mixed_lines = TRUE;
        break;
      }
    }

    return $has_jammed_headers || $has_embedded_bullets || $has_long_mixed_lines;
  }

  /**
   * Simple markdown formatting using regex patterns.
   *
   * @param string $text
   *   The text to format.
   *
   * @return string
   *   The formatted text.
   */
  protected function simpleMarkdownFormat(string $text): string {
    // Step 1: Fix headers that are jammed against previous text.
    $text = preg_replace('/([.!?])(\s*)#/', "$1\n\n#", $text);
    $text = preg_replace('/([a-zA-Z0-9])#/', "$1\n\n#", $text);

    // Step 2: Fix headers that have bullet points embedded in them
    // Pattern: "## Header- bullet content" → "## Header\n- bullet content".
    $text = preg_replace('/(#{1,6}\s+[^#\n]*?)(-\s+)/', "$1\n\n$2", $text);

    // Step 3: Fix headers that appear after bullet point content
    // Pattern: "- bullet content...## Header" → "- bullet...\n\n## Header".
    $text = preg_replace('/([.!?"\'])\s*(#{1,6}\s+)/', "$1\n\n$2", $text);

    // Step 4: Fix headers that run into the next header
    // Pattern: "...content## NextHeader" → "...content\n\n## NextHeader".
    $text = preg_replace('/([a-zA-Z0-9.!?])\s*(#{1,6}\s+)/', "$1\n\n$2", $text);

    // Step 5: Fix obvious list items that start new sections after punctuation.
    $text = preg_replace('/([.!?])\s*(-\s+[A-Z])/', "$1\n\n$2", $text);

    // Step 6: Clean up multiple spaces (but preserve intentional formatting)
    $text = preg_replace('/[ \t]+/', ' ', $text);

    // Step 7: Clean up excessive line breaks.
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return $text;
  }

  /**
   * Reconstruct markdown from CommonMark AST with proper formatting.
   *
   * @param \League\CommonMark\Node\Block\Document $document
   *   The parsed document.
   *
   * @return string
   *   The reconstructed markdown with proper spacing.
   */
  protected function reconstructMarkdownFromAst(Document $document): string {
    $output = '';
    $last_block_type = NULL;

    // Process top-level children of the document.
    foreach ($document->children() as $child) {
      if ($child instanceof Heading) {
        // Add spacing before headers (except at the very beginning)
        if ($output) {
          $output .= "\n\n";
        }

        // Add the header.
        $level = $child->getLevel();
        $header_text = $this->extractDirectTextFromNode($child);
        $output .= str_repeat('#', $level) . ' ' . $header_text . "\n";

        $last_block_type = 'heading';
      }
      elseif ($child instanceof ListBlock) {
        // Add spacing before lists.
        if ($output && substr($output, -1) !== "\n") {
          $output .= "\n";
        }
        if ($last_block_type !== 'list') {
          $output .= "\n";
        }

        $output .= $this->reconstructListBlock($child);
        $last_block_type = 'list';
      }
      elseif ($child instanceof Paragraph) {
        // Add spacing before paragraphs.
        if ($output) {
          $output .= "\n\n";
        }

        $paragraph_text = $this->extractDirectTextFromNode($child);
        $output .= $paragraph_text . "\n";

        $last_block_type = 'paragraph';
      }
    }

    // Clean up extra whitespace and normalize line breaks.
    $output = preg_replace('/[ \t]+/', ' ', $output);
    $output = preg_replace('/\n{3,}/', "\n\n", $output);

    return trim($output);
  }

  /**
   * Reconstruct a list block with proper formatting.
   *
   * @param \League\CommonMark\Node\Block\ListBlock $listBlock
   *   The list block to reconstruct.
   *
   * @return string
   *   The reconstructed list.
   */
  protected function reconstructListBlock(ListBlock $listBlock): string {
    $output = '';
    $listData = $listBlock->getListData();
    $counter = $listData->start ?? 1;

    foreach ($listBlock->children() as $listItem) {
      if ($listItem instanceof ListItem) {
        // Add list item marker.
        if ($listData->type === 'ordered') {
          $output .= $counter . '. ';
          $counter++;
        }
        else {
          $output .= '- ';
        }

        // Get list item content.
        $item_text = $this->extractDirectTextFromNode($listItem);
        $output .= $item_text . "\n";
      }
    }

    return $output;
  }

  /**
   * Extract direct text content from a node without traversing blocks.
   *
   * @param \League\CommonMark\Node\Node $node
   *   The node to extract text from.
   *
   * @return string
   *   The extracted text content.
   */
  protected function extractDirectTextFromNode(Node $node): string {
    $text = '';

    // Walk through the node but stop at block boundaries.
    $walker = $node->walker();

    while ($event = $walker->next()) {
      $current_node = $event->getNode();
      $is_entering = $event->isEntering();

      // Skip certain block-level children processed separately.
      if ($is_entering && $current_node !== $node) {
        if ($current_node instanceof Heading ||
            $current_node instanceof ListBlock ||
            $current_node instanceof ListItem) {
          // Skip nested block elements (but allow paragraphs for content)
          $walker->resumeAt($current_node, FALSE);
          continue;
        }
      }

      if ($current_node instanceof Text) {
        $content = $current_node->getLiteral();
        $text .= $content;
      }
    }

    return trim($text);
  }

  /**
   * Extract text content from a CommonMark node (legacy method).
   *
   * @param \League\CommonMark\Node\Node $node
   *   The node to extract text from.
   *
   * @return string
   *   The extracted text content.
   */
  protected function extractTextFromNode(Node $node): string {
    return $this->extractDirectTextFromNode($node);
  }

  /**
   * Fallback markdown formatting using regex (when CommonMark parsing fails).
   *
   * @param string $text
   *   The markdown text to format.
   *
   * @return string
   *   The formatted markdown text.
   */
  protected function formatMarkdownTextFallback(string $text): string {
    $text = trim($text);

    // Use the preprocessing step to fix oneliner issues.
    $text = $this->preprocessOnelineMarkdown($text);

    // Clean up excessive whitespace but preserve intentional formatting.
    $text = preg_replace('/[ \t]+/', ' ', $text);

    // Clean up excessive line breaks (more than 2)
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
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
    $markdown = trim($markdown);

    if (empty($markdown)) {
      return '';
    }

    // Just use CommonMark to parse and validate the structure.
    try {
      $document = $this->parser->parse($markdown);
      $formatted_text = $this->reconstructMarkdownFromAST($document);

      if (!empty($formatted_text)) {
        return trim($formatted_text);
      }
    }
    catch (\Exception $e) {
      // If parsing fails, return the original with basic cleanup.
    }

    // Fallback: basic cleanup without aggressive processing.
    $markdown = preg_replace('/[ \t]+/', ' ', $markdown);
    $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

    return trim($markdown);
  }

}
