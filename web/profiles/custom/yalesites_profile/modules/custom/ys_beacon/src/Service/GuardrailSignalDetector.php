<?php

namespace Drupal\ys_beacon\Service;

/**
 * Classifies a chat turn into the guardrail signals Beacon counts.
 *
 * Pure text classification: no dependencies, no storage, and no state. It
 * answers "does this opening look like a refusal" and "does this question look
 * like an injection attempt", returning a boolean or a pattern NAME - never the
 * matched text. Keeping it separate from GuardrailTelemetry means the component
 * that reads question and answer text has no way to persist any of it, which is
 * the design half of the privacy constraint in
 * yalesites-org/YaleSites-Internal#1469.
 *
 * Both signals are heuristics. They exist to make a trend measurable (is a
 * jailbreak campaign underway, are refusals climbing after a prompt change),
 * not to gate a response - the guardrails and the platform prompt do that. The
 * pattern lists below are the single place to tune them.
 */
class GuardrailSignalDetector {

  /**
   * How many characters of the answer are inspected for a refusal.
   *
   * A model that declines does so at the start of its answer, so only the
   * opening is examined. This keeps the check cheap and keeps false positives
   * down - a long, helpful answer can easily contain "I cannot" in passing. It
   * also bounds the copy of the answer the caller has to keep in order to
   * classify it, though that is not a privacy boundary on its own: the AI
   * module's streamed iterator already accumulates the whole answer for the
   * duration of the request.
   */
  public const REFUSAL_SAMPLE_LENGTH = 400;

  /**
   * Curly apostrophe variants folded to a straight quote before matching.
   *
   * Models emit typographic apostrophes as often as straight ones ("I can’t").
   * Folding them once up front keeps the patterns below readable and avoids
   * matching a multibyte character inside a character class, which would need
   * the /u modifier and would otherwise silently compare byte by byte.
   */
  protected const APOSTROPHES = ['’', '‘', '＇', '´'];

  /**
   * Patterns that indicate the model declined the request.
   *
   * Deliberately conservative and anchored on a first-person subject, so
   * ordinary content ("You can register online") does not read as a refusal.
   * Matched against apostrophe-normalized text, so only the straight form
   * needs to appear here.
   */
  protected const REFUSAL_PATTERNS = [
    '/\bI\s+(?:can\'?t|cannot|can\s+not)\b/i',
    '/\bI\s*(?:\'m|\s+am)\s+(?:not\s+able|unable)\s+to\b/i',
    '/\bI\s*(?:\'m|\s+am)\s+sorry,?\s+(?:but\s+)?I\b/i',
    '/\bI\s+(?:don\'?t|do\s+not)\s+have\s+(?:access|the\s+ability|permission)\b/i',
    '/\b(?:that\'?s|that\s+is)\s+(?:outside|beyond)\s+(?:the\s+scope|what\s+I\s+can)\b/i',
  ];

  /**
   * Injection-attempt patterns, keyed by the name recorded in telemetry.
   *
   * The categories mirror what SystemPromptBuilder::PLATFORM_GUARDRAIL already
   * declares off limits - overriding the platform rules, extracting the system
   * prompt, and role-play escapes - so the counters measure attempts against
   * the rules Beacon actually states rather than an unrelated list.
   *
   * Every repetition is explicitly bounded ({0,4} rather than *). This endpoint
   * is public and unauthenticated, so an unbounded quantifier over an
   * alternation would be a denial-of-service surface: a crafted question could
   * force catastrophic backtracking. Bounded repetition keeps matching linear.
   *
   * Order is significant: the first match wins, so a turn is counted once.
   */
  protected const INJECTION_PATTERNS = [
    'ignore_instructions' => '/\bignore\s+(?:all\s+|any\s+|your\s+|the\s+|these\s+){0,4}(?:previous|prior|above|preceding|earlier|foregoing|system)\s+(?:instructions?|rules?|prompts?|directives?)\b/i',
    'reveal_prompt' => '/\b(?:show|reveal|repeat|print|output|display|tell\s+me|what\s+(?:are|were))\b[^.?!]{0,40}\b(?:system\s+prompt|initial\s+prompt|your\s+instructions|these\s+rules|platform\s+rules)\b/i',
    'roleplay_override' => '/\b(?:pretend\s+(?:to\s+be|you)|act\s+as\s+(?:if|a|an|though)|role-?play\s+as|you\s+are\s+now|from\s+now\s+on\s+you\s+(?:are|will))\b/i',
    'jailbreak' => '/\b(?:jailbreak(?:ing|s)?|dan\s+mode|developer\s+mode|do\s+anything\s+now|unfiltered\s+mode)\b/i',
    'override_rules' => '/\b(?:disregard|forget|bypass|override)\s+(?:all\s+|any\s+|your\s+|the\s+|these\s+){0,4}(?:previous\s+|prior\s+|above\s+){0,2}(?:instructions?|rules?|prompts?|guardrails?|restrictions?)\b/i',
  ];

  /**
   * Determines whether an answer's opening reads as a refusal.
   *
   * @param string $answer
   *   The answer text, or just its opening. Only the first
   *   self::REFUSAL_SAMPLE_LENGTH characters are inspected.
   *
   * @return bool
   *   TRUE if the answer appears to decline the request.
   */
  public function isRefusal(string $answer): bool {
    $opening = mb_substr($answer, 0, self::REFUSAL_SAMPLE_LENGTH);
    if (trim($opening) === '') {
      return FALSE;
    }
    $opening = str_replace(self::APOSTROPHES, "'", $opening);

    foreach (self::REFUSAL_PATTERNS as $pattern) {
      if (preg_match($pattern, $opening) === 1) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Names the injection pattern a question matches, if any.
   *
   * @param string $question
   *   The user's question.
   *
   * @return string|null
   *   The name of the first matching pattern, or NULL if none matched. Only
   *   ever a fixed name from self::INJECTION_PATTERNS - never any part of the
   *   question itself.
   */
  public function injectionPattern(string $question): ?string {
    if (trim($question) === '') {
      return NULL;
    }

    foreach (self::INJECTION_PATTERNS as $name => $pattern) {
      if (preg_match($pattern, $question) === 1) {
        return $name;
      }
    }

    return NULL;
  }

}
