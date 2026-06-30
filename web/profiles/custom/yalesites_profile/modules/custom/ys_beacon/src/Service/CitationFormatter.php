<?php

namespace Drupal\ys_beacon\Service;

/**
 * Normalizes retrieved sources into a citation list for display.
 *
 * Single, server-side home for the citation-marker logic that previously lived
 * only in the React chat app: it reads the [docN] markers the model emitted,
 * de-duplicates sources by URL, flags which were actually cited, and renumbers
 * them for display. The chat and the AI tester both build on this so the two
 * cannot drift.
 */
class CitationFormatter {

  /**
   * Length, in characters, of the per-citation content excerpt.
   */
  protected const EXCERPT_LENGTH = 300;

  /**
   * Normalizes retrieved citations against the model's answer.
   *
   * @param string $answer
   *   The model's answer text, carrying [docN] markers.
   * @param array[] $citations
   *   The retrieved citations in [docN] order (the order RagRetriever returned
   *   them and SystemPromptBuilder numbered them), each with at least title,
   *   url, and content keys.
   *
   * @return array[]
   *   The de-duplicated citations, in retrieval order, each with: number
   *   (1-based display position), title, url, content, excerpt, and cited (TRUE
   *   when the model referenced this source).
   */
  public function format(string $answer, array $citations): array {
    $cited_numbers = $this->citedMarkers($answer);

    $by_url = [];
    foreach (array_values($citations) as $index => $citation) {
      // SystemPromptBuilder labels the Nth source [docN] (1-based).
      $cited = isset($cited_numbers[$index + 1]);
      $url = $citation['url'] ?? NULL;
      // De-duplicate by URL; sources without one stay distinct.
      $key = ($url !== NULL && $url !== '') ? 'url:' . $url : 'pos:' . $index;

      if (isset($by_url[$key])) {
        // A source cited under any of its duplicate markers counts as cited.
        $by_url[$key]['cited'] = $by_url[$key]['cited'] || $cited;
        continue;
      }

      $content = trim((string) ($citation['content'] ?? ''));
      $by_url[$key] = [
        'title' => trim((string) ($citation['title'] ?? '')),
        'url' => $url,
        'content' => $content,
        'excerpt' => mb_substr($content, 0, self::EXCERPT_LENGTH),
        'cited' => $cited,
      ];
    }

    $normalized = [];
    $number = 1;
    foreach ($by_url as $entry) {
      $entry['number'] = $number++;
      $normalized[] = $entry;
    }
    return $normalized;
  }

  /**
   * Extracts the set of cited [docN] marker numbers from an answer.
   *
   * @param string $answer
   *   The answer text.
   *
   * @return array
   *   A set (keys are the cited 1-based numbers) for O(1) lookup.
   */
  protected function citedMarkers(string $answer): array {
    preg_match_all('/\[doc(\d+)\]/', $answer, $matches);
    return array_flip(array_map('intval', $matches[1]));
  }

}
