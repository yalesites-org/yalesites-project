<?php

namespace Drupal\ys_beacon\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Assembles the system prompt for the Beacon chat assistant.
 *
 * Combines the per-site system instructions with the retrieved, numbered
 * sources. Source markers follow the [docN] convention the chat frontend
 * turns into citation superscripts.
 */
class SystemPromptBuilder {

  /**
   * Constructs the prompt builder.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\ys_beacon\Service\SystemInstructionsStorage $instructionsStorage
   *   The system instructions storage.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected SystemInstructionsStorage $instructionsStorage,
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
   * @return string
   *   The active system instructions, falling back to the configured
   *   default when no version has been saved yet.
   */
  protected function getSystemInstructions(): string {
    $instructions = '';
    try {
      $active = $this->instructionsStorage->getActiveInstructions();
      $instructions = trim((string) ($active['instructions'] ?? ''));
    }
    catch (\Throwable $e) {
      // Table missing mid-install: use the fallback below.
    }
    if ($instructions === '') {
      $instructions = (string) $this->configFactory
        ->get('ys_beacon.settings')
        ->get('fallback_system_prompt');
    }
    return $instructions;
  }

}
