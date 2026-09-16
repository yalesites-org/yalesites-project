<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use League\CommonMark\CommonMarkConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Converts between Markdown and the restricted-HTML used by the WYSIWYG editor.
 *
 * The Beacon system instructions are authored in a CKEditor "text_format"
 * element bound to the "restricted_html" format, but the canonical stored and
 * consumed form is Markdown. This service is the single seam between the two:
 * Markdown -> HTML when loading the editor, HTML -> Markdown when saving.
 *
 * The HTML side is always constrained to the tags "restricted_html" allows
 * (<a> <br> <p> <strong> <em> <sub> <sup>): no headings, lists, or tables. Any
 * richer Markdown (e.g. the seeded default instructions, which use headings and
 * lists) is downgraded on load so all text survives — headings become bold
 * paragraphs and list items become paragraphs — rather than being silently
 * dropped or run together.
 */
class MarkdownConverter {

  /**
   * Tags permitted by the restricted_html text format.
   *
   * Kept in sync by hand with the filter_html allowed_html setting in
   * config/sync/filter.format.restricted_html.yml — that config is the source
   * of truth for what an editor may author.
   */
  const ALLOWED_TAGS = ['a', 'br', 'p', 'strong', 'em', 'sub', 'sup'];

  /**
   * Renders Markdown to HTML.
   *
   * @var \League\CommonMark\CommonMarkConverter
   */
  protected CommonMarkConverter $markdownToHtml;

  /**
   * Renders HTML to Markdown.
   *
   * @var \League\HTMLToMarkdown\HtmlConverter
   */
  protected HtmlConverter $htmlToMarkdown;

  /**
   * Constructs a MarkdownConverter.
   */
  public function __construct() {
    // html_input=allow lets subscript/superscript survive the round-trip as
    // inline HTML (Markdown has no equivalent); the constrainToRestrictedHtml()
    // filter below is the security boundary, so allowing raw HTML here is safe.
    $this->markdownToHtml = new CommonMarkConverter([
      'html_input' => 'allow',
      'allow_unsafe_links' => FALSE,
    ]);
    // hard_break turns <br> into "\n" rather than the two-trailing-spaces form,
    // keeping the stored Markdown clean.
    $this->htmlToMarkdown = new HtmlConverter([
      'hard_break' => TRUE,
      'remove_nodes' => 'script style',
    ]);
  }

  /**
   * Converts stored Markdown to restricted HTML for the WYSIWYG editor.
   *
   * @param string $markdown
   *   The Markdown to render.
   *
   * @return string
   *   HTML limited to the restricted_html tag set.
   */
  public function toHtml(string $markdown): string {
    $markdown = trim($markdown);
    if ($markdown === '') {
      return '';
    }

    $html = $this->markdownToHtml->convert($markdown)->getContent();
    return $this->constrainToRestrictedHtml($html);
  }

  /**
   * Converts editor HTML back to Markdown for storage.
   *
   * @param string $html
   *   The HTML produced by the WYSIWYG editor.
   *
   * @return string
   *   The equivalent Markdown, trimmed and line-ending normalized.
   */
  public function toMarkdown(string $html): string {
    // Enforce the allowed tag set server-side: CKEditor already restricts the
    // input, but a crafted POST could bypass it, so never trust the markup.
    $html = Xss::filter($html, self::ALLOWED_TAGS);

    if (trim(strip_tags($html)) === '') {
      return '';
    }

    $markdown = $this->htmlToMarkdown->convert($html);
    $markdown = $this->decodeEntities($markdown);
    return trim(str_replace(["\r\n", "\r"], "\n", $markdown));
  }

  /**
   * Decodes HTML entities left encoded by the HTML-to-Markdown conversion.
   *
   * The html-to-markdown library keeps entities encoded (e.g. "&amp;",
   * "&quot;"), which would otherwise corrupt stored Markdown ("&amp;" text).
   * "&lt;" and "&gt;" are deliberately preserved: decoding a literal "<x>" in
   * the text would cause it to be re-parsed as HTML — and stripped — the next
   * time the Markdown is loaded into the editor.
   *
   * @param string $markdown
   *   The Markdown that may contain encoded entities.
   *
   * @return string
   *   The Markdown with entities decoded, angle brackets left encoded.
   */
  protected function decodeEntities(string $markdown): string {
    $guarded = strtr($markdown, ['&lt;' => "\x01", '&gt;' => "\x02"]);
    $decoded = html_entity_decode($guarded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return strtr($decoded, ["\x01" => '&lt;', "\x02" => '&gt;']);
  }

  /**
   * Constrains HTML to the restricted_html tag set without losing text.
   *
   * @param string $html
   *   Arbitrary HTML (e.g. CommonMark output containing headings and lists).
   *
   * @return string
   *   HTML limited to the allowed tags, with headings downgraded to bold
   *   paragraphs and list items flattened to paragraphs.
   */
  protected function constrainToRestrictedHtml(string $html): string {
    if (trim($html) === '') {
      return '';
    }

    $document = Html::load($html);
    $this->downgradeHeadings($document);
    $this->flattenLists($document);
    $html = Html::serialize($document);

    // Strip anything still outside the allowed set (blockquote, pre, img,
    // tables, …), keeping the text content so nothing is lost.
    return trim(Xss::filter($html, self::ALLOWED_TAGS));
  }

  /**
   * Rewrites h1-h6 elements as bold paragraphs, preserving inline content.
   *
   * @param \DOMDocument $document
   *   The document to mutate in place.
   */
  protected function downgradeHeadings(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    foreach (iterator_to_array($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6')) as $heading) {
      $paragraph = $document->createElement('p');
      $strong = $document->createElement('strong');
      while ($heading->firstChild) {
        $strong->appendChild($heading->firstChild);
      }
      $paragraph->appendChild($strong);
      $heading->parentNode->replaceChild($paragraph, $heading);
    }
  }

  /**
   * Flattens ul/ol lists into one paragraph per list item.
   *
   * Leaf lists (those with no nested list) are processed first so nested lists
   * are unwrapped inside-out and all item text is preserved.
   *
   * @param \DOMDocument $document
   *   The document to mutate in place.
   */
  protected function flattenLists(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    while (($lists = $xpath->query('//ul|//ol')) && $lists->length > 0) {
      $leaf = NULL;
      foreach ($lists as $candidate) {
        if ($xpath->query('.//ul|.//ol', $candidate)->length === 0) {
          $leaf = $candidate;
          break;
        }
      }
      // Guard against an unexpected cycle (should never trigger).
      if ($leaf === NULL) {
        break;
      }

      foreach (iterator_to_array($leaf->childNodes) as $item) {
        if ($item instanceof \DOMElement && $item->nodeName === 'li') {
          $paragraph = $document->createElement('p');
          while ($item->firstChild) {
            $paragraph->appendChild($item->firstChild);
          }
          $leaf->parentNode->insertBefore($paragraph, $leaf);
        }
      }
      $leaf->parentNode->removeChild($leaf);
    }
  }

}
