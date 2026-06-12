<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Assembles the system prompt for the Beacon chat assistant.
 *
 * Combines the per-site system instructions (managed through the
 * ys_ai_system_instructions module when available) with the retrieved,
 * numbered sources. Source markers follow the [docN] convention the chat
 * frontend turns into citation superscripts.
 */
class SystemPromptBuilder {

  /**
   * Constructs the prompt builder.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param object|null $instructionsStorage
   *   The ys_ai_system_instructions storage service when that module is
   *   installed, NULL otherwise (optional service reference). Typed loosely
   *   so this module never hard-depends on the class.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ?object $instructionsStorage = NULL,
  ) {
  }

  /**
   * Builds the system prompt.
   *
   * @param array[] $citations
   *   Citations from the retriever, in [docN] order.
   *
   * @return string
   *   The complete system prompt.
   */
  public function build(array $citations): string {
    $prompt = $this->getSystemInstructions();

    if ($citations) {
      $prompt .= "\n\nAnswer using only the numbered sources below. Cite every fact with its source marker, for example [doc1]. Combine markers when multiple sources support a fact, for example [doc1][doc3].";
      $prompt .= "\n\nSources:";
      foreach (array_values($citations) as $index => $citation) {
        $number = $index + 1;
        $title = trim((string) ($citation['title'] ?? ''));
        $prompt .= "\n\n[doc{$number}] {$title}\n" . $citation['content'];
      }
    }
    else {
      $prompt .= "\n\nNo sources were found for this question. Tell the user you could not find relevant information on this site and suggest rephrasing the question.";
    }

    return $prompt;
  }

  /**
   * Gets the per-site system instructions.
   *
   * Reads the active instructions directly from the
   * ys_ai_system_instructions storage service. The storage service is used
   * instead of the manager service because the manager synchronizes from the
   * legacy remote API on every read.
   *
   * @return string
   *   The system instructions, falling back to the configured default.
   */
  protected function getSystemInstructions(): string {
    $instructions = '';
    if ($this->instructionsStorage) {
      try {
        $active = $this->instructionsStorage->getActiveInstructions();
        $instructions = trim((string) ($active['instructions'] ?? ''));
      }
      catch (\Throwable $e) {
        // Table missing or module mid-install: use the fallback below.
      }
    }
    if ($instructions === '') {
      $instructions = (string) $this->configFactory
        ->get('ys_beacon.settings')
        ->get('fallback_system_prompt');
    }
    return $instructions;
  }

}
